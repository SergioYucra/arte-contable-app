<?php

namespace App\Modules\Calculators\Services;

use App\Modules\Calculators\Exceptions\SourceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Descarga las páginas de boliviaimpuestos.com y ubica el <script> que calcula.
 *
 * Seguridad: solo https y solo boliviaimpuestos.com (la URL viene de config, no de
 * afuera), sin seguir redirecciones, con tiempo y tamaño máximos. Su JavaScript
 * NUNCA se ejecuta: FormulaReader solo lee números y tablas con expresiones regulares.
 */
class BoliviaImpuestosPages
{
    private const HOST = 'boliviaimpuestos.com';

    public function fetch(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== self::HOST) {
            throw new SourceUnavailable('la dirección configurada no es una página https de '.self::HOST);
        }

        $timeout = (int) config('calculators.monitor.timeout_seconds', 10);
        $maxBytes = (int) config('calculators.monitor.max_bytes', 1_000_000);
        $tooBig = false;

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                // Corta la descarga apenas pasa del tope, sin cargar todo en memoria.
                'progress' => function ($expected, $received) use ($maxBytes, &$tooBig): void {
                    if ($expected > $maxBytes || $received > $maxBytes) {
                        $tooBig = true;
                        throw new RuntimeException('Respuesta demasiado grande');
                    }
                },
            ])
                ->timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RevisionCalculadoras/1.0)',
                    'Accept' => 'text/html',
                ])
                ->get($url);
        } catch (Throwable $e) {
            if ($tooBig) {
                throw new SourceUnavailable('la página pesa mucho más de lo normal (más de '.$maxBytes.' bytes)');
            }
            if ($e instanceof ConnectionException) {
                throw new SourceUnavailable("no respondió (sin conexión o tardó más de {$timeout} segundos)");
            }
            throw $e;
        }

        $status = $response->status();
        if ($status >= 300 && $status < 400) {
            throw new SourceUnavailable("redirigió a otra dirección (código {$status})");
        }
        if ($this->isCloudflareBlock($response)) {
            throw new SourceUnavailable("Cloudflare bloqueó la revisión automática (código {$status})");
        }
        if ($status !== 200) {
            throw new SourceUnavailable("respondió con un error (código {$status})");
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new SourceUnavailable('la página pesa mucho más de lo normal ('.strlen($body).' bytes)');
        }

        return $body;
    }

    /** El primer <script> de la página que contiene el marcador, o null si no hay. */
    public static function calculationScript(string $html, string $marker): ?string
    {
        if (! preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $script) {
            if (str_contains($script, $marker)) {
                return $script;
            }
        }

        return null;
    }

    /** Huella del script: cambia si cambia cualquier cosa del código, salvo espacios y saltos de línea. */
    public static function fingerprint(string $script): string
    {
        return hash('sha256', (string) preg_replace('/\s+/', '', $script));
    }

    /**
     * Un bloqueo de Cloudflare (reto o regla contra robots), no una caída: su sitio está
     * detrás de Cloudflare, así que una caída también llega con sus encabezados.
     */
    private function isCloudflareBlock(Response $response): bool
    {
        $status = $response->status();
        if (! in_array($status, [403, 429, 503], true)) {
            return false;
        }

        $body = $response->body();
        $challenge = str_contains($body, 'cf-chl') || str_contains($body, 'Just a moment') || str_contains($body, 'Attention Required');
        if ($challenge) {
            return true;
        }

        $behindCloudflare = strtolower($response->header('Server')) === 'cloudflare' || $response->header('cf-ray') !== '';

        return $behindCloudflare && in_array($status, [403, 429], true);
    }
}
