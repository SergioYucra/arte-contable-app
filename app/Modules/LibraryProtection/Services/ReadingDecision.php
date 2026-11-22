<?php

namespace App\Modules\LibraryProtection\Services;

use App\Support\HumanDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Resultado de revisar un pedido de página: se entrega, o se responde con un código que el
 * lector traduce a un aviso en palabras llanas (otro dispositivo, límite de hoy, pausa…).
 *
 * `detail` es el motivo técnico y solo se arma para el personal (SuperAdmin/Admin): así quien
 * administra ve en el mismo lector qué falla, y un estudiante nunca ve datos del servidor.
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
        public readonly ?string $detail = null,
        public readonly ?int $recordedViewId = null,
    ) {}

    /** @param  int|null  $recordedViewId  Registro de lectura creado por este pedido (página nueva hoy). */
    public static function allow(?int $remainingToday, ?int $recordedViewId = null): self
    {
        return new self(true, remainingToday: $remainingToday, recordedViewId: $recordedViewId);
    }

    public static function deny(int $status, string $code, string $message, ?Carbon $retryAt = null, ?string $detail = null): self
    {
        return new self(false, $status, $code, $message, $retryAt, detail: $detail);
    }

    public function toResponse(): JsonResponse
    {
        $body = [
            'code' => $this->code,
            'message' => $this->message,
            'retry_at' => $this->retryAt?->toIso8601String(),
            'retry_at_text' => $this->retryAt ? HumanDate::dateTime($this->retryAt) : null,
        ];

        if ($this->detail !== null) {
            $body['detail'] = $this->detail;
        }

        return response()->json($body, $this->status, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
