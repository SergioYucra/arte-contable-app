<?php

namespace App\Modules\LibraryProtection\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Lo que se imprime en cada página: nombre, correo a medias, código personal y fecha.
 *
 * El correo va a medias ("j***z@gmail.com") porque una página filtrada circula en público;
 * el código identifica a la persona sin exponer su correo completo.
 */
final class WatermarkStamp
{
    public function __construct(
        public readonly string $name,
        public readonly string $maskedEmail,
        public readonly string $code,
        public readonly string $date,
    ) {}

    public static function for(User $user, string $code, ?Carbon $at = null): self
    {
        $timezone = (string) config('library.protection.timezone', 'America/La_Paz');

        return new self(
            name: self::cleanName((string) $user->name),
            maskedEmail: self::maskEmail((string) $user->email),
            code: $code,
            date: ($at ?? Carbon::now())->copy()->setTimezone($timezone)->format('d/m/Y'),
        );
    }

    public function diagonalText(): string
    {
        return "{$this->name} · {$this->maskedEmail} · {$this->date} · {$this->code}";
    }

    public function footerText(): string
    {
        return "Copia personal de {$this->name} · Código {$this->code} · {$this->date} · Prohibido compartir";
    }

    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = mb_strimwidth(substr($email, $at + 1), 0, 40, '');
        $first = mb_substr($local, 0, 1);
        $last = mb_strlen($local) >= 3 ? mb_substr($local, -1) : '';

        return "{$first}***{$last}@{$domain}";
    }

    private static function cleanName(string $name): string
    {
        $clean = (string) preg_replace('/[\p{C}]+/u', ' ', $name);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return $clean === '' ? 'Lector' : mb_strimwidth($clean, 0, 48, '…');
    }
}
