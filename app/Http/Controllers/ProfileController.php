<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
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

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
