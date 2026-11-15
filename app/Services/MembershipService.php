<?php

namespace App\Services;

use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionGrant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Única fuente de la regla de vigencia de la membresía.
 *
 * Regla: si la persona tiene acceso vigente, los días nuevos se suman a su vencimiento;
 * si no lo tiene (nunca tuvo, venció o se le retiró), el acceso empieza hoy. No hay periodo de gracia.
 *
 * Cada otorgamiento deja una fila en `subscription_grants`.
 */
class MembershipService
{
    /**
     * Otorga los días del plan de un pago aprobado.
     */
    public function grantFromPayment(PaymentRequest $payment, User $reviewer): SubscriptionGrant
    {
        $payment->loadMissing(['user', 'plan']);

        if ($payment->user === null || $payment->plan === null) {
            throw new InvalidArgumentException('El pago necesita una persona y un plan para otorgar acceso.');
        }

        return $this->extend(
            $payment->user,
            (int) $payment->plan->duration_days,
            $payment->plan->id,
            ['payment_request_id' => $payment->id],
            $reviewer->id,
        );
    }

    /**
     * Otorga los días de un código a quien lo canjea.
     *
     * Quien lo llama debe tener el código bloqueado dentro de su transacción.
     */
    public function grantFromCode(AccessCode $code, User $redeemer): SubscriptionGrant
    {
        return $this->extend(
            $redeemer,
            (int) $code->duration_days,
            $code->subscription_plan_id,
            ['access_code_id' => $code->id],
            $code->created_by,
        );
    }

    /**
     * Fecha hasta la que quedaría el acceso de la persona si hoy se le sumaran estos días.
     */
    public function previewExpiry(User $user, int $days): Carbon
    {
        $current = $this->currentSubscription($user);
        $base = $current !== null ? $current->expires_at->copy() : Carbon::now();

        return $base->addDays($days);
    }

    /**
     * Suscripción vigente de la persona, o null si no tiene acceso ahora.
     *
     * Si la relación `subscriptions` ya está cargada la usa, para no consultar por cada fila de un listado.
     */
    public function currentSubscription(User $user): ?Subscription
    {
        $now = Carbon::now();

        if ($user->relationLoaded('subscriptions')) {
            return $user->subscriptions
                ->filter(fn (Subscription $s): bool => $s->status === Subscription::STATUS_ACTIVE
                    && $s->expires_at !== null
                    && $s->expires_at->gt($now))
                ->sortByDesc('expires_at')
                ->first();
        }

        return Subscription::where('user_id', $user->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', $now)
            ->latest('expires_at')
            ->first();
    }

    /**
     * Retira el acceso vigente de una persona. Conserva su avance, notas e historial.
     *
     * @throws ValidationException si la persona no tiene acceso vigente
     */
    public function revoke(User $user, User $by, string $reason): Subscription
    {
        return DB::transaction(function () use ($user, $by, $reason): Subscription {
            $active = Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where('expires_at', '>', Carbon::now())
                ->latest('expires_at')
                ->get();

            if ($active->isEmpty()) {
                throw ValidationException::withMessages([
                    'user' => 'Esta persona no tiene un acceso vigente que quitar.',
                ]);
            }

            foreach ($active as $subscription) {
                $subscription->update(['status' => Subscription::STATUS_REVOKED]);
            }

            /** @var Subscription $latest */
            $latest = $active->first();

            activity('subscriptions')
                ->causedBy($by)
                ->performedOn($latest)
                ->withProperties([
                    'user_id' => $user->id,
                    'reason' => $reason,
                    'expires_at' => $latest->expires_at?->toIso8601String(),
                ])
                ->log("Acceso de '{$user->name}' retirado");

            return $latest;
        });
    }

    /**
     * @param  array<string, int>  $source  Columna de origen y su id: payment_request_id o access_code_id
     */
    private function extend(User $user, int $days, ?int $planId, array $source, ?int $grantedBy): SubscriptionGrant
    {
        if ($days < 1) {
            throw new InvalidArgumentException('Los días a otorgar deben ser al menos 1.');
        }

        return DB::transaction(function () use ($user, $days, $planId, $source, $grantedBy): SubscriptionGrant {
            $now = Carbon::now();

            $current = Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where('expires_at', '>', $now)
                ->latest('expires_at')
                ->first();

            $previousExpiresAt = $current?->expires_at?->copy();
            $expiresAt = ($previousExpiresAt?->copy() ?? $now->copy())->addDays($days);

            if ($current !== null) {
                $current->update($source + [
                    'subscription_plan_id' => $planId ?: $current->subscription_plan_id,
                    'expires_at' => $expiresAt,
                ]);

                $subscription = $current;
            } else {
                // $source va a la izquierda: en PHP el `+` conserva las claves del primer arreglo.
                $subscription = Subscription::create($source + [
                    'user_id' => $user->id,
                    'book_edition_id' => null,
                    'subscription_plan_id' => $planId,
                    'payment_request_id' => null,
                    'access_code_id' => null,
                    'starts_at' => $now,
                    'expires_at' => $expiresAt,
                    'status' => Subscription::STATUS_ACTIVE,
                ]);
            }

            return SubscriptionGrant::create($source + [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'subscription_plan_id' => $planId,
                'days' => $days,
                'previous_expires_at' => $previousExpiresAt,
                'new_expires_at' => $expiresAt,
                'granted_by' => $grantedBy,
            ]);
        });
    }
}
