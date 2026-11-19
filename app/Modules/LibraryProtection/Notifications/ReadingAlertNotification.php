<?php

namespace App\Modules\LibraryProtection\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo al administrador cuando la protección de los libros detecta algo.
 *
 * Los textos llegan escapados por la plantilla de Laravel (MailMessage escapa cada línea).
 */
class ReadingAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $headline,
        public readonly array $lines,
        public readonly string $actionText,
        public readonly string $actionUrl,
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
        $message = (new MailMessage)
            ->subject($this->subject)
            ->greeting($this->headline);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message
            ->action($this->actionText, $this->actionUrl)
            ->line('Este aviso lo envía la protección de los libros de '.config('app.name').'. Un mismo aviso no se repite antes de una hora.');
    }
}
