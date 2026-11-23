<?php

namespace App\Modules\LibraryProtection\Services;

use App\Core\Storage\PrivateVault;
use App\Models\BookEdition;
use App\Models\User;
use App\Modules\LibraryProtection\Exceptions\WatermarkUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega una página del lector: revisa el pedido (ReadingGuard), toma la imagen de la bóveda
 * privada y la devuelve con la marca de agua de quien la pide.
 */
final class ProtectedPages
{
    public function __construct(
        private readonly ReadingGuard $guard,
        private readonly PageWatermarker $watermarker,
        private readonly ReaderCodes $codes,
        private readonly ReadingAlerts $alerts,
    ) {}

    public function respond(Request $request, User $user, BookEdition $edition, int $page): Response
    {
        $isStaff = $user->hasAnyRole('SuperAdmin', 'Admin');

        // Falla cerrado antes de registrar nada: sin marca de agua no sale ninguna página.
        $unavailable = $this->watermarker->unavailableReason();
        if ($unavailable !== null) {
            $this->alerts->watermarkUnavailable($unavailable);

            return $this->stopped($isStaff
                ? "{$unavailable} Corre \"php artisan libros:diagnostico\" en el servidor para ver qué falta."
                : null);
        }

        $decision = $this->guard->check($user, $edition, $page, $request);
        if (! $decision->allowed) {
            // Quien abre la dirección de la imagen en una pestaña llega al lector en esa página.
            if ($decision->code === 'fuera_del_lector' && $request->headers->get('Sec-Fetch-Dest') === 'document') {
                return redirect()->route('library.reader', ['edition' => $edition->id, 'page' => max(1, $page)]);
            }

            return $decision->toResponse();
        }

        $path = PrivateVault::validatePath("editions/{$edition->id}/page-{$page}.webp");
        $disk = Storage::disk(PrivateVault::DISK);

        // Lo que no se entrega no se descuenta del tope de hoy ni cuenta para el ritmo.
        if (! $disk->exists($path) || $disk->mimeType($path) !== 'image/webp') {
            $this->guard->forgetView($decision);

            return ReadingDecision::deny(404, 'no_disponible', 'Esta página todavía no está lista. Intenta de nuevo más tarde.')->toResponse();
        }

        try {
            $bytes = $this->watermarker->stamp(
                (string) $disk->get($path),
                WatermarkStamp::for($user, $this->codes->for($user)),
            );
        } catch (WatermarkUnavailable $e) {
            report($e);
            $this->guard->forgetView($decision);
            $this->alerts->pageUnreadable($edition, $page, $e->getMessage());

            return $this->stopped($isStaff
                ? "No se pudo poner la marca de agua a la página {$page} de esta edición: {$e->getMessage()} Si pasa solo con esta página, su archivo puede estar dañado: vuelve a subir las páginas de la edición."
                : null);
        }

        $headers = [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="pagina.webp"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox",
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ];

        if ($decision->remainingToday !== null) {
            $headers['X-Paginas-Restantes'] = (string) $decision->remainingToday;
        }

        return response($bytes, 200, $headers);
    }

    /**
     * Lector detenido. Antes de llegar aquí siempre se avisó al administrador por correo (un
     * aviso por hora), así que el mensaje al estudiante es cierto. El personal ve además el
     * motivo técnico ($staffDetail), y no se le dice "ya avisamos al administrador".
     */
    private function stopped(?string $staffDetail): Response
    {
        if ($staffDetail !== null) {
            return ReadingDecision::deny(
                503,
                'lector_detenido',
                'El servidor no pudo ponerle la marca de agua a la página, así que no la entrega.',
                detail: $staffDetail,
            )->toResponse();
        }

        return ReadingDecision::deny(
            503,
            'lector_detenido',
            'Ya avisamos al administrador para que lo revise. Intenta de nuevo más tarde.',
        )->toResponse();
    }
}
