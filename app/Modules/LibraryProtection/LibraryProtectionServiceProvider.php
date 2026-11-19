<?php

namespace App\Modules\LibraryProtection;

use App\Modules\LibraryProtection\Console\DiagnoseCommand;
use App\Modules\LibraryProtection\Console\PrunePageViewsCommand;
use App\Modules\LibraryProtection\Services\ReadingDecision;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo "Protección de los libros" contra la descarga masiva.
 *
 * Registra el límite por minuto de las páginas, las rutas del módulo, los comandos y la
 * limpieza diaria del registro. Va en bootstrap/providers.php para no tocar bootstrap/app.php,
 * routes/console.php ni AppServiceProvider, que modifica la rama fix/revision-3.
 *
 * Análisis y medidas: docs/seguridad-libros/ANALISIS.md
 */
class LibraryProtectionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Los correos al administrador incluyen el nombre que escribió el estudiante. Sin esto,
        // Laravel no escapa el Markdown de lo que se imprime con {{ }} y un nombre como
        // "[Panel](https://sitio-falso)" se volvería un enlace dentro de un correo oficial.
        Markdown::withSecuredEncoding();

        RateLimiter::for('book-pages', function (Request $request): Limit {
            $user = $request->user();
            $perMinute = max(1, (int) config('library.protection.pages_per_minute', 30));

            if ($user !== null && $user->hasAnyRole('SuperAdmin', 'Admin')) {
                $perMinute *= 4;
            }

            return Limit::perMinute($perMinute)
                ->by('book-pages:'.($user?->id ?? $request->ip()))
                ->response(fn (Request $request, array $headers) => tap(
                    ReadingDecision::deny(429, 'muy_rapido', 'Para cuidar los libros, el lector frena cuando se pasan muchas páginas seguidas. Espera unos segundos y sigue leyendo.')->toResponse(),
                    fn ($response) => $response->withHeaders($headers),
                ));
        });

        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([DiagnoseCommand::class, PrunePageViewsCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('libros:limpiar-registro')
                ->dailyAt('03:30')
                ->timezone((string) config('library.protection.timezone', 'America/La_Paz'))
                ->withoutOverlapping();
        });
    }
}
