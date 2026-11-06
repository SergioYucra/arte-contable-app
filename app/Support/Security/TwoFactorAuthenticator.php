<?php

namespace App\Support\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * P4 (H1, A07:2025): verificación en dos pasos con app de códigos (TOTP, RFC 6238).
 *
 * - Un código vale una sola vez: se guarda el último intervalo usado por cuenta y no se aceptan
 *   códigos de ese intervalo o anteriores (evita reusar un código visto por encima del hombro).
 * - Los códigos de recuperación se guardan como hashes SHA-256 (tienen ~50 bits de azar, no hace
 *   falta bcrypt) y cada uno sirve una vez. En claro solo existen al generarlos.
 */
final class TwoFactorAuthenticator
{
    /** Tolerancia de reloj: el intervalo actual y uno a cada lado (±30 s). */
    public const WINDOW = 1;

    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    /**
     * SVG del código QR para la app de autenticación. Se genera en el servidor: el secreto nunca
     * sale hacia un servicio externo de QR.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUrl($user, $secret));
    }

    /**
     * Comprueba un código de 6 dígitos contra un secreto y lo marca como usado para esa cuenta.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $cacheKey = 'two-factor:last-timestamp:'.$user->getKey();
        $lastTimestamp = Cache::get($cacheKey);

        try {
            $timestamp = $this->engine->verifyKeyNewer($secret, $code, $lastTimestamp, self::WINDOW);
        } catch (Throwable) {
            return false;
        }

        if ($timestamp === false) {
            return false;
        }

        // verifyKeyNewer devuelve el intervalo del código cuando se le pasa uno anterior; si no, true.
        $usedTimestamp = is_int($timestamp) ? $timestamp : $this->engine->getTimestamp();
        Cache::put($cacheKey, $usedTimestamp, now()->addMinutes(5));

        return true;
    }

    /**
     * Genera códigos de recuperación nuevos. Devuelve los códigos en claro (para mostrarlos una vez)
     * y sus hashes (para guardar).
     *
     * @return array{plain: list<string>, hashes: list<string>}
     */
    public function generateRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return [
            'plain' => $plain,
            'hashes' => array_map(self::hashRecoveryCode(...), $plain),
        ];
    }

    /**
     * Usa un código de recuperación: si coincide, lo borra de la cuenta para que no sirva de nuevo.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $candidate = self::hashRecoveryCode($code);
        $stored = $user->two_factor_recovery_codes ?? [];

        foreach ($stored as $index => $hash) {
            if (is_string($hash) && hash_equals($hash, $candidate)) {
                unset($stored[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', Str::lower(trim($code)));
    }
}
