<?php

namespace App\Modules\LibraryProtection\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Lo que se imprime en cada página: nombre, código personal y fecha.
 *
 * El correo no se imprime: una página filtrada circula en público, y el código ya identifica
 * a la persona (el administrador lo busca en el panel).
 */
final class WatermarkStamp
{
    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly string $date,
    ) {}

    public static function for(User $user, string $code, ?Carbon $at = null): self
    {
        $timezone = (string) config('library.protection.timezone', 'America/La_Paz');

        return new self(
            name: self::cleanName((string) $user->name),
            code: $code,
            date: ($at ?? Carbon::now())->copy()->setTimezone($timezone)->format('d/m/Y'),
        );
    }

    public function centerText(): string
    {
        return "Código: {$this->code}";
    }

    public function footerText(): string
    {
        return "Copia personal de {$this->name} · Código {$this->code} · {$this->date} · Prohibido compartir";
    }

    private static function cleanName(string $name): string
    {
        $clean = (string) preg_replace('/[\p{C}]+/u', ' ', $name);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return $clean === '' ? 'Lector' : mb_strimwidth($clean, 0, 48, '…');
    }
}
