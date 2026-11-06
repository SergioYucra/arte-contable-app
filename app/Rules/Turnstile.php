<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * P5 (H3, A07:2025): valida el token del captcha Cloudflare Turnstile contra la API de Cloudflare.
 *
 * Falla cerrada (A10:2025): sin clave secreta, con la API caída o con una respuesta inesperada,
 * el formulario se rechaza. Un captcha que deja pasar todo cuando falla no protege nada.
 */
class Turnstile implements ValidationRule
{
    public const FIELD = 'cf-turnstile-response';

    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Se ejecuta aunque el campo no llegue: un formulario sin token también se rechaza. */
    public bool $implicit = true;

    public static function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled');
    }

    /**
     * Reglas para mezclar con las de un formulario; vacío si el captcha está desactivado.
     *
     * @return array<string, list<ValidationRule>>
     */
    public static function rules(): array
    {
        return self::enabled() ? [self::FIELD => [new self]] : [];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::enabled()) {
            return;
        }

        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail('Confirma que no eres un robot para continuar.');

            return;
        }

        $secret = config('services.turnstile.secret_key');

        if (empty($secret)) {
            report(new RuntimeException('TURNSTILE_SECRET_KEY no está configurada: el captcha rechaza todos los formularios.'));
            $fail('No pudimos verificar el captcha. Intenta de nuevo en unos minutos.');

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(config('services.turnstile.timeout', 5))
                ->post(self::VERIFY_URL, [
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (Throwable $e) {
            report($e);
            $fail('No pudimos verificar el captcha. Intenta de nuevo en unos minutos.');

            return;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $fail('No pudimos confirmar que no eres un robot. Vuelve a intentarlo.');
        }
    }
}
