<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SubscriptionPlan extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'price',
        'duration_days',
        'qr_image_path',
        'description',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Solicitudes de pago asociadas a este plan.
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * Suscripciones activas o concluidas generadas bajo este plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Códigos de acceso prepago generados bajo este plan.
     */
    public function accessCodes(): HasMany
    {
        return $this->hasMany(AccessCode::class);
    }

    /**
     * URL pública de la imagen del código QR bancario.
     */
    public function getQrImageUrlAttribute(): ?string
    {
        if (blank($this->qr_image_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->qr_image_path);
    }

    /**
     * Configuración de auditoría inmutable (spatie/laravel-activitylog).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('subscription_plans')
            ->logOnly([
                'name',
                'price',
                'duration_days',
                'qr_image_path',
                'description',
                'is_active',
                'sort_order',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
