<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1 (H4, A02:2025): cabeceras de seguridad en todas las respuestas.
 *
 * - X-Frame-Options y CSP frame-ancestors: ninguna otra página puede mostrar la app dentro de un
 *   marco (clickjacking sobre el panel de usuarios).
 * - Content-Security-Policy: los scripts solo pueden venir de la propia app, de Cloudflare Turnstile
 *   o llevar el nonce de esta respuesta. 'unsafe-eval' sigue porque Alpine evalúa sus expresiones;
 *   aun así, un <script> inyectado en el HTML no corre. 'unsafe-inline' en estilos lo necesitan los
 *   atributos style que ponen Alpine y Vue.
 * - Strict-Transport-Security solo en producción y sobre HTTPS (P3).
 */
class SecurityHeaders
{
    public const TURNSTILE_ORIGIN = 'https://challenges.cloudflare.com';

    public function handle(Request $request, Closure $next): Response
    {
        // El nonce debe existir antes de renderizar las vistas: @vite y los <script> en línea lo usan.
        Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        // Una respuesta que ya fijó sus propias cabeceras (p. ej. PrivateVault::streamInline, que permite
        // mostrar un PDF dentro de la propia app con SAMEORIGIN) las conserva.
        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        }

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $nonce = Vite::cspNonce();
        $dev = $this->viteDevServerSources();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", "'unsafe-eval'", self::TURNSTILE_ORIGIN, ...$dev['http']],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev['http']],
            'img-src' => ["'self'", 'data:', 'blob:', ...$dev['http']],
            'font-src' => ["'self'", 'data:', ...$dev['http']],
            'connect-src' => ["'self'", self::TURNSTILE_ORIGIN, ...$dev['http'], ...$dev['ws']],
            'frame-src' => [self::TURNSTILE_ORIGIN],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        $policy = collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');

        if (app()->isProduction() && $request->isSecure()) {
            $policy .= '; upgrade-insecure-requests';
        }

        return $policy;
    }

    /**
     * En desarrollo con `npm run dev`, el servidor de Vite sirve los assets y la recarga en vivo.
     *
     * @return array{http: list<string>, ws: list<string>}
     */
    private function viteDevServerSources(): array
    {
        if (! Vite::isRunningHot()) {
            return ['http' => [], 'ws' => []];
        }

        $origin = rtrim((string) file_get_contents(Vite::hotFile()));
        $parts = parse_url($origin);

        if (! is_array($parts) || empty($parts['host'])) {
            return ['http' => [], 'ws' => []];
        }

        $hostPort = (str_contains($parts['host'], ':') ? '['.trim($parts['host'], '[]').']' : $parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
        $scheme = $parts['scheme'] ?? 'http';

        return [
            'http' => ["{$scheme}://{$hostPort}"],
            'ws' => [($scheme === 'https' ? 'wss' : 'ws')."://{$hostPort}"],
        ];
    }
}
