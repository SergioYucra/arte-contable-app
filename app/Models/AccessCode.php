<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AccessCode extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'reference',
        'subscription_plan_id',
        'duration_days',
        'redeemed_by',
        'redeemed_at',
        'revoked_at',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'redeemed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Genera un código criptográficamente seguro (CSPRNG) con formato legible
     * sin caracteres confusos (omitiendo 0, O, 1, I).
     */
    public static function generateSecureCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $alphaLength = strlen($alphabet);

        do {
            $part1 = '';
            $part2 = '';
            for ($i = 0; $i < 4; $i++) {
                $part1 .= $alphabet[random_int(0, $alphaLength - 1)];
                $part2 .= $alphabet[random_int(0, $alphaLength - 1)];
            }
            $candidate = "AC-{$part1}-{$part2}";
        } while (static::where('code', $candidate)->exists());

        return $candidate;
    }

    protected static function booted(): void
    {
        static::creating(function (AccessCode $accessCode): void {
            if (blank($accessCode->code)) {
                $accessCode->code = static::generateSecureCode();
            }
        });
    }

    /**
     * Indica si el código está disponible para ser canjeado.
     */
    public function isAvailable(): bool
    {
        return $this->redeemed_at === null && $this->revoked_at === null;
    }

    /**
     * Plan asociado a este código (si aplica).
     *
     * Incluye planes archivados: un código canjeado conserva el nombre del plan con el que se creó.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id')->withTrashed();
    }

    /**
     * Usuario que canjeó el código prepago.
     */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    /**
     * Administrador que emitió el código prepago.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Suscripción originada por este código prepago.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Configuración de auditoría inmutable (spatie/laravel-activitylog).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('access_codes')
            ->logOnly([
                'code',
                'reference',
                'subscription_plan_id',
                'duration_days',
                'redeemed_by',
                'redeemed_at',
                'revoked_at',
                'created_by',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
