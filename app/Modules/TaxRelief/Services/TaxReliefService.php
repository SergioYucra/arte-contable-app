<?php

namespace App\Modules\TaxRelief\Services;

use App\Modules\TaxRelief\Exceptions\UpstreamUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

/**
 * Traduce entre nuestro formulario y la API de boliviaimpuestos.com.
 *
 * - Lo que enviamos se arma aquí campo por campo (lista blanca): nunca se
 *   reenvía la petición del navegador tal cual.
 * - Lo que recibimos se valida y se copia a NUESTRO formato. Si ellos cambian
 *   un nombre de campo, solo se ajusta este archivo y la pantalla no se entera.
 * - Resultados iguales se sirven desde el caché hasta fin del día en Bolivia,
 *   para no repetir llamadas al servicio externo.
 */
class TaxReliefService
{
    public const TREATMENTS = ['regularizable', 'condonado', 'normal'];

    public function __construct(private readonly BoliviaImpuestosClient $client) {}

    /**
     * Plan completo: al contado vs. en cuotas, con cronograma.
     *
     * @param  array{fecha_solicitud: string, garantia: string, cuotas: int, deudas: list<array{impuesto: string, periodo: string, fecha_vencimiento: string, tributo_omitido: float|int|string}>}  $input
     * @param  bool  $fresh  true = ignora el caché (lo usa el monitor diario)
     * @return array<string, mixed>
     */
    public function plan(array $input, bool $fresh = false): array
    {
        $payload = [
            'fechaSolicitud' => $input['fecha_solicitud'],
            'mesPrimera' => (int) config('tax_relief.first_installment_month_offset'),
            'tipoGarantia' => $input['garantia'],
            'modo' => 'regularizacion',
            'deudas' => array_map(fn (array $d): array => [
                'impuesto' => $d['impuesto'],
                'periodo' => $d['periodo'],
                'fechaVenc' => $d['fecha_vencimiento'],
                'tributoOmitido' => round((float) $d['tributo_omitido'], 2),
                'cuotas' => (int) $input['cuotas'],
            ], array_values($input['deudas'])),
        ];

        $compute = function () use ($payload): array {
            $raw = $this->client->post(BoliviaImpuestosClient::PLAN, $payload);

            return $this->mapped(BoliviaImpuestosClient::PLAN, fn () => $this->mapPlan($raw, count($payload['deudas'])));
        };

        return $fresh ? $compute() : $this->remember('plan', $payload, $compute);
    }

    /**
     * Desglose de una deuda: mantenimiento de valor, interés por tramos y sanción opcional.
     *
     * @param  array{fecha_solicitud: string, impuesto: string, periodo: string, fecha_vencimiento: string, tributo_omitido: float|int|string, con_sancion?: bool}  $input
     * @return array<string, mixed>
     */
    public function breakdown(array $input): array
    {
        $payload = [
            'fechaVenc' => $input['fecha_vencimiento'],
            'fechaPago' => $input['fecha_solicitud'],
            'tributoOmitido' => round((float) $input['tributo_omitido'], 2),
            'impuesto' => $input['impuesto'],
            'periodo' => $input['periodo'],
            'sancionOmision' => (bool) ($input['con_sancion'] ?? false),
            'precisionFactor' => 'completa',
        ];

        return $this->remember('breakdown', $payload, function () use ($payload): array {
            $raw = $this->client->post(BoliviaImpuestosClient::BREAKDOWN, $payload);

            return $this->mapped(BoliviaImpuestosClient::BREAKDOWN, fn () => $this->mapBreakdown($raw));
        });
    }

    /** Fecha de vencimiento sugerida según impuesto, periodo y último dígito del NIT. */
    public function dueDate(string $tax, string $period, int $nitLastDigit): ?string
    {
        $query = ['periodo' => $period, 'impuesto' => $tax, 'nit' => $nitLastDigit];
        $key = 'tax-relief:due:'.sha1(json_encode($query));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['date'];
        }

        $raw = $this->client->get(BoliviaImpuestosClient::DUE_DATE, $query);
        $date = $this->mapped(BoliviaImpuestosClient::DUE_DATE, function () use ($raw): ?string {
            if (! array_key_exists('fecha', $raw)) {
                throw new UnexpectedValueException('falta el campo fecha');
            }

            return $raw['fecha'] === null ? null : $this->date($raw, 'fecha');
        });

        Cache::put($key, ['date' => $date], (int) config('tax_relief.cache.due_date_seconds'));

