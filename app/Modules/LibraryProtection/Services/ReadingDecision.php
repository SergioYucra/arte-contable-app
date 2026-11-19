<?php

namespace App\Modules\LibraryProtection\Services;

use App\Support\HumanDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Resultado de revisar un pedido de página: se entrega, o se responde con un código que el
 * lector traduce a un aviso en palabras llanas (otro dispositivo, límite de hoy, pausa…).
 */
final class ReadingDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly int $status = 200,
        public readonly string $code = '',
        public readonly string $message = '',
        public readonly ?Carbon $retryAt = null,
        public readonly ?int $remainingToday = null,
    ) {}

    public static function allow(?int $remainingToday): self
    {
        return new self(true, remainingToday: $remainingToday);
    }

    public static function deny(int $status, string $code, string $message, ?Carbon $retryAt = null): self
    {
        return new self(false, $status, $code, $message, $retryAt);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'code' => $this->code,
            'message' => $this->message,
            'retry_at' => $this->retryAt?->toIso8601String(),
            'retry_at_text' => $this->retryAt ? HumanDate::dateTime($this->retryAt) : null,
        ], $this->status, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
