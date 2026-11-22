<?php

namespace App\Modules\LibraryProtection\Services;

use App\Models\BookEdition;
use App\Models\User;
use App\Modules\LibraryProtection\Models\ReadingHold;
use App\Modules\LibraryProtection\Notifications\ReadingAlertNotification;
use App\Support\HumanDate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Avisos por correo al administrador (usuarios SuperAdmin).
 *
 * El correo se envía después de responder al navegador (defer), para que el lector no espere
 * al servidor de correo; un fallo de envío se reporta y no rompe la respuesta. Cada aviso tiene
 * una clave para no repetirse antes de `alert_repeat_minutes`.
 */
final class ReadingAlerts
{
    public function paused(User $student, ReadingHold $hold): void
    {
        $this->send(
            "hold:{$hold->id}",
            new ReadingAlertNotification(
                subject: 'Libros: se pausó la lectura de '.$student->name,
                headline: 'Pausamos la lectura de una cuenta',
                lines: [
                    "Cuenta: {$student->name} ({$student->email}).",
                    'Motivo: '.$hold->reasonText(),
                    'La pausa termina sola '.HumanDate::until($hold->ends_at).' (hora de Bolivia).',
                    'Desde el panel puedes reactivar la lectura si fue un error, o quitarle el acceso si crees que estaba copiando los libros.',
                ],
                actionText: 'Revisar en Protección de libros',
                actionUrl: route('admin.library-protection.index'),
            ),
        );
    }

    public function possibleSharing(User $student, int $deviceSwitches, int $distinctIps, int $windowHours): void
    {
        $this->send(
            "sharing:{$student->id}",
            new ReadingAlertNotification(
                subject: 'Libros: posible cuenta compartida ('.$student->name.')',
                headline: 'Esta cuenta podría estar compartida',
                lines: [
                    "Cuenta: {$student->name} ({$student->email}).",
                    "En las últimas {$windowHours} horas cambió {$deviceSwitches} veces de dispositivo y leyó desde {$distinctIps} conexiones distintas.",
                    'La lectura no se pausó: solo un dispositivo puede leer a la vez, así que quien comparte la cuenta se va turnando. Revisa el detalle y decide si corresponde hablar con la persona o quitarle el acceso.',
                ],
                actionText: 'Revisar en Protección de libros',
                actionUrl: route('admin.library-protection.index'),
            ),
        );
    }

    public function watermarkUnavailable(string $reason): void
    {
        $sent = $this->send(
            'watermark-unavailable',
            new ReadingAlertNotification(
                subject: 'Libros: el lector no puede mostrar páginas',
                headline: 'El lector está detenido para proteger los libros',
                lines: [
                    'El servidor no pudo poner la marca de agua en las páginas, así que no las está entregando.',
                    "Detalle técnico: {$reason}",
                    'Ejecuta "php artisan libros:diagnostico" en el servidor para ver qué falta (normalmente la extensión GD de PHP con WebP y FreeType).',
                ],
                actionText: 'Abrir Protección de libros',
                actionUrl: route('admin.library-protection.index'),
            ),
        );

        // Una línea por aviso (no una por cada página pedida mientras el lector está detenido).
        if ($sent) {
            Log::error('library_protection.watermark_unavailable', ['reason' => $reason]);
        }
    }

    /**
     * Una página puntual no se pudo marcar (el servidor sí puede marcar las demás): lo más
     * probable es que su archivo esté dañado. Un aviso por edición, no uno por página.
     */
    public function pageUnreadable(BookEdition $edition, int $page, string $reason): void
    {
        $edition->loadMissing('book');
        $title = trim(($edition->book?->title ?? 'Libro sin título').' · '.($edition->edition_name ?? ''), ' ·');

        $sent = $this->send(
            "page-unreadable:{$edition->id}",
            new ReadingAlertNotification(
                subject: 'Libros: una página no se pudo mostrar',
                headline: 'Una página de un libro no se pudo mostrar',
                lines: [
                    "Libro: {$title}, página {$page}.",
                    'El servidor no pudo ponerle la marca de agua a esa página, así que no la entregó. Quien la pidió vio "El lector no está disponible ahora" y la página no se le descontó.',
                    "Detalle técnico: {$reason}",
                    'Si pasa con una sola página, lo más probable es que su archivo esté dañado: vuelve a subir las páginas de esa edición desde Libros y ediciones.',
                ],
                actionText: 'Abrir Libros y ediciones',
                actionUrl: route('admin.books.index'),
            ),
        );

        if ($sent) {
            Log::error('library_protection.page_unreadable', [
                'edition_id' => $edition->id,
                'page' => $page,
                'reason' => $reason,
            ]);
        }
    }

    /** Devuelve si el aviso salió (false si ya se avisó lo mismo en la última hora). */
    private function send(string $key, ReadingAlertNotification $notification): bool
    {
        $minutes = max(1, (int) config('library.protection.alert_repeat_minutes', 60));

        if (! Cache::add('library-protection:alert:'.$key, true, now()->addMinutes($minutes))) {
            return false;
        }

        defer(function () use ($notification): void {
            try {
                $admins = User::role('SuperAdmin')->get();
                if ($admins->isEmpty()) {
                    Log::warning('library_protection.alert_without_recipient', ['subject' => $notification->subject]);

                    return;
                }

                Notification::send($admins, $notification);
            } catch (Throwable $e) {
                report($e);
            }
        }, always: true);

        return true;
    }
}
