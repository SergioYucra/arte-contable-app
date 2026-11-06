<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * P14 (A02:2025): proxies de confianza leídos de TRUSTED_PROXIES (config/app.php).
 *
 * Sin proxy (Hostinger sirve directo), se deja vacío y Laravel usa la IP real de la conexión.
 * Detrás de un CDN o proxy, se listan sus IPs o rangos separados por coma; así los límites por IP
 * y la bitácora registran la IP del visitante y no la del proxy. '*' confía en cualquier origen:
 * úsalo solo si la app NO se puede abrir directo, sin pasar por el proxy.
 *
 * Se lee en cada petición (y no en bootstrap/app.php) para que funcione con config:cache.
 */
class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return parent::proxies();
        }

        if ($configured === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
