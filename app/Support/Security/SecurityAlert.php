<?php

namespace App\Support\Security;

use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * P8 (A09:2025, registro y alertas): aviso por correo a los Superadministradores activos ante
 * eventos de seguridad graves (cambios de nivel de administración, cuentas de administración
 * desactivadas, 2FA desactivado, bloqueos por intentos fallidos, correo de una cuenta revertido).
 *
 * El evento siempre queda en la bitácora inmutable antes de llamar aquí; el correo es un aviso
 * adicional. Por eso se envía después de responder (defer) y un fallo del servidor de correo se
 * reporta en el log en lugar de deshacer la acción.
 */
final class SecurityAlert
{
    /**
     * @param  array<string, string>  $details  Pares "etiqueta => valor" que se muestran en el correo.
     * @param  string|null  $dedupeKey  Si se indica, el mismo aviso se envía una sola vez por ventana.
     */
    public static function send(
        string $subject,
        string $summary,
        array $details = [],
        ?string $dedupeKey = null,
        int $dedupeSeconds = 3600,
    ): void {
        if ($dedupeKey !== null && ! Cache::add('security-alert:'.$dedupeKey, true, $dedupeSeconds)) {
            return;
        }

        $occurredAt = now();

        defer(function () use ($subject, $summary, $details, $occurredAt): void {
            try {
                $recipients = User::role('SuperAdmin')->get();

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new SecurityAlertNotification($subject, $summary, $details, $occurredAt));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }, always: true);
    }
}
