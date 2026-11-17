<?php

namespace App\Modules\Calculators\Console;

use App\Modules\Calculators\Exceptions\SourceUnavailable;
use App\Modules\Calculators\Services\BoliviaImpuestosPages;
use App\Modules\Calculators\Services\FormulaReader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Revisión diaria de las calculadoras RC-IVA y Bono de antigüedad
 * (programada en CalculatorsServiceProvider).
 *
 * boliviaimpuestos.com no tiene API para estos cálculos: copiamos sus fórmulas.
 * Por eso, a diferencia de la calculadora tributaria, aquí NO seguimos sus cambios
 * solos: este comando descarga sus dos páginas, lee sus tasas y tablas y avisa al
 * desarrollador líder para que actualice parametros.json.
 *  - Algo cambió (tasas, tablas o el código del cálculo) → correo "cambió su cálculo".
 *  - No se pudo revisar (caída, bloqueo, página irreconocible) → correo "no se pudo revisar"
 *    y el comando termina con error.
 */
class CheckSourcesCommand extends Command
{
    protected $signature = 'calculators:check
        {--no-mail : Solo muestra el resultado, sin enviar correo}
        {--test-mail : Envía un correo de prueba a CALCULATORS_ALERT_EMAIL (para comprobar el SMTP) y termina}
        {--show-fingerprint : Muestra la huella actual de cada página (para config/calculators.php)}';

    protected $description = 'Revisa si boliviaimpuestos.com cambió las fórmulas que copian las calculadoras RC-IVA y Bono de antigüedad';

