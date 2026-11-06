<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Notifications\EmailChangedNotification;
use App\Policies\UserPolicy;
use App\Support\Security\SecurityAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /** V4: días que sirve el enlace "no fui yo" que recibe el correo anterior. */
    public const EMAIL_REVERT_LINK_DAYS = 7;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $newEmail = $request->validated('email');
        $oldEmail = $user->email;
        $oldEmailWasVerified = $user->hasVerifiedEmail();

        // N3: Limitación de tasa (3 solicitudes diarias por cuenta) y registro en bitácora
        if ($newEmail !== $oldEmail) {
            $throttleKey = 'email-change:'.$user->id;

            if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
                $seconds = max(1, RateLimiter::availableIn($throttleKey));
                $hours = ceil($seconds / 3600);

                // P9: la bitácora es inmutable; solo se registra el primer bloqueo de cada ventana.
                if (Cache::add($throttleKey.':blocked-logged', true, $seconds)) {
                    activity('security')
                        ->causedBy($user)
                        ->withProperties([
                            'attempted_email' => $newEmail,
                            'ip' => $request->ip(),
                        ])
                        ->log('Intento de cambio de correo bloqueado por exceder el límite diario (máximo 3 por día).');
                }

                throw ValidationException::withMessages([
                    'email' => __("Has alcanzado el límite máximo de 3 solicitudes de cambio de correo por día. Intenta de nuevo en {$hours} horas."),
                ]);
            }

            RateLimiter::hit($throttleKey, 86400);

            activity('users')
                ->causedBy($user)
                ->withProperties([
                    'old_email' => $oldEmail,
                    'new_email' => $newEmail,
                    'ip' => $request->ip(),
                ])
                ->log("Solicitud de cambio de correo de '{$oldEmail}' a '{$newEmail}'");
        }

        $user->fill($request->safe()->only(['name', 'email']));

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();

            // V4: el correo anterior recibe un aviso con un enlace para revertir el cambio. Solo si estaba
            // verificado: un correo nunca confirmado puede no ser del dueño de la cuenta.
            if ($oldEmailWasVerified) {
                $revertUrl = URL::temporarySignedRoute(
                    'email.revert',
                    now()->addDays(self::EMAIL_REVERT_LINK_DAYS),
                    ['user' => $user->id, 'email' => $oldEmail],
                );

                Notification::route('mail', $oldEmail)
                    ->notify(new EmailChangedNotification($user->name, $newEmail, $revertUrl, self::EMAIL_REVERT_LINK_DAYS));
            }

            if ($user->hasAnyRole(UserPolicy::SUPERADMIN_ONLY_ROLES)) {
                SecurityAlert::send(
                    'Cambio de correo de una cuenta de administración',
                    "La cuenta {$user->name} cambió su correo de {$oldEmail} a {$newEmail}.",
                    ['IP' => (string) $request->ip()],
                );
            }
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
