<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * P4: Administradores y Superadministradores no entran al panel sin 2FA. "Actuar como" una de esas
     * cuentas en un test representa una sesión que ya pasó el segundo paso, así que actingAs() les
     * marca el 2FA como configurado. Los tests del propio 2FA lo apagan con withoutAutoTwoFactor().
     */
    protected bool $autoConfirmTwoFactor = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function withoutAutoTwoFactor(): static
    {
        $this->autoConfirmTwoFactor = false;

        return $this;
    }

    public function actingAs(UserContract $user, $guard = null)
    {
        if (
            $this->autoConfirmTwoFactor
            && $user instanceof User
            && $user->requiresTwoFactorAuthentication()
            && ! $user->hasEnabledTwoFactorAuthentication()
        ) {
            // saveQuietly: sin eventos de modelo, así no aparece una fila extra en la bitácora del test.
            $user->forceFill([
                'two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
                'two_factor_recovery_codes' => [],
                'two_factor_confirmed_at' => now(),
            ])->saveQuietly();
        }

        // V3: authenticateSessions() guarda en la sesión el hash de la contraseña de quien inició sesión.
        // En un test la sesión sobrevive entre peticiones; cambiar de usuario con actingAs() equivale a
        // un login nuevo, así que se olvida el hash anterior (si no, la sesión se cerraría sola).
        if ($this->app->bound('session')) {
            $this->app['session']->driver()->forget('password_hash_'.($guard ?? config('auth.defaults.guard')));
        }

        return parent::actingAs($user, $guard);
    }
}
