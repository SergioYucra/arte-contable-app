<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
            'has_password' => 'boolean',
        ];
    }

    /**
     * Eventos de ciclo de vida del modelo.
     *
     * Al desactivar (SoftDelete) un usuario, se revoca cualquier token de sesión
     * única de lectura activo en sus suscripciones.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $user->subscriptions()
                ->whereNotNull('active_session_token')
                ->update(['active_session_token' => null]);
        });
    }

    /**
     * Configuración de auditoría (spatie/laravel-activitylog).
     *
     * Solo se registran atributos NO sensibles. Nunca agregar 'password'
     * ni 'remember_token' a esta lista.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'email_verified_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Suscripciones asociadas al usuario.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Solicitudes de pago P2P realizadas por el usuario.
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * Códigos prepago canjeados por el usuario.
     */
    public function redeemedAccessCodes(): HasMany
    {
        return $this->hasMany(AccessCode::class, 'redeemed_by');
    }

    /**
     * Códigos prepago emitidos por el usuario (administrador).
     */
    public function createdAccessCodes(): HasMany
    {
        return $this->hasMany(AccessCode::class, 'created_by');
    }

    /**
     * Estados de progreso de lectura por edición.
     */
    public function readingStates(): HasMany
    {
        return $this->hasMany(ReadingState::class);
    }

    /**
     * Notas del cuaderno de estudio del lector.
     */
    public function studyNotes(): HasMany
    {
        return $this->hasMany(StudyNote::class);
    }

    /**
     * Determina si el usuario cuenta con al menos una suscripción vigente y no revocada.
     *
     * Si la relación 'subscriptions' ya está cargada en memoria, evalúa la colección
     * para evitar consultas N+1 en listados administrativos.
     */
    public function hasActiveSubscription(?int $editionId = null): bool
    {
        if ($this->relationLoaded('subscriptions')) {
            return $this->subscriptions->contains(
                fn (Subscription $subscription): bool => $subscription->isActive()
                    && ($editionId === null
                        || $subscription->book_edition_id === null
                        || (int) $subscription->book_edition_id === $editionId)
            );
        }

        $now = now();

        return $this->subscriptions()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->when($editionId !== null, fn ($query) => $query->where(
                fn ($scoped) => $scoped->whereNull('book_edition_id')->orWhere('book_edition_id', $editionId)
            ))
            ->exists();
    }

    /**
     * Candado del Cuaderno de Estudio: solo staff (SuperAdmin/Admin) o miembros con días vigentes.
     *
     * Se mantiene separado de hasActiveSubscription() para no alterar el estado de membresía
     * mostrado en dashboard/listados (un Admin sin suscripción no es "miembro activo").
     */
    public function canAccessStudyNotes(int $editionId): bool
    {
        return $this->hasAnyRole('SuperAdmin', 'Admin')
            || $this->hasActiveSubscription($editionId);
    }

    /**
     * Estado consolidado de suscripción del usuario: 'free', 'active' o 'expired'.
     */
    public function getSubscriptionStatusAttribute(): string
    {
        if ($this->hasActiveSubscription()) {
            return 'active';
        }

        $hasAnySubscription = $this->relationLoaded('subscriptions')
            ? $this->subscriptions->isNotEmpty()
            : $this->subscriptions()->exists();

        if (! $hasAnySubscription) {
            return 'free';
        }

        return 'expired';
    }

    /**
     * Indica si la cuenta tiene una contraseña local configurada por el usuario.
     */
    public function hasPassword(): bool
    {
        return (bool) ($this->has_password ?? true) && filled($this->password);
    }

    /**
     * Autoriza de forma fail-safe la lectura de una página específica de una edición.
     */
    public function canReadEditionPage(BookEdition $edition, int $page): bool
    {
        if ($page < 1) {
            return false;
        }

        if ($edition->total_pages > 0 && $page > $edition->total_pages) {
            return false;
        }

        if ($this->hasAnyRole('SuperAdmin', 'Admin')) {
            return true;
        }

        if ($page <= (int) $edition->sample_pages_count) {
            return true;
        }

        // Con el ID de la edición: una suscripción de un solo libro no abre los demás.
        return $this->hasActiveSubscription((int) $edition->id);
    }
}
