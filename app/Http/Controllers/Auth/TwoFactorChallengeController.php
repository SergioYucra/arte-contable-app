<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\SecurityAlert;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * P4 (H1): segundo paso del login para cuentas con 2FA activo.
 *
 * LoginRequest valida la contraseña SIN iniciar sesión y deja en la sesión un "login pendiente"
 * (id de la cuenta, "recordarme" y vencimiento). Aquí se pide el código de la app o un código de
 * recuperación. Solo con el código correcto se inicia la sesión.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'login.two_factor';

    /** Minutos que dura el login pendiente antes de tener que volver a escribir la contraseña. */
    public const PENDING_MINUTES = 5;

    /** Códigos incorrectos permitidos por cuenta antes de exigir de nuevo la contraseña. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'email' => 'La verificación expiró. Vuelve a iniciar sesión.',
            ]);
        }

        $throttleKey = 'two-factor-challenge:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return $this->abandonAfterTooManyAttempts($request, $user, $throttleKey);
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $usedRecoveryCode = false;

        if ($request->filled('code')) {
            $valid = $this->twoFactor->verify($user, (string) $user->two_factor_secret, (string) $request->input('code'));
        } elseif ($request->filled('recovery_code')) {
            $valid = $usedRecoveryCode = $this->twoFactor->useRecoveryCode($user, (string) $request->input('recovery_code'));
        } else {
            $valid = false;
        }

        if (! $valid) {
            RateLimiter::hit($throttleKey, 300);

            activity('security')
                ->performedOn($user)
                ->withProperties(['ip' => $request->ip(), 'method' => $request->filled('recovery_code') ? 'recovery_code' : 'code'])
                ->log('Código de verificación en dos pasos incorrecto.');

            if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
                return $this->abandonAfterTooManyAttempts($request, $user, $throttleKey);
            }

            throw ValidationException::withMessages([
                $request->filled('recovery_code') ? 'recovery_code' : 'code' => 'El código no es válido.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) ($request->session()->get(self::SESSION_KEY)['remember'] ?? false);
        $request->session()->forget(self::SESSION_KEY);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        if ($usedRecoveryCode) {
            $remaining = $this->twoFactor->remainingRecoveryCodes($user);

            activity('security')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['ip' => $request->ip(), 'remaining_codes' => $remaining])
                ->log('Inicio de sesión con un código de recuperación de la verificación en dos pasos.');

            if ($user->requiresTwoFactorAuthentication()) {
                SecurityAlert::send(
                    'Inicio de sesión con código de recuperación',
                    "La cuenta de administración {$user->name} ({$user->email}) entró usando un código de recuperación. Le quedan {$remaining}.",
                    ['IP' => (string) $request->ip()],
                );
            }

            return redirect()->route('two-factor.show')->with('status', 'recovery-code-used');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $user = User::find($pending['id'] ?? null);

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $user;
    }

    private function abandonAfterTooManyAttempts(Request $request, User $user, string $throttleKey): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $minutes = (int) ceil(max(1, RateLimiter::availableIn($throttleKey)) / 60);

        SecurityAlert::send(
            'Varios códigos de verificación incorrectos',
            'Se escribieron '.self::MAX_ATTEMPTS." códigos de verificación en dos pasos incorrectos para la cuenta {$user->name} ({$user->email}). La contraseña de esa cuenta era correcta.",
            ['IP' => (string) $request->ip()],
            dedupeKey: 'two-factor-lockout:'.$user->getKey(),
        );

        return redirect()->route('login')->withErrors([
            'email' => "Demasiados códigos incorrectos. Vuelve a iniciar sesión en {$minutes} minutos.",
        ]);
    }
}
