<?php

namespace App\Modules\TaxRelief\Console;

use App\Modules\TaxRelief\Exceptions\UpstreamUnavailable;
use App\Modules\TaxRelief\Services\TaxReliefService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Revisión diaria de la calculadora (programada en TaxReliefServiceProvider).
 *
 * Prueba los 3 servicios de boliviaimpuestos.com que usa la pantalla con un caso
 * de referencia de fecha fija ya pasada: sus resultados dependen solo de UFV
 * históricas, así que no deberían cambiar nunca.
 *  - Si alguno falla o cambió de formato → correo "dejó de funcionar" (sale con error).
 *  - Si responden bien pero con otros valores → correo "cambió el cálculo"
 *    (no es un error: ellos ajustaron su lógica y la nuestra la sigue sola).
 * El correo va al desarrollador líder (TAX_RELIEF_ALERT_EMAIL).
 */
class CheckUpstreamCommand extends Command
{
    protected $signature = 'tax-relief:check
        {--no-mail : Solo muestra el resultado, sin enviar correo}
        {--test-mail : Envía un correo de prueba a TAX_RELIEF_ALERT_EMAIL (para comprobar el SMTP) y termina}';

    protected $description = 'Prueba la calculadora de alivio tributario contra boliviaimpuestos.com y avisa al desarrollador líder si falla o cambia';

    /** Caso de referencia: IVA 2019-01, Bs 10.000, vence 15/02/2019, solicitud 15/11/2026 (ver docs/calculadora-alivio-tributario). */
    private const DEBT = ['impuesto' => 'IVA', 'periodo' => '2019-01', 'fecha_vencimiento' => '2019-02-15', 'tributo_omitido' => 10000];

    private const REQUEST_DATE = '2026-11-15';

