<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Subscription extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'book_edition_id',
        'payment_request_id',
        'access_code_id',
        'starts_at',
        'expires_at',
        'status',
        'active_session_token',
    ];

    /**
     * Oculta tokens sensibles de sesión en serializaciones de respuestas.
     *
     * @var list<string>
     */
    protected $hidden = [
        'active_session_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Evalúa si esta instancia de suscripción se encuentra vigente en este momento.
     */
    public function isActive(): bool
    {
        $now = now();

        return $this->status === self::STATUS_ACTIVE
            && $this->starts_at !== null
            && $this->expires_at !== null
            && $this->starts_at->lte($now)
            && $this->expires_at->gt($now);
    }

    /**
     * Usuario titular de la suscripción.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Edición específica a la que aplica la suscripción (o null para suscripción global a todo el catálogo).
     */
    public function bookEdition(): BelongsTo
    {
        return $this->belongsTo(BookEdition::class);
    }

    /**
     * Solicitud de pago P2P que originó la suscripción (si aplica).
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * Código prepago que originó la suscripción (si aplica).
     */
    public function accessCode(): BelongsTo
    {
        return $this->belongsTo(AccessCode::class);
    }

    /**
     * Configuración de auditoría inmutable (spatie/laravel-activitylog).
     *
     * Seguridad: se excluye 'active_session_token' para nunca persistir tokens
     * de sesión en registros de auditoría.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('subscriptions')
            ->logOnly([
                'user_id',
                'book_edition_id',
                'payment_request_id',
                'access_code_id',
                'starts_at',
                'expires_at',
                'status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
