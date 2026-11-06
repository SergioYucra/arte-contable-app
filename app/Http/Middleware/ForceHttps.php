<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3 (H5): en producción toda petición por http:// se redirige a https://, para que la cookie de
 * sesión nunca viaje sin cifrar. Se controla con FORCE_HTTPS (por defecto activo en producción).
 *
 * Detrás de un proxy o CDN que termina el HTTPS (por ejemplo Cloudflare en modo "Flexible"), hay
 * que configurar TRUSTED_PROXIES; si no, Laravel cree que toda petición llega por http y redirige
 * en bucle.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https') && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
