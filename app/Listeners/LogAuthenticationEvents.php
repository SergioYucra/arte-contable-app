<?php

namespace App\Listeners;

use App\Support\Security\SecurityAlert;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * P7 (H8, A09:2025): los eventos de autenticación quedan en la bitácora de seguridad.
 *
 * Nunca se registra la contraseña: de las credenciales de un intento fallido solo se guarda el
 * correo escrito. Como la bitácora es inmutable y no se depura, los eventos repetibles (fallos y
 * bloqueos) se registran una vez por ventana y no una vez por petición.
 *
 * Laravel registra solo cada método handle*() para el evento de su parámetro (descubrimiento de
 * eventos en app/Listeners): no hace falta registrarlo a mano, y hacerlo duplicaría las filas.
 */
class LogAuthenticationEvents
{
    public const FAILED_LOGIN_LOG_SECONDS = 60;

    public const LOCKOUT_LOG_SECONDS = 900;

    public function handleLogin(Login $event): void
    {
        activity('security')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($this->context() + ['remember' => $event->remember])
            ->log('Inicio de sesión.');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        activity('security')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($this->context())
            ->log('Cierre de sesión.');
    }

    public function handleFailed(Failed $event): void
    {
        $email = Str::lower((string) ($event->credentials['email'] ?? ''));

        if (! Cache::add('auth-log:failed:'.sha1($email.'|'.request()->ip()), true, self::FAILED_LOGIN_LOG_SECONDS)) {
            return;
        }

        $log = activity('security')->withProperties($this->context() + ['attempted_email' => $email]);

        if ($event->user) {
            $log->performedOn($event->user);
        }

        $log->log('Inicio de sesión fallido (contraseña incorrecta o cuenta inexistente).');
    }

    public function handleLockout(Lockout $event): void
    {
        $email = Str::lower((string) $event->request->input('email', ''));
        $ip = (string) $event->request->ip();
        $key = sha1($email.'|'.$ip);

        if (! Cache::add('auth-log:lockout:'.$key, true, self::LOCKOUT_LOG_SECONDS)) {
            return;
        }

        activity('security')
            ->withProperties($this->context() + ['attempted_email' => $email])
            ->log('Inicio de sesión bloqueado por demasiados intentos fallidos.');

        SecurityAlert::send(
            'Bloqueo por intentos fallidos de inicio de sesión',
            "Se bloqueó temporalmente el inicio de sesión para el correo {$email} desde la IP {$ip} por demasiados intentos fallidos.",
            ['IP' => $ip],
            dedupeKey: 'lockout:'.$key,
            dedupeSeconds: self::LOCKOUT_LOG_SECONDS,
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        activity('security')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($this->context())
            ->log('Contraseña restablecida con el enlace del correo; se cerraron todas las sesiones.');
    }

    public function handleVerified(Verified $event): void
    {
        activity('security')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($this->context())
            ->log('Correo verificado.');
    }

    /**
     * @return array<string, string|null>
     */
    private function context(): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 255, ''),
        ];
    }
}
