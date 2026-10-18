<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        // SuperAdmin: acceso implícito a todos los permisos/policies.
        // Devuelve null (no false) para el resto, dejando decidir a Spatie/Policies.
        Gate::before(function ($user, $ability) {
            return $user->hasRole('SuperAdmin') ? true : null;
        });
    }
}
