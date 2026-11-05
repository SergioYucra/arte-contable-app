<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
        // las habilidades sobre App\Models\User las decide siempre UserPolicy, porque sus reglas
        // (último SuperAdmin activo, auto-desactivación) existen para proteger al propio SuperAdmin.
        // Devuelve null (no false) para el resto, dejando decidir a Spatie/Policies.
        Gate::before(function ($user, string $ability, array $arguments = []) {
            $target = $arguments[0] ?? null;

            if ($target instanceof User || $target === User::class) {
                return null;
            }

            return $user->hasRole('SuperAdmin') ? true : null;
        });
    }
}
