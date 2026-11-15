<?php

namespace App\Modules\TaxRelief\Services;

use App\Modules\TaxRelief\Exceptions\UpstreamUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Único punto de salida hacia boliviaimpuestos.com.
 *
 * Protecciones, en orden:
 *  1. Interruptor general (config tax_relief.enabled).
 *  2. Cortacircuito: tras N fallas seguidas se deja de llamar unos minutos.
 *  3. Tope global de llamadas por minuto y por hora (todos los usuarios juntos).
 *  4. URL fija por configuración (sin SSRF), sin seguir redirecciones,
 *     con tiempo máximo y tamaño máximo de respuesta.
 *
 * Solo devuelve el JSON ya decodificado; la validación del formato y el
 * armado de lo que ve el estudiante están en TaxReliefService.
 */
class BoliviaImpuestosClient
{
    public const PLAN = 'fap.php?action=fap';

    public const BREAKDOWN = 'deudas.php?action=calc';

    public const DUE_DATE = 'deudas.php?action=vencimiento';

    private const BREAKER_FAILURES = 'tax-relief:breaker:failures';

    private const BREAKER_OPEN = 'tax-relief:breaker:open';

    private const BUDGET_MINUTE = 'tax-relief:upstream:minute';

    private const BUDGET_HOUR = 'tax-relief:upstream:hour';

    /** @return array<string, mixed> */
    public function post(string $endpoint, array $body): array
    {
        return $this->send('POST', $endpoint, $body);
    }

    /** @return array<string, mixed> */
    public function get(string $endpoint, array $query): array
    {
        // La opción "query" de Guzzle reemplaza la que trae la URL: se unen aquí
        // para no perder el "?action=..." del endpoint.
        [$path, $fixedQuery] = array_pad(explode('?', $endpoint, 2), 2, '');
        parse_str($fixedQuery, $fixed);

        return $this->send('GET', $path, $fixed + $query);
    }

    /** Una respuesta llegó, pero con un formato que no reconocemos: cuenta como falla. */
    public function reportFormatProblem(string $endpoint, string $problem): void
    {
        Log::error('tax_relief.upstream_format_changed', ['endpoint' => $endpoint, 'problem' => $problem]);
        $this->recordFailure();
    }

    public function isPaused(): bool
    {
        return Cache::has(self::BREAKER_OPEN);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $endpoint, array $data): array
    {
        if (! config('tax_relief.enabled')) {
            throw new UpstreamUnavailable(UpstreamUnavailable::DISABLED);
        }

        // Solo https: los datos de los estudiantes nunca viajan sin cifrar.
        if (parse_url((string) config('tax_relief.base_url'), PHP_URL_SCHEME) !== 'https') {
            Log::error('tax_relief.insecure_base_url');

            throw new UpstreamUnavailable(UpstreamUnavailable::DISABLED, 'TAX_RELIEF_API_URL debe usar https.');
        }

        if ($this->isPaused()) {
            throw new UpstreamUnavailable(UpstreamUnavailable::DOWN, 'Cortacircuito abierto: llamadas en pausa.');
        }

        $this->consumeGlobalBudget();

        $started = microtime(true);

        try {
            $request = Http::baseUrl((string) config('tax_relief.base_url'))
                ->acceptJson()
                ->connectTimeout((int) config('tax_relief.connect_timeout'))
                ->timeout((int) config('tax_relief.timeout'))
                ->withOptions(['allow_redirects' => false]);

            /** @var Response $response */
            $response = $method === 'POST'
                ? $request->asJson()->post($endpoint, $data)
                : $request->get($endpoint, $data);
        } catch (ConnectionException $e) {
            $this->fail($endpoint, 'sin conexión o tiempo agotado', $started);
        } catch (Throwable $e) {
            $this->fail($endpoint, 'error inesperado: '.$e::class, $started);
        }

        if ($response->status() !== 200) {
            $this->fail($endpoint, 'HTTP '.$response->status(), $started);
        }

        $body = $response->body();
        if (strlen($body) > (int) config('tax_relief.max_response_bytes')) {
            $this->fail($endpoint, 'respuesta demasiado grande ('.strlen($body).' bytes)', $started);
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            $this->fail($endpoint, 'la respuesta no es JSON', $started);
        }

        $this->recordSuccess();

        // El servicio respondió bien pero rechazó los datos ("error": "mensaje").
        if (isset($json['error']) && $json['error'] !== false) {
            $detail = is_string($json['error']) ? TaxReliefService::cleanText($json['error']) : null;
            Log::info('tax_relief.upstream_rejected', ['endpoint' => $endpoint, 'detail' => $detail]);

            throw new UpstreamUnavailable(UpstreamUnavailable::REJECTED, 'Datos rechazados por el servicio externo.', $detail);
        }

        return $json;
    }

    private function consumeGlobalBudget(): void
    {
        $perMinute = (int) config('tax_relief.limits.upstream_per_minute');
        $perHour = (int) config('tax_relief.limits.upstream_per_hour');

        if (RateLimiter::tooManyAttempts(self::BUDGET_MINUTE, $perMinute)
            || RateLimiter::tooManyAttempts(self::BUDGET_HOUR, $perHour)) {
            Log::warning('tax_relief.global_budget_reached', ['per_minute' => $perMinute, 'per_hour' => $perHour]);

            throw new UpstreamUnavailable(UpstreamUnavailable::BUSY, 'Tope global de llamadas alcanzado.');
        }

        RateLimiter::hit(self::BUDGET_MINUTE, 60);
        RateLimiter::hit(self::BUDGET_HOUR, 3600);
    }

    private function fail(string $endpoint, string $why, float $started): never
    {
        Log::warning('tax_relief.upstream_failure', [
            'endpoint' => $endpoint,
            'why' => $why,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        $this->recordFailure();

        throw new UpstreamUnavailable(UpstreamUnavailable::DOWN, $why);
    }

    private function recordFailure(): void
    {
        Cache::add(self::BREAKER_FAILURES, 0, 600);
        $failures = (int) Cache::increment(self::BREAKER_FAILURES);

        if ($failures >= (int) config('tax_relief.limits.breaker_failures')) {
            $pause = (int) config('tax_relief.limits.breaker_pause_seconds');
            Cache::put(self::BREAKER_OPEN, true, $pause);
            Cache::forget(self::BREAKER_FAILURES);
            Log::error('tax_relief.breaker_opened', ['pause_seconds' => $pause]);
        }
    }

    private function recordSuccess(): void
    {
        Cache::forget(self::BREAKER_FAILURES);
    }
}
