<?php

namespace App\Modules\LibraryProtection\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pausa de lectura de una cuenta.
 *
 * @property int $id
 * @property int $user_id
 * @property string $reason
 * @property array<string, mixed>|null $details
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $released_by
 * @property Carbon|null $released_at
 */
class ReadingHold extends Model
{
    /** Leyó más rápido de lo que una persona puede leer. */
    public const REASON_VELOCITY = 'velocidad';

    /** Pidió páginas que el lector nunca pide. */
    public const REASON_TRAPS = 'pedidos_trampa';

    protected $fillable = [
        'user_id',
        'reason',
        'details',
        'starts_at',
        'ends_at',
        'released_by',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by')->withTrashed();
    }

    /** Pausas que siguen frenando la lectura en este momento. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->where('ends_at', '>', Carbon::now());
    }

    public function isActive(): bool
    {
        return $this->released_at === null && $this->ends_at->isFuture();
    }

    /** El motivo en palabras de cualquier persona, con los números que lo explican. */
    public function reasonText(): string
    {
        $details = $this->details ?? [];

        return match ($this->reason) {
            self::REASON_VELOCITY => sprintf(
                'Pasó %d páginas en %d minutos, más rápido de lo que una persona puede leer.',
                (int) ($details['pages'] ?? 0),
                (int) ($details['minutes'] ?? 0),
            ),
            self::REASON_TRAPS => sprintf(
                'Pidió %d veces páginas que el lector nunca pide (por ejemplo, páginas que no existen o que no le corresponden). Así trabajan los programas que copian libros.',
                (int) ($details['strikes'] ?? 0),
            ),
            default => 'Actividad fuera de lo normal en el lector.',
        };
    }
}
