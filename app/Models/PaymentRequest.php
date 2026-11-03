<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaymentRequest extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'amount',
        'reference_text',
        'proof_vault_path',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Usuario solicitante del pago.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Administrador que revisó la solicitud.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Suscripción generada al aprobarse esta solicitud.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Configuración de auditoría inmutable (spatie/laravel-activitylog).
     *
     * TODO(security): En el controlador/servicio de carga del comprobante hacia PrivateVault,
     * validar MIME real (magic bytes), tamaño máximo y eliminación de contenido activo (CDR/antivirus).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_requests')
            ->logOnly([
                'user_id',
                'amount',
                'reference_text',
                'proof_vault_path',
                'status',
                'admin_notes',
                'reviewed_by',
                'reviewed_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