    public function handle(BoliviaImpuestosPages $pages): int
    {
        if ($this->option('test-mail')) {
            return $this->sendTestMail();
        }

        try {
            $ours = FormulaReader::ours();
        } catch (Throwable $e) {
            $this->error('No se pudo leer parametros.json: '.$e->getMessage());
            Log::error('calculators.monitor_own_parameters_unreadable', ['error' => $e::class]);

            return self::FAILURE;
        }

        $broken = [];
        $changed = [];

        foreach ((array) config('calculators.sources') as $key => $source) {
            $name = (string) $source['name'];

            try {
                $html = $pages->fetch((string) $source['url']);
                $script = BoliviaImpuestosPages::calculationScript($html, (string) $source['marker']);
                if ($script === null) {
                    throw new SourceUnavailable('no encontramos el cálculo en su página: puede que la hayan rediseñado');
                }
                $theirs = FormulaReader::fromPage((string) $key, $script, $html);
            } catch (SourceUnavailable $e) {
                $broken[$name] = $e->getMessage();
                $this->error("✗ {$name}: {$broken[$name]}");

                continue;
            } catch (Throwable $e) {
                $broken[$name] = 'error inesperado ('.$e::class.')';
                $this->error("✗ {$name}: {$broken[$name]}");

                continue;
            }

            $fingerprint = BoliviaImpuestosPages::fingerprint($script);
            if ($this->option('show-fingerprint')) {
                $this->line("Huella actual de {$name}: {$fingerprint}");
            }

            $differences = FormulaReader::differences((string) $key, $ours[$key], $theirs);

            $expected = (string) ($source['fingerprint'] ?? '');
            if ($expected === '') {
                $this->warn("  (sin huella guardada para {$name} en config/calculators.php: solo se comparan tasas y tablas)");
            } elseif ($differences === [] && ! hash_equals($expected, $fingerprint)) {
                $differences[] = 'El código de su cálculo cambió, aunque las tasas y tablas que leemos siguen iguales. Conviene mirar la página por si cambiaron la fórmula.';
            }

            if ($differences === []) {
                $this->info("✓ {$name}: igual que nuestras calculadoras");
            } else {
                $changed[$name] = $differences;
                $this->warn("~ {$name}: cambió algo");
                foreach ($differences as $line) {
                    $this->line("    - {$line}");
                }
            }
        }

        if ($broken !== []) {
            Log::error('calculators.monitor_broken', ['broken' => $broken, 'changed' => $changed]);
            $this->notify('Calculadoras RC-IVA y Bono: no se pudo revisar boliviaimpuestos', $this->brokenBody($broken, $changed));

            return self::FAILURE;
        }

        if ($changed !== []) {
            Log::warning('calculators.monitor_changed', ['changed' => $changed]);
            $this->notify('Calculadoras RC-IVA y Bono: boliviaimpuestos cambió su cálculo', $this->changedBody($changed));

            return self::SUCCESS;
        }

        Log::info('calculators.monitor_ok');
        $this->info('OK: las dos páginas siguen con las mismas fórmulas que usamos.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Correos
    // ------------------------------------------------------------------

    /**
     * @param  array<string, string>  $broken
     * @param  array<string, list<string>>  $changed
     */
    private function brokenBody(array $broken, array $changed): string
    {
        $lines = ['No se pudo revisar si boliviaimpuestos.com cambió sus calculadoras:', ''];
        foreach ($broken as $name => $why) {
            $lines[] = "✗ {$name}: {$why}";
        }
        if ($changed !== []) {
            $lines[] = '';
            $lines[] = 'Además, en las que sí se pudieron revisar cambió algo:';
            $lines = array_merge($lines, $this->changeLines($changed));
        }

        return implode("\n", array_merge($lines, [
            '',
            'Los estudiantes no notan nada: nuestras calculadoras calculan en su navegador y siguen funcionando.',
            'Solo que hoy no sabemos si boliviaimpuestos ajustó alguna tasa o tabla.',
            '',
            'Qué hacer:',
            '1. Abrir las páginas (enlaces abajo) y ver si cargan.',
            '2. Si cambiaron de diseño, revisar dónde quedó su cálculo y ajustar app/Modules/Calculators/Services/FormulaReader.php.',
            '3. Si es un corte pasajero, no hace falta nada: mañana se revisa de nuevo.',
        ], $this->footer()));
    }

    /** @param array<string, list<string>> $changed */
    private function changedBody(array $changed): string
    {
        $lines = ['boliviaimpuestos.com cambió algo en las calculadoras que copiamos:', ''];
        $lines = array_merge($lines, $this->changeLines($changed));

        return implode("\n", array_merge($lines, [
            '',
            'Nuestras calculadoras siguen con los valores de antes hasta que alguien las actualice.',
            '',
            'Qué hacer:',
            '1. Abrir su página (enlaces abajo) y confirmar el cambio.',
            '2. Si corresponde, actualizar resources/js/components/calculators/parametros.json',
            '   (y rcIva.js o bonoAntiguedad.js si cambió la fórmula). Luego: npm run test:js y npm run build.',
            '3. Guardar la huella nueva en config/calculators.php: php artisan calculators:check --show-fingerprint',
            'Detalle en docs/calculadoras-rciva-bono/ANALISIS.md.',
        ], $this->footer()));
    }

    /**
     * @param  array<string, list<string>>  $changed
     * @return list<string>
     */
    private function changeLines(array $changed): array
    {
        $lines = [];
        foreach ($changed as $name => $differences) {
            $lines[] = "~ {$name}";
            foreach ($differences as $difference) {
                $lines[] = "    - {$difference}";
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function footer(): array
    {
        $links = array_map(
            fn (array $s) => "{$s['name']}: {$s['url']}",
            array_values((array) config('calculators.sources')),
        );

        return array_merge(['', 'Páginas revisadas:'], $links, [
            '',
            '—',
            'Sistema: '.config('app.name').' ('.config('app.url').', entorno '.app()->environment().')',
            'Revisión: '.Carbon::now((string) config('calculators.timezone'))->format('d/m/Y H:i').' (hora de Bolivia)',
            'Comando: php artisan calculators:check',
        ]);
    }

    private function sendTestMail(): int
    {
        $sent = $this->notify(
            'Calculadoras RC-IVA y Bono: correo de prueba',
            implode("\n", array_merge([
                'Este es un correo de prueba de la revisión diaria de las calculadoras RC-IVA y Bono de antigüedad.',
                'Si te llegó, el correo está bien configurado: cuando boliviaimpuestos.com cambie sus fórmulas, recibirás un aviso como este.',
            ], $this->footer())),
            force: true,
        );

        return $sent ? self::SUCCESS : self::FAILURE;
    }

    /** Envía el aviso a cada dirección de CALCULATORS_ALERT_EMAIL. Devuelve si se envió. */
    private function notify(string $subject, string $body, bool $force = false): bool
    {
        if ($this->option('no-mail') && ! $force) {
            $this->line('(--no-mail: no se envía el correo)');

            return false;
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            $this->warn('No hay correo del desarrollador líder configurado (CALCULATORS_ALERT_EMAIL): el aviso solo queda en el log.');
            Log::warning('calculators.monitor_no_recipient', ['subject' => $subject]);

            return false;
        }

        try {
            Mail::raw($body, fn ($message) => $message->to($recipients)->subject($subject));
            $this->info('Aviso enviado a: '.implode(', ', $recipients));

            return true;
        } catch (Throwable $e) {
            $this->error('No se pudo enviar el correo: '.$e->getMessage());
            Log::error('calculators.monitor_mail_failed', ['error' => $e::class, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $raw = (string) config('calculators.monitor.alert_to');
        $emails = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $valid = array_values(array_filter($emails, fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));

        if (count($valid) !== count($emails)) {
            $this->warn('Se ignoraron direcciones no válidas en CALCULATORS_ALERT_EMAIL.');
        }

        return $valid;
    }
}
