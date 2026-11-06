<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\SecurityAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * V4: "No fui yo". El correo anterior de una cuenta recibe un enlace firmado (ProfileController) que
 * devuelve la cuenta a esa dirección. Quien abre el enlace demuestra que controla el correo original,
 * así que la recuperación es completa: se cierran todas las sesiones, se invalida la contraseña y el
 * 2FA (pudo configurarlos quien cambió el correo) y se envía un enlace para elegir contraseña nueva.
 *
 * No exige sesión: el dueño legítimo normalmente ya no puede entrar a su cuenta.
 */
class EmailRevertController extends Controller
{
    public function __invoke(Request $request, int $user): RedirectResponse
    {
        $account = User::find($user);
        $originalEmail = (string) $request->query('email', '');

        if (! $account || ! filter_var($originalEmail, FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('login')->withErrors([
                'email' => 'El enlace no es válido. Si necesitas ayuda, comunícate con el administrador del sistema.',
            ]);
        }

        if ($account->email === $originalEmail) {
            return redirect()->route('login')->with('status', 'Este enlace ya se usó: la cuenta ya tiene ese correo.');
        }

        $takenByAnotherAccount = User::withTrashed()
            ->where('email', $originalEmail)
            ->whereKeyNot($account->getKey())
            ->exists();

        if ($takenByAnotherAccount) {
            return redirect()->route('login')->withErrors([
                'email' => 'No pudimos recuperar el correo porque ahora lo usa otra cuenta. Comunícate con el administrador del sistema.',
            ]);
        }

        $replacedEmail = $account->email;

        DB::transaction(function () use ($account, $originalEmail, $replacedEmail, $request): void {
            $account->forceFill([
                'email' => $originalEmail,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'remember_token' => Str::random(60),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $account->getKey())
                ->delete();

            activity('security')
                ->performedOn($account)
                ->withProperties([
                    'restored_email' => $originalEmail,
                    'replaced_email' => $replacedEmail,
                    'ip' => $request->ip(),
                ])
                ->log("Correo de la cuenta revertido a '{$originalEmail}' desde el enlace \"No fui yo\"");
        });

        // Si este navegador tenía abierta una sesión de esa cuenta, también se cierra.
        if (Auth::id() === $account->getKey()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Password::sendResetLink(['email' => $originalEmail]);

        SecurityAlert::send(
            'Correo de una cuenta revertido',
            "La cuenta {$account->name} volvió a su correo original {$originalEmail} usando el enlace \"No fui yo\". Antes tenía {$replacedEmail}.",
            ['IP' => (string) $request->ip()],
        );

        return redirect()->route('login')->with(
            'status',
            'Recuperamos el correo de tu cuenta y cerramos todas las sesiones. Te enviamos un enlace para que elijas una contraseña nueva.',
        );
    }
}
