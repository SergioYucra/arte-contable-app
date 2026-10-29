<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }

    /**
     * Update the unverified user's email address and resend verification email.
     */
    public function update(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $user = $request->user();

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        $newEmail = $validated['email'];
        $oldEmail = $user->email;

        // N3: Limitación de tasa (3 solicitudes diarias por cuenta) y registro en bitácora
        if ($newEmail !== $oldEmail) {
            $throttleKey = 'email-change:'.$user->id;

            if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                $hours = ceil($seconds / 3600);

                activity('security')
                    ->causedBy($user)
                    ->withProperties([
                        'attempted_email' => $newEmail,
                        'ip' => $request->ip(),
                    ])
                    ->log("Intento de cambio de correo bloqueado por exceder el límite diario (máximo 3 por día).");

                return back()->withErrors([
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
                ->log("Actualización de correo de usuario no verificado de '{$oldEmail}' a '{$newEmail}'");
        }

        $user->forceFill([
            'email' => $newEmail,
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'email-updated');
    }
}
