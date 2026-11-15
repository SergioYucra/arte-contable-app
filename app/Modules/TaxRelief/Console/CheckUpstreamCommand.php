<?php

namespace App\Modules\TaxRelief\Console;

use App\Modules\TaxRelief\Exceptions\UpstreamUnavailable;
use App\Modules\TaxRelief\Services\TaxReliefService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Monitor diario de la calculadora (programado en TaxReliefServiceProvider).
 *
 * Calcula un caso conocido con fecha fija ya pasada: sus resultados solo
 * dependen de UFV históricas, así que no deberían cambiar nunca.
 *  - Si el servicio externo falla o cambió de formato → correo "se rompió".
 *  - Si responde bien pero con otros montos → correo "cambió el cálculo"
 *    (no es un error: ellos ajustaron su lógica y la nuestra la sigue sola).
 */
class CheckUpstreamCommand extends Command
{
    protected $signature = 'tax-relief:check';

    protected $description = 'Revisa que la calculadora de alivio tributario (boliviaimpuestos.com) siga respondiendo igual';

    /** Caso de referencia: IVA 2019-01, Bs 10.000, solicitud 15/11/2026 (docs/calculadora-alivio-tributario). */
    private const CASE = [
        'fecha_solicitud' => '2026-11-15',
        'garantia' => 'efectivo',
        'cuotas' => 12,
        'deudas' => [[
            'impuesto' => 'IVA', 'periodo' => '2019-01',
            'fecha_vencimiento' => '2019-02-15', 'tributo_omitido' => 10000,
        ]],
    ];

    private const EXPECTED = [
        'total_without_relief' => 21400.77,
        'total_cash' => 12352.15,
        'plan_base' => 14185.57,
        'balance' => 12767.01,
        'installments' => 12,
    ];

    public function handle(TaxReliefService $service): int
    {
        if (! config('tax_relief.enabled')) {
            $this->info('La calculadora está desactivada (TAX_RELIEF_ENABLED=false): no se revisa.');

            return self::SUCCESS;
        }

        try {
            $plan = $service->plan(self::CASE, fresh: true);
        } catch (UpstreamUnavailable $e) {
            if ($e->reason === UpstreamUnavailable::BUSY) {
                $this->warn('Tope global de llamadas alcanzado: se revisará en la próxima corrida.');

                return self::SUCCESS;
            }

            return $this->broken("El servicio respondió con un problema ({$e->reason}): {$e->getMessage()}");
        } catch (Throwable $e) {
            return $this->broken('Error inesperado: '.$e::class);
        }

        $differences = [];
        foreach (self::EXPECTED as $field => $expected) {
            if (abs((float) $plan[$field] - $expected) > 0.005) {
                $differences[] = "{$field}: antes {$expected}, ahora {$plan[$field]}";
            }
        }
        if (count($plan['schedule']) !== self::EXPECTED['installments']) {
            $differences[] = 'cuotas del cronograma: antes '.self::EXPECTED['installments'].', ahora '.count($plan['schedule']);
        }

        if ($differences !== []) {
            $this->warn('Responde bien, pero cambió el cálculo:');
            foreach ($differences as $line) {
                $this->line("  - {$line}");
            }
            Log::warning('tax_relief.monitor_calculation_changed', ['differences' => $differences]);
            $this->notifyByEmail(
                'Calculadora de alivio tributario: cambió el cálculo',
                "boliviaimpuestos.com sigue respondiendo, pero el caso de referencia ahora da otros montos:\n\n- "
                .implode("\n- ", $differences)
                ."\n\nNo es un error: ellos ajustaron su cálculo y la calculadora ya muestra los montos nuevos. Conviene revisar qué cambió."
            );

            return self::SUCCESS;
        }

        $this->info('OK: la calculadora responde y el caso de referencia da los mismos montos.');
        Log::info('tax_relief.monitor_ok');

        return self::SUCCESS;
    }

    private function broken(string $why): int
    {
        $this->error("La calculadora NO funciona: {$why}");
        Log::error('tax_relief.monitor_broken', ['why' => $why]);
        $this->notifyByEmail(
            'Calculadora de alivio tributario: dejó de funcionar',
            "La revisión diaria no pudo calcular el caso de referencia.\n\nMotivo: {$why}\n\n"
            .'Los estudiantes ven el mensaje "La calculadora no está disponible". Si el problema sigue, puede que '
            .'boliviaimpuestos.com haya cambiado o bloqueado su servicio: ver docs/calculadora-alivio-tributario/ANALISIS-FAP-ALIVIO.md.'
        );

        return self::FAILURE;
    }

    private function notifyByEmail(string $subject, string $body): void
    {
        $to = config('tax_relief.monitor.alert_email');
        if (! is_string($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->warn('No hay correo de aviso configurado (TAX_RELIEF_ALERT_EMAIL / SUPERADMIN_EMAIL).');

            return;
        }

        try {
            Mail::raw($body, fn ($message) => $message->to($to)->subject($subject));
        } catch (Throwable $e) {
            Log::error('tax_relief.monitor_mail_failed', ['error' => $e::class]);
        }
    }
}
