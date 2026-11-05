<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
     * Misma conversión que usa el Gate para llegar al método de la policy ('update-role' → updateRole).
     * get_class_methods() desde fuera de la clase solo devuelve los métodos públicos.
     */
    private static function isUserPolicyAbility(string $ability): bool
    {
        $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;

        return in_array($method, get_class_methods(UserPolicy::class), true);
    }
}
