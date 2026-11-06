<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * V4: aviso al correo ANTERIOR cuando una cuenta cambia de correo, con un enlace firmado para revertirlo.
 * Usa la vista escapada de avisos de seguridad (ver SecurityAlertNotification).
 */
class EmailChangedNotification extends Notification
{
    public function __construct(
        public readonly string $accountName,
        public readonly string $newEmail,
        public readonly string $revertUrl,
        public readonly int $validDays,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Se cambió el correo de tu cuenta')
            ->view(['emails.security-notice', 'emails.security-notice-text'], [
                'heading' => 'Se cambió el correo de tu cuenta',
                'lines' => [
                    "El correo de la cuenta {$this->accountName} se cambió a {$this->newEmail}. Desde ahora, los avisos y la recuperación de contraseña van a esa dirección.",
                    "Si no fuiste tú, usa el botón: recuperamos este correo, cerramos todas las sesiones abiertas y te enviamos un enlace para elegir una contraseña nueva. El enlace sirve durante {$this->validDays} días.",
                ],
                'details' => [],
                'actionText' => 'No fui yo: recuperar mi cuenta',
                'actionUrl' => $this->revertUrl,
                'footer' => 'Si el cambio lo hiciste tú, ignora este correo.',
            ]);
    }
}
