<?php

namespace App\Modules\LibraryProtection\Services;

use App\Models\User;
use App\Modules\LibraryProtection\Models\ReaderCode;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Código personal de cada lector ("K7QM-3XPA"), impreso en todas sus páginas.
 *
 * Es aleatorio (no se deduce del correo ni del ID) y no usa caracteres que se confunden
 * en una captura borrosa (0/O, 1/I/L).
 */
final class ReaderCodes
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function for(User $user): string
    {
        $existing = ReaderCode::where('user_id', $user->id)->value('code');
        if (is_string($existing)) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return ReaderCode::createOrFirst(
                    ['user_id' => $user->id],
                    ['code' => $this->generate()],
                )->code;
            } catch (UniqueConstraintViolationException) {
                // Otro usuario ya tiene ese código (muy improbable): se intenta con otro.
            }
        }

        throw new RuntimeException('No se pudo generar un código de lector único.');
    }

    /** Busca al dueño de un código escrito a mano (acepta minúsculas, espacios y sin guion). */
    public function find(string $typed): ?ReaderCode
    {
        $normalized = self::normalize($typed);

        return $normalized === null
            ? null
            : ReaderCode::with('user')->where('code', $normalized)->first();
    }

    public static function normalize(string $typed): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));

        if (! preg_match('/^['.self::ALPHABET.']{8}$/', $clean)) {
            return null;
        }

        return substr($clean, 0, 4).'-'.substr($clean, 4, 4);
    }

    private function generate(): string
    {
        $chars = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, $max)];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4, 4);
    }
}
