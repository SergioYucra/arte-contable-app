<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * V2: aviso al dueño de un correo que ya tiene cuenta cuando alguien intenta registrarse con él.
 * Es la contracara de la respuesta neutra del registro: quien llena el formulario no se entera de si
 * el correo existe; el dueño sí, por su propio correo.
 */
class RegistrationAttemptNotification extends Notification
{
    public function __construct(public readonly bool $deactivated = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = ['Alguien intentó crear una cuenta nueva con este correo, pero ya tienes una. No creamos ninguna cuenta.'];

        $lines[] = $this->deactivated
            ? 'Tu cuenta está desactivada. Si quieres volver a usarla, comunícate con el administrador del sistema.'
            : 'Si fuiste tú, inicia sesión con tu cuenta. Si no recuerdas la contraseña, usa "¿Olvidaste tu contraseña?" en la pantalla de inicio de sesión.';

        return (new MailMessage)
            ->subject('Intento de registro con tu correo')
            ->view(['emails.security-notice', 'emails.security-notice-text'], [
                'heading' => 'Intento de registro con tu correo',
                'lines' => $lines,
                'details' => [],
                'actionText' => $this->deactivated ? null : 'Iniciar sesión',
                'actionUrl' => $this->deactivated ? null : route('login'),
                'footer' => 'Si no fuiste tú, puedes ignorar este correo: tu cuenta no cambió.',
            ]);
    }
}
