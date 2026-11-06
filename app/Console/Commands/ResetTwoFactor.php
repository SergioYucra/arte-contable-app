<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P4: recuperación de una cuenta que perdió el teléfono Y los códigos de recuperación. Solo se puede
 * hacer con acceso al servidor; queda en la bitácora de seguridad. La cuenta tendrá que volver a
 * configurar la verificación en dos pasos al entrar (si su nivel la exige).
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'user:reset-two-factor {email : Correo de la cuenta}';

    protected $description = 'Desactiva la verificación en dos pasos de una cuenta (pérdida del teléfono y de los códigos)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No hay una cuenta activa con ese correo.');

            return self::FAILURE;
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $this->info('Esa cuenta no tiene la verificación en dos pasos activa.');

            return self::SUCCESS;
        }

        if (! $this->confirm("¿Desactivar la verificación en dos pasos de {$user->name} ({$user->email})? Hazlo solo si confirmaste su identidad por otro medio.")) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            activity('security')
                ->performedOn($user)
                ->withProperties(['via' => 'artisan user:reset-two-factor'])
                ->log('Verificación en dos pasos desactivada desde la consola del servidor.');
        });

        $this->info('Listo. La cuenta tendrá que configurar de nuevo la verificación en dos pasos.');

        return self::SUCCESS;
    }
}
