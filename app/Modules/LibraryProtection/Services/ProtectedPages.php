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
        // Falla cerrado antes de registrar nada: sin marca de agua no sale ninguna página.
        $unavailable = $this->watermarker->unavailableReason();
        if ($unavailable !== null) {
            $this->alerts->watermarkUnavailable($unavailable);

            return $this->stopped();
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

        if (! $disk->exists($path) || $disk->mimeType($path) !== 'image/webp') {
            return ReadingDecision::deny(404, 'no_disponible', 'Esta página todavía no está lista. Intenta de nuevo más tarde.')->toResponse();
        }

        try {
            $bytes = $this->watermarker->stamp(
                (string) $disk->get($path),
                WatermarkStamp::for($user, $this->codes->for($user)),
            );
        } catch (WatermarkUnavailable $e) {
            report($e);

            return $this->stopped();
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

    private function stopped(): Response
    {
        return ReadingDecision::deny(
            503,
            'lector_detenido',
            'Ya avisamos al administrador para que lo revise. Intenta de nuevo más tarde.',
        )->toResponse();
    }
}
