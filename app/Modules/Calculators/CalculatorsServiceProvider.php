<?php

namespace App\Modules\Calculators;

use App\Modules\Calculators\Console\CheckSourcesCommand;
use App\Modules\Calculators\Services\BoliviaImpuestosPages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo "Calculadoras RC-IVA y Bono de antigüedad".
 *
 * Las calculadoras viven en el navegador (resources/js/components/calculators) y se
 * abren desde el catálogo de Calculadoras: no hay rutas ni endpoints nuevos.
 * Este proveedor solo registra la revisión diaria contra boliviaimpuestos.com.
 * Va en bootstrap/providers.php para no tocar routes/console.php ni bootstrap/app.php,
 * que modifica la rama fix/revision-3.
 */
class CalculatorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BoliviaImpuestosPages::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CheckSourcesCommand::class]);

        if (! config('calculators.monitor.enabled')) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('calculators:check')
                ->dailyAt((string) config('calculators.monitor.daily_at'))
                ->timezone((string) config('calculators.timezone'))
                ->withoutOverlapping();
        });
    }
}
