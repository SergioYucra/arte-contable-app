<?php

namespace App\Modules\TaxRelief;

use App\Modules\TaxRelief\Console\CheckUpstreamCommand;
use App\Modules\TaxRelief\Services\BoliviaImpuestosClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo "Calculadora de alivio tributario".
 *
 * Se registra con su propio proveedor (bootstrap/providers.php) en lugar de
 * editar bootstrap/app.php y routes/web.php, porque esos archivos los modifica
 * la rama fix/revision-3 (pendiente de integrar): así no se cruzan.
 */
class TaxReliefServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BoliviaImpuestosClient::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/Routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckUpstreamCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('tax-relief:check')
                    ->dailyAt((string) config('tax_relief.monitor.daily_at'))
                    ->timezone((string) config('tax_relief.timezone'))
                    ->withoutOverlapping();
            });
        }
    }

    /**
     * Límites por persona (las rutas los usan con throttle:...).
     * El tope GLOBAL hacia boliviaimpuestos vive en BoliviaImpuestosClient.
     */
    private function registerRateLimiters(): void
    {
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => response()->json([
            'message' => $message,
            'reason' => 'too_many',
        ], 429, $headers);

        RateLimiter::for('tax-relief', function (Request $request) use ($tooMany): array {
            $who = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute((int) config('tax_relief.limits.per_user_per_minute'))
                    ->by('tax-relief:minute:'.$who)
                    ->response($tooMany('Hiciste muchos cálculos seguidos. Espera un minuto y vuelve a intentar.')),
                Limit::perDay((int) config('tax_relief.limits.per_user_per_day'))
                    ->by('tax-relief:day:'.$who)
                    ->response($tooMany('Llegaste al máximo de cálculos por hoy. Mañana puedes seguir.')),
            ];
        });

        RateLimiter::for('tax-relief-lookup', function (Request $request) use ($tooMany): Limit {
            $who = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute((int) config('tax_relief.limits.lookups_per_user_per_minute'))
                ->by('tax-relief:lookup:'.$who)
                ->response($tooMany('Espera un momento antes de buscar otra fecha de vencimiento.'));
        });
    }
}
