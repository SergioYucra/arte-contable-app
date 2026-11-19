<?php

namespace App\Modules\LibraryProtection\Console;

use App\Modules\LibraryProtection\Exceptions\WatermarkUnavailable;
use App\Modules\LibraryProtection\Services\PageWatermarker;
use App\Modules\LibraryProtection\Services\WatermarkStamp;
use Illuminate\Console\Command;
use Throwable;

/**
 * Revisión antes de desplegar: si algo de esto falla en el servidor, el lector se detiene
 * (falla cerrado) en vez de entregar páginas sin marca de agua.
 */
class DiagnoseCommand extends Command
{
    protected $signature = 'libros:diagnostico';

    protected $description = 'Revisa que el servidor pueda proteger los libros (GD con WebP y FreeType, fuente, correo, tareas programadas)';

    public function handle(PageWatermarker $watermarker): int
    {
        $failed = false;

        $this->line('Protección de libros: diagnóstico del servidor');
        $this->newLine();

        $reason = $watermarker->unavailableReason();
        $failed = $this->report('PHP GD con WebP y FreeType, y la fuente de la marca', $reason === null, $reason) || $failed;

        $font = (string) config('library.protection.watermark.font');
        $expected = (string) config('library.protection.watermark.font_sha256');
        $actual = is_readable($font) ? hash_file('sha256', $font) : null;
        $failed = $this->report(
            'La fuente es la original (SHA-256)',
            $actual !== null && hash_equals($expected, (string) $actual),
            $actual === null ? 'No se puede leer la fuente.' : 'El archivo de la fuente cambió: vuelve a copiarlo del repositorio.',
        ) || $failed;

        if ($reason === null) {
            $failed = $this->report('Marca de agua de prueba', $this->trialStamp($watermarker), 'No se pudo marcar una página de prueba.') || $failed;
        }

        $mailer = (string) config('mail.default');
        $mailOk = ! in_array($mailer, ['log', 'array'], true) || ! app()->isProduction();
        $failed = $this->report(
            "Correo para los avisos al administrador (MAIL_MAILER={$mailer})",
            $mailOk,
            'En producción los avisos no saldrían: MAIL_MAILER no puede ser log ni array.',
        ) || $failed;

        $this->newLine();
        $this->line('Recordatorios que este comando no puede comprobar:');
        $this->line(' - El cron de Hostinger debe ejecutar "php artisan schedule:run" cada minuto (limpieza diaria del registro).');
        $this->line(' - Si hay Cloudflare u otro proxy delante, configura TRUSTED_PROXIES: si no, todas las lecturas parecen venir de la misma IP.');
        $this->line(' - Los respaldos incluyen las páginas de los libros: guárdalos fuera del servidor y con contraseña.');

        $this->newLine();
        if ($failed) {
            $this->error('Hay problemas: el lector no entregará páginas hasta corregirlos.');

            return self::FAILURE;
        }

        $this->info('Todo listo: el lector puede entregar páginas con marca de agua.');

        return self::SUCCESS;
    }

    /** Devuelve true si la revisión falló. */
    private function report(string $label, bool $ok, ?string $problem): bool
    {
        if ($ok) {
            $this->line("  <info>✔</info> {$label}");

            return false;
        }

        $this->line("  <error>✘</error> {$label}");
        if ($problem) {
            $this->line("      {$problem}");
        }

        return true;
    }

    private function trialStamp(PageWatermarker $watermarker): bool
    {
        try {
            $image = imagecreatetruecolor(300, 420);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagewebp($image, null, 80);
            $page = (string) ob_get_clean();

            $stamped = $watermarker->stamp($page, new WatermarkStamp('Prueba', 'p***a@ejemplo.com', 'TEST-0000', date('d/m/Y')));

            return @imagecreatefromstring($stamped) !== false;
        } catch (WatermarkUnavailable|Throwable) {
            return false;
        }
    }
}
