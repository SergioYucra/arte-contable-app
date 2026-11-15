<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un acceso otorgado: los días que sumó un pago aprobado o un código canjeado.
 *
 * Es un registro histórico. Se crea desde MembershipService y no se edita.
 */
class SubscriptionGrant extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_CODE = 'code';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'subscription_id',
        'subscription_plan_id',
        'payment_request_id',
        'access_code_id',
        'days',
        'previous_expires_at',
        'new_expires_at',
        'granted_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'previous_expires_at' => 'datetime',
            'new_expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * De dónde salieron los días: de un pago aprobado o de un código canjeado.
     */
    public function source(): string
    {
        return $this->access_code_id !== null && $this->payment_request_id === null
            ? self::SOURCE_CODE
            : self::SOURCE_PAYMENT;
    }

    /**
     * Persona que recibió el acceso.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Suscripción que este otorgamiento creó o extendió.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Plan asociado, aunque después haya sido archivado.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id')->withTrashed();
    }

    /**
     * Pago aprobado que originó el acceso (si aplica).
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * Código canjeado que originó el acceso (si aplica).
     */
    public function accessCode(): BelongsTo
    {
        return $this->belongsTo(AccessCode::class);
    }

    /**
     * Quien lo otorgó: el administrador que aprobó el pago o el que emitió el código.
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
