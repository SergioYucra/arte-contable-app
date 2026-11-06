<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Aviso de seguridad para los Superadministradores (P8).
 *
 * Usa una vista Blade propia en lugar de las líneas Markdown de MailMessage: el resumen incluye
 * nombres que escriben los usuarios, y en Markdown un nombre como "[texto](https://…)" se
 * convertiría en un enlace dentro de un correo de seguridad. Blade lo escapa como texto.
 */
class SecurityAlertNotification extends Notification
{
    /**
     * @param  array<string, string>  $details
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $summary,
        public readonly array $details,
        public readonly CarbonInterface $occurredAt,
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
        $details = $this->details + ['Fecha' => $this->occurredAt->format('d/m/Y H:i')];

        return (new MailMessage)
            ->subject('[Alerta de seguridad] '.$this->subject)
            ->view(['emails.security-notice', 'emails.security-notice-text'], [
                'heading' => $this->subject,
                'lines' => [$this->summary],
                'details' => $details,
                'actionText' => Route::has('admin.audit-logs.index') ? 'Revisar la bitácora' : null,
                'actionUrl' => Route::has('admin.audit-logs.index') ? route('admin.audit-logs.index') : null,
                'footer' => 'Si reconoces esta acción, no tienes que hacer nada. Si no, revisa la bitácora y cambia las credenciales afectadas.',
            ]);
    }
}
