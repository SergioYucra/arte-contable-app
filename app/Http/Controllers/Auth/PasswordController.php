<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $validated, $request): void {
            $user->update([
                'password' => Hash::make($validated['password']),
            ]);

            // V3: cambiar la contraseña cierra las demás sesiones abiertas de la cuenta (la actual sigue:
            // authenticateSessions() guarda el hash nuevo en esta sesión al terminar la petición).
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();

            // P7 (A09): el cambio de contraseña queda en la bitácora de seguridad.
            activity('security')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('Contraseña cambiada desde el perfil; se cerraron las demás sesiones.');
        });

        return back()->with('status', 'password-updated');
    }
}
