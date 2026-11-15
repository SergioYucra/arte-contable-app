<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
        'special_price',
        'special_label',
        'special_starts_at',
        'special_ends_at',
        'special_qr_image_path',
    ];

    public const SPECIAL_NONE = 'none';

    public const SPECIAL_SCHEDULED = 'scheduled';

    public const SPECIAL_ACTIVE = 'active';

    public const SPECIAL_ENDED = 'ended';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'special_price' => 'decimal:2',
            'special_starts_at' => 'datetime',
            'special_ends_at' => 'datetime',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Estado del precio especial en un momento dado: sin precio especial, programado, vigente o terminado.
     */
    public function specialPriceStatus(?CarbonInterface $at = null): string
    {
        if ($this->special_price === null || $this->special_ends_at === null) {
            return self::SPECIAL_NONE;
        }

        $at ??= now();

        if ($this->special_starts_at !== null && $at->lt($this->special_starts_at)) {
            return self::SPECIAL_SCHEDULED;
        }

        return $at->lt($this->special_ends_at) ? self::SPECIAL_ACTIVE : self::SPECIAL_ENDED;
    }

    public function hasActiveSpecialPrice(?CarbonInterface $at = null): bool
    {
        return $this->specialPriceStatus($at) === self::SPECIAL_ACTIVE;
    }

    /**
     * Precio que se cobra ahora: el especial mientras está vigente, si no el normal.
     */
    public function currentPrice(?CarbonInterface $at = null): float
    {
        return $this->hasActiveSpecialPrice($at) ? (float) $this->special_price : (float) $this->price;
    }

    /**
     * QR para pagar ahora. Con precio especial vigente se usa solo su QR: el normal lleva otro monto,
     * así que si falta se devuelve null en vez de mostrar un QR con el precio equivocado.
     */
    public function currentQrImageUrl(?CarbonInterface $at = null): ?string
    {
        return $this->hasActiveSpecialPrice($at) ? $this->special_qr_image_url : $this->qr_image_url;
    }

    /**
     * URL pública del QR del precio especial.
     */
    public function getSpecialQrImageUrlAttribute(): ?string
    {
        return blank($this->special_qr_image_path) ? null : Storage::disk('public')->url($this->special_qr_image_path);
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
                'special_price',
                'special_label',
                'special_starts_at',
                'special_ends_at',
                'special_qr_image_path',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
