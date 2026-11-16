<?php

namespace App\Modules\TaxRelief\Exceptions;

use RuntimeException;

/**
 * El cálculo externo no se pudo usar. El motivo decide el mensaje que ve el
 * estudiante; los detalles técnicos solo van al log, nunca a la pantalla.
 */
class UpstreamUnavailable extends RuntimeException
{
    /** La calculadora está apagada por configuración. */
    public const DISABLED = 'disabled';

    /** Se alcanzó el tope global de llamadas hacia el servicio externo. */
    public const BUSY = 'busy';

    /** El servicio externo falla, tarda demasiado o está en pausa (cortacircuito). */
    public const DOWN = 'down';

    /** El servicio externo respondió con un formato que ya no reconocemos. */
    public const FORMAT = 'format';

    /** El servicio externo rechazó los datos (por ejemplo, una fecha sin UFV publicada). */
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $reason,
        string $logMessage = '',
        public readonly ?string $detail = null,
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $reason);
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::DISABLED => 'La calculadora está desactivada por el momento.',
            self::BUSY => 'Mucha gente está usando la calculadora ahora mismo. Intenta de nuevo en un minuto.',
            self::REJECTED => 'No se pudo calcular con esos datos. Revisa las fechas y el monto.',
            default => 'La calculadora no está disponible en este momento. Intenta de nuevo en unos minutos.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::BUSY => 429,
            self::REJECTED => 422,
            default => 503,
        };
    }
}