        return $date;
    }

    /** Texto externo seguro para mostrar: sin HTML, sin espacios raros y con largo máximo. */
    public static function cleanText(string $text, int $max = 300): string
    {
        $plain = preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '';

        return mb_substr(trim($plain), 0, $max);
    }

    // ------------------------------------------------------------------
    // Caché
    // ------------------------------------------------------------------

    /** @param  callable(): array<string, mixed>  $compute */
    private function remember(string $kind, array $payload, callable $compute): array
    {
        $now = Carbon::now((string) config('tax_relief.timezone'));
        $key = "tax-relief:{$kind}:".sha1(json_encode($payload)).':'.$now->toDateString();

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $compute();

        $untilEndOfDay = max(60, (int) $now->diffInSeconds($now->copy()->endOfDay()));
        Cache::put($key, $result, min((int) config('tax_relief.cache.plan_max_seconds'), $untilEndOfDay));

        return $result;
    }

    /**
     * Ejecuta un mapeo: si el formato no cuadra, avisa al cortacircuito y
     * responde "no disponible" sin mostrar nada a medias.
     *
     * @template T
     *
     * @param  callable(): T  $map
     * @return T
     */
    private function mapped(string $endpoint, callable $map): mixed
    {
        try {
            return $map();
        } catch (UnexpectedValueException $e) {
            $this->client->reportFormatProblem($endpoint, $e->getMessage());

            throw new UpstreamUnavailable(UpstreamUnavailable::FORMAT, 'Formato externo cambió: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Mapeo de respuestas (su formato → el nuestro)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function mapPlan(array $r, int $debtsSent): array
    {
        $debtsRaw = $this->list($r, 'deudas', $debtsSent);
        $scheduleRaw = $this->list($r, 'cronograma', 60);
        $lawDate = $this->date($r, 'pubLey');

        $debts = array_map(function (mixed $d) use ($lawDate): array {
            $d = $this->assoc($d, 'deuda');
            $treatment = $this->str($d, 'tratamiento', 20);
            if (! in_array($treatment, self::TREATMENTS, true)) {
                throw new UnexpectedValueException("tratamiento desconocido: {$treatment}");
            }
            $due = $this->date($d, 'venc');

            return [
                'tax' => $this->str($d, 'impuesto', 20),
                'period' => $this->str($d, 'periodo', 10),
                'due_date' => $due,
                'treatment' => $treatment,
                'omitted_tax' => $this->money($d, 'tributoOmitido'),
                'value_adjustment' => $this->money($d, 'mv'),
                'interest' => $this->money($d, 'interes'),
                'total_without_relief' => $this->money($d, 'totalNormal'),
                'plan_base' => $this->money($d, 'total'),
                'cash' => $this->money($d, 'contado'),
                'down_payment' => $this->money($d, 'pagoInicial'),
                'guarantee' => $this->money($d, 'garantia'),
                'balance' => $this->money($d, 'saldo'),
                'installments' => $this->int($d, 'cuotas'),
                // Su API acepta deudas que vencieron después de la ley (ver docs, hallazgo 2).
                'due_after_law' => $due > $lawDate,
            ];
        }, $debtsRaw);

        $eligible = $this->int($r, 'nRegularizables');

        $schedule = $eligible === 0 ? [] : array_map(function (mixed $c): array {
            $c = $this->assoc($c, 'cuota');

            return [
                'number' => $this->int($c, 'n'),
                'due_date' => $this->date($c, 'fecha'),
                'ufv' => $this->num($c, 'ufv'),
                'projected' => $this->bool($c, 'proyectada'),
                'overdue' => $this->bool($c, 'vencida'),
                'total' => $this->money($c, 'totalCuota'),
                'capital' => $this->money($c, 'compTO'),
                'value_adjustment' => $this->money($c, 'compMV'),
                'interest' => $this->money($c, 'compInt'),
            ];
        }, $scheduleRaw);

        $notices = $eligible === 0 ? [] : array_values(array_filter(array_map(
            fn (mixed $n) => is_string($n) ? self::cleanText($n) : '',
            array_slice(is_array($r['avisos'] ?? null) ? $r['avisos'] : [], 0, 10)
        )));

        $totalCash = $this->money($r, 'totalContado');
        $downPayment = $this->money($r, 'pagoInicial');
        $guarantee = $this->money($r, 'garantia');
        $scheduleTotal = round(array_sum(array_column($schedule, 'total')), 2);
        $finalTotal = round($downPayment + $guarantee + $scheduleTotal, 2);
        $eligibleTax = array_sum(array_map(
            fn (array $d) => $d['treatment'] === 'condonado' ? 0 : $d['omitted_tax'],
            $debts
        ));
        $planBase = $this->money($r, 'totalConsolidado');

        return [
            'request_date' => $this->date($r, 'fechaSolicitud'),
            'law_published_on' => $lawDate,
            'ufv_request' => $this->num($r, 'ufvSolicitud'),
            'ufv_last_published_on' => $this->date($r, 'ufvUltimaFecha'),
            'inflation_estimate_pct' => $this->num($r, 'inflacionAnualEstim'),
            'min_installment_bs' => $this->money($r, 'cuotaMinimaBs'),
            'installments' => $this->int($r, 'maxCuotas'),
            'max_installments_allowed' => $this->int($r, 'maxCuotasPermitidas'),
            'installments_cap' => $this->int($r, 'topeCuotas'),
            'total_without_relief' => $this->money($r, 'totalNormal'),
            'total_cash' => $totalCash,
            'savings_cash' => round($this->money($r, 'totalNormal') - $totalCash, 2),
            'plan_base' => $planBase,
            'frozen_value_adjustment' => round(max(0, $planBase - $eligibleTax), 2),
            'total_forgiven' => $this->money($r, 'totalCondonado'),
            'forgiven_count' => $this->int($r, 'nCondonadas'),
            'eligible_count' => $eligible,
            'down_payment' => $downPayment,
            'down_payment_pct' => $this->num($r, 'porcentajePagoInicial'),
            'guarantee' => $guarantee,
            'guarantee_pct' => $this->num($r, 'porcentajeGarantia'),
            'balance' => $this->money($r, 'saldoTotal'),
            'schedule_total' => $scheduleTotal,
            'plan_final_total' => $finalTotal,
            'average_installment' => $schedule === [] ? 0.0 : round($scheduleTotal / count($schedule), 2),
            'extra_vs_cash' => $eligible === 0 ? 0.0 : round($finalTotal - $totalCash, 2),
            'extra_vs_cash_pct' => $totalCash > 0 ? round(($finalTotal - $totalCash) / $totalCash * 100, 1) : 0.0,
            'notices' => $notices,
            'debts' => $debts,
            'schedule' => $schedule,
        ];
    }

    /** @return array<string, mixed> */
    private function mapBreakdown(array $r): array
    {
        $ufvPayment = $this->num($r, 'ufvPago');
        $tranches = array_map(function (mixed $t) use ($ufvPayment): array {
            $t = $this->assoc($t, 'tramo');
            $interestUfv = $this->num($t, 'interesUFV');

            return [
                'rate_pct' => $this->num($t, 'pct'),
                'days' => $this->int($t, 'dias'),
                'from' => $this->date($t, 'desde'),
                'to' => $this->date($t, 'hasta'),
                'interest_ufv' => round($interestUfv, 2),
                'interest' => round($interestUfv * $ufvPayment, 2),
            ];
        }, $this->list($r, 'tramos', 10));

        return [
            'due_date' => $this->date($r, 'fechaVenc'),
            'payment_date' => $this->date($r, 'fechaPago'),
            'omitted_tax' => $this->money($r, 'tributoOmitido'),
            'ufv_due' => $this->num($r, 'ufvVenc'),
            'ufv_payment' => $ufvPayment,
            'tax_in_ufv' => round($this->num($r, 'cUFV'), 2),
            'updated_tax' => $this->money($r, 'toActualizadoBs'),
            'value_adjustment' => $this->money($r, 'mvBs'),
            'interest' => $this->money($r, 'interesBs'),
            'total' => $this->money($r, 'deudaBs'),
            'penalty' => $this->money($r, 'sancionBs'),
            'days_late' => $this->int($r, 'diasMora'),
            'tranches' => $tranches,
        ];
    }

    // ------------------------------------------------------------------
    // Lectores estrictos: si algo no está o no tiene el tipo esperado,
    // se considera que el formato cambió.
    // ------------------------------------------------------------------

    private function assoc(mixed $value, string $what): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException("{$what} no es un objeto");
        }

        return $value;
    }

    private function list(array $r, string $key, int $max): array
    {
        $value = $r[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("{$key} no es una lista");
        }
        if (count($value) > $max) {
            throw new UnexpectedValueException("{$key} trae demasiados elementos");
        }

        return $value;
    }

    private function num(array $r, string $key): float
    {
        $value = $r[$key] ?? null;
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new UnexpectedValueException("{$key} no es un número");
        }

        return (float) $value;
    }

    private function money(array $r, string $key): float
    {
        return round($this->num($r, $key), 2);
    }

    private function int(array $r, string $key): int
    {
        $value = $this->num($r, $key);
        if ((float) (int) $value !== $value) {
            throw new UnexpectedValueException("{$key} no es entero");
        }

        return (int) $value;
    }

    private function bool(array $r, string $key): bool
    {
        if (! is_bool($r[$key] ?? null)) {
            throw new UnexpectedValueException("{$key} no es verdadero/falso");
        }

        return $r[$key];
    }

    private function str(array $r, string $key, int $max): string
    {
        if (! is_string($r[$key] ?? null)) {
            throw new UnexpectedValueException("{$key} no es texto");
        }

        return self::cleanText($r[$key], $max);
    }

    private function date(array $r, string $key): string
    {
        $value = $r[$key] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new UnexpectedValueException("{$key} no es una fecha");
        }

        return $value;
    }
}