    public function handle(TaxReliefService $service): int
    {
        if ($this->option('test-mail')) {
            return $this->sendTestMail();
        }

        if (! config('tax_relief.enabled')) {
            $this->info('La calculadora está desactivada (TAX_RELIEF_ENABLED=false): no se revisa.');

            return self::SUCCESS;
        }

        $checks = [
            'Plan al contado y en cuotas (fap.php)' => fn () => $this->checkPlan($service),
            'Desglose "¿De dónde sale?" (deudas.php)' => fn () => $this->checkBreakdown($service),
            'Fecha de vencimiento sugerida (deudas.php)' => fn () => $this->checkDueDate($service),
        ];

        $broken = [];
        $changed = [];
        foreach ($checks as $name => $run) {
            try {
                $differences = $run();
            } catch (UpstreamUnavailable $e) {
                if ($e->reason === UpstreamUnavailable::BUSY) {
                    $this->warn('Tope global de llamadas alcanzado (mucho uso en este momento): se revisará en la próxima corrida.');

                    return self::SUCCESS;
                }
                $broken[$name] = $this->describe($e);
                $this->error("✗ {$name}: {$broken[$name]}");

                continue;
            } catch (Throwable $e) {
                $broken[$name] = 'error inesperado ('.$e::class.')';
                $this->error("✗ {$name}: {$broken[$name]}");

                continue;
            }

            if ($differences === []) {
                $this->info("✓ {$name}");
            } else {
                $changed[$name] = $differences;
                $this->warn("~ {$name}: responde, pero con otros valores");
                foreach ($differences as $line) {
                    $this->line("    - {$line}");
                }
            }
        }

        if ($broken !== []) {
            Log::error('tax_relief.monitor_broken', ['broken' => $broken, 'changed' => $changed]);
            $this->notify('Calculadora tributaria: dejó de funcionar', $this->brokenBody($broken, $changed));

            return self::FAILURE;
        }

        if ($changed !== []) {
            Log::warning('tax_relief.monitor_calculation_changed', ['changed' => $changed]);
            $this->notify('Calculadora tributaria: cambió el cálculo', $this->changedBody($changed));

            return self::SUCCESS;
        }

        Log::info('tax_relief.monitor_ok');
        $this->info('OK: los 3 servicios responden y el caso de referencia da los mismos valores.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Pruebas (devuelven la lista de diferencias; vacía = todo igual)
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function checkPlan(TaxReliefService $service): array
    {
        $plan = $service->plan([
            'fecha_solicitud' => self::REQUEST_DATE,
            'garantia' => 'efectivo',
            'cuotas' => 12,
            'deudas' => [self::DEBT],
        ], fresh: true);

        return $this->compare([
            'Deuda sin alivio' => [21400.77, $plan['total_without_relief']],
            'Al contado' => [12352.15, $plan['total_cash']],
            'Base en cuotas' => [14185.57, $plan['plan_base']],
            'Saldo en cuotas' => [12767.01, $plan['balance']],
            'Número de cuotas' => [12, $plan['installments']],
            'Filas del cronograma' => [12, count($plan['schedule'])],
        ]);
    }

    /** @return list<string> */
    private function checkBreakdown(TaxReliefService $service): array
    {
        $detail = $service->breakdown(self::DEBT + ['fecha_solicitud' => self::REQUEST_DATE], fresh: true);
        $days = array_column($detail['tranches'], 'days');

        return $this->compare([
            'Mantenimiento de valor' => [4704.30, $detail['value_adjustment']],
            'Interés' => [6696.47, $detail['interest']],
            'Deuda total' => [21400.77, $detail['total']],
            'Tramos de interés (días)' => ['1461, 1096, 273', implode(', ', $days)],
        ]);
    }

    /** @return list<string> */
    private function checkDueDate(TaxReliefService $service): array
    {
        $date = $service->dueDate('IVA', '2019-01', 2, fresh: true);

        return $this->compare(['Vencimiento IVA 2019-01, NIT terminado en 2' => ['2019-02-15', $date]]);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $pairs  etiqueta => [esperado, recibido]
     * @return list<string>
     */
    private function compare(array $pairs): array
    {
        $differences = [];
        foreach ($pairs as $label => [$expected, $actual]) {
            $same = is_numeric($expected) && is_numeric($actual)
                ? abs((float) $expected - (float) $actual) <= 0.005
                : (string) $expected === (string) $actual;
            if (! $same) {
                $differences[] = "{$label}: antes {$expected}, ahora ".($actual ?? 'vacío');
            }
        }

        return $differences;
    }

    private function describe(UpstreamUnavailable $e): string
    {
        return match ($e->reason) {
            UpstreamUnavailable::FORMAT => 'respondió con un formato distinto al que conocemos ('.$e->getMessage().')',
            UpstreamUnavailable::REJECTED => 'rechazó los datos del caso de referencia ('.($e->detail ?? 'sin detalle').')',
            UpstreamUnavailable::DISABLED => 'la configuración no permite llamarlo ('.$e->getMessage().')',
            default => 'no respondió o respondió con error ('.$e->getMessage().')',
        };
    }

    // ------------------------------------------------------------------
    // Correo al desarrollador líder
    // ------------------------------------------------------------------

    /** @param array<string, string> $broken @param array<string, list<string>> $changed */
    private function brokenBody(array $broken, array $changed): string
    {
        $lines = ['La revisión diaria de la calculadora tributaria encontró servicios de boliviaimpuestos.com que no funcionan:', ''];
        foreach ($broken as $name => $why) {
            $lines[] = "✗ {$name}: {$why}";
        }
        foreach ($changed as $name => $differences) {
            $lines[] = "~ {$name}: ".implode('; ', $differences);
        }

        return implode("\n", array_merge($lines, [
            '',
            'Qué ven los estudiantes: "La calculadora no está disponible en este momento" en la parte que falla.',
            '',
            'Qué hacer:',
            '1. Probar la calculadora en el sitio. Si el problema sigue, revisar storage/logs (buscar "tax_relief.").',
            '2. Si boliviaimpuestos cambió o bloqueó su servicio, apagar la calculadora con TAX_RELIEF_ENABLED=false mientras se resuelve.',
            '3. Contrato de su API y pruebas: docs/calculadora-alivio-tributario/ (php probar-api.php).',
        ], $this->footer()));
    }

    /** @param array<string, list<string>> $changed */
    private function changedBody(array $changed): string
    {
        $lines = ['boliviaimpuestos.com sigue respondiendo, pero el caso de referencia ahora da otros valores:', ''];
        foreach ($changed as $name => $differences) {
            $lines[] = "~ {$name}";
            foreach ($differences as $difference) {
                $lines[] = "    - {$difference}";
            }
        }

        return implode("\n", array_merge($lines, [
            '',
            'No es un error: ellos ajustaron su cálculo y la calculadora ya muestra los valores nuevos.',
            'Conviene revisar qué cambiaron y, si corresponde, actualizar los valores esperados en CheckUpstreamCommand.',
        ], $this->footer()));
    }

    /** @return list<string> */
    private function footer(): array
    {
        return [
            '',
            '—',
            'Sistema: '.config('app.name').' ('.config('app.url').', entorno '.app()->environment().')',
            'Revisión: '.Carbon::now((string) config('tax_relief.timezone'))->format('d/m/Y H:i').' (hora de Bolivia)',
            'Comando: php artisan tax-relief:check',
        ];
    }

    private function sendTestMail(): int
    {
        $sent = $this->notify(
            'Calculadora tributaria: correo de prueba',
            implode("\n", array_merge([
                'Este es un correo de prueba del aviso diario de la calculadora tributaria.',
                'Si te llegó, el correo está bien configurado: cuando boliviaimpuestos.com falle o cambie su cálculo, recibirás un aviso como este.',
            ], $this->footer())),
            force: true,
        );

        return $sent ? self::SUCCESS : self::FAILURE;
    }

    /** Envía el aviso a cada dirección de TAX_RELIEF_ALERT_EMAIL. Devuelve si se envió. */
    private function notify(string $subject, string $body, bool $force = false): bool
    {
        if ($this->option('no-mail') && ! $force) {
            $this->line('(--no-mail: no se envía el correo)');

            return false;
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            $this->warn('No hay correo del desarrollador líder configurado (TAX_RELIEF_ALERT_EMAIL): el aviso solo queda en el log.');
            Log::warning('tax_relief.monitor_no_recipient', ['subject' => $subject]);

            return false;
        }

        try {
            Mail::raw($body, fn ($message) => $message->to($recipients)->subject($subject));
            $this->info('Aviso enviado a: '.implode(', ', $recipients));

            return true;
        } catch (Throwable $e) {
            $this->error('No se pudo enviar el correo: '.$e->getMessage());
            Log::error('tax_relief.monitor_mail_failed', ['error' => $e::class, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $raw = (string) config('tax_relief.monitor.alert_to');
        $emails = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $valid = array_values(array_filter($emails, fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));

        if (count($valid) !== count($emails)) {
            $this->warn('Se ignoraron direcciones no válidas en TAX_RELIEF_ALERT_EMAIL.');
        }

        return $valid;
    }
}
