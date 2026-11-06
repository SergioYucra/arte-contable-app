<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Security\FailClosedPwnedVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // P17: el verificador de contraseñas filtradas falla cerrado. Se usa extend() porque Laravel
        // registra UncompromisedVerifier en un proveedor diferido que pisaría un bind() hecho aquí.
        $this->app->extend(UncompromisedVerifier::class, function (UncompromisedVerifier $verifier, $app) {
            return config('app.pwned_passwords_fail_closed')
                ? new FailClosedPwnedVerifier($app->make(HttpFactory::class))
                : $verifier;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            // Solo en peticiones web: ahí está el riesgo (trazas a visitantes, enlaces de recuperación al log).
            // En consola debe seguir funcionando composer install (sin .env, Laravel asume production y
            // MAIL_MAILER=log) y `php artisan security:check`, que es justamente la herramienta para diagnosticarlo.
            if (! $this->app->runningInConsole()) {
                self::refuseDangerousProductionConfiguration();
            }

            // P3: los enlaces generados (correos, redirecciones) siempre salen con https://.
            URL::forceScheme('https');
        }

        // Política de contraseñas: mínimo 8 caracteres con mayúsculas, minúsculas, números y símbolos
        Password::defaults(function () {
            $rule = Password::min(8)
                ->mixedCase()
                ->numbers()
                ->symbols();

            return $this->app->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });

        // SuperAdmin: acceso implícito a todos los permisos/policies, con una excepción (N8):
        // las habilidades que UserPolicy define las decide siempre la policy, porque sus reglas
        // (último SuperAdmin activo, auto-desactivación) existen para proteger al propio SuperAdmin.
        // Cualquier otra habilidad que reciba un usuario (p. ej. un @can('algo', $user) futuro) conserva el bypass.
        // Devuelve null (no false) para el resto, dejando decidir a Spatie/Policies.
        Gate::before(function ($user, string $ability, array $arguments = []) {
            $target = $arguments[0] ?? null;

            if (($target instanceof User || $target === User::class) && self::isUserPolicyAbility($ability)) {
                return null;
            }

            return $user->hasRole('SuperAdmin') ? true : null;
        });
    }

    /**
     * P2 (H10, A02/A10:2025): en producción la app no arranca con una configuración que expone datos.
     * Es una falla cerrada a propósito: es preferible ver el error al desplegar que un sitio que
     * muestra trazas internas o guarda en el log los enlaces para restablecer contraseñas.
     * La lista completa de chequeos de despliegue está en `php artisan security:check`.
     */
    private static function refuseDangerousProductionConfiguration(): void
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG=true muestra trazas internas a cualquier visitante';
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $problems[] = 'MAIL_MAILER='.config('mail.default').' no envía correos: los escribe en el log, con los enlaces para restablecer contraseñas';
        }

        if ($problems !== []) {
            throw new RuntimeException('Configuración insegura para producción: '.implode('; ', $problems).'. Corrige el .env (ver .env.production.example).');
        }
    }

    /**
     * Misma conversión que usa el Gate para llegar al método de la policy ('update-role' → updateRole).
     * get_class_methods() desde fuera de la clase solo devuelve los métodos públicos.
     */
    private static function isUserPolicyAbility(string $ability): bool
    {
        $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;

        return in_array($method, get_class_methods(UserPolicy::class), true);
    }
}
