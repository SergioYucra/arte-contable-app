<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Security\SecurityAlert;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * P4 (H1): activar, confirmar y desactivar la verificación en dos pasos, y regenerar los códigos
 * de recuperación. La página exige haber confirmado la contraseña hace poco (password.confirm) y
 * cada acción lo vuelve a comprobar, porque password.confirm no se puede poner en un POST.
 */
class TwoFactorSettingsController extends Controller
{
    public const PENDING_SECRET_KEY = 'two_factor.pending_secret';

    public const RECOVERY_CODES_FLASH_KEY = 'two_factor.recovery_codes';

    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $pendingSecret = $request->session()->get(self::PENDING_SECRET_KEY);

        return view('profile.two-factor', [
            'user' => $user,
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'required' => $user->requiresTwoFactorAuthentication(),
            'pendingSecret' => $pendingSecret,
            'qrCodeSvg' => $pendingSecret ? $this->twoFactor->qrCodeSvg($user, $pendingSecret) : null,
            'recoveryCodes' => $request->session()->get(self::RECOVERY_CODES_FLASH_KEY),
            'remainingRecoveryCodes' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    /**
     * Paso 1: genera un secreto nuevo. Queda en la sesión hasta que el usuario confirme un código:
     * así nunca hay un 2FA "a medias" guardado en la cuenta.
     */
    public function enable(Request $request): RedirectResponse
    {
        if ($redirect = $this->requirePasswordConfirmation($request)) {
            return $redirect;
        }

        $request->session()->put(self::PENDING_SECRET_KEY, $this->twoFactor->generateSecret());

        return redirect()->route('two-factor.show');
    }

    /**
     * Paso 2: el usuario escanea el QR y escribe un código. Si es correcto, el 2FA queda activo.
     */
    public function confirm(Request $request): RedirectResponse
    {
        if ($redirect = $this->requirePasswordConfirmation($request)) {
            return $redirect;
        }

        $user = $request->user();
        $pendingSecret = $request->session()->get(self::PENDING_SECRET_KEY);

        if (! $pendingSecret) {
            return redirect()->route('two-factor.show');
        }

        $request->validate(['code' => ['required', 'string', 'max:16']]);

        if (! $this->twoFactor->verify($user, $pendingSecret, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'El código no es válido. Revisa que la hora de tu teléfono sea la correcta y escribe el código que se muestra ahora.',
            ]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        DB::transaction(function () use ($user, $pendingSecret, $codes, $request): void {
            $user->forceFill([
                'two_factor_secret' => $pendingSecret,
                'two_factor_recovery_codes' => $codes['hashes'],
                'two_factor_confirmed_at' => now(),
            ])->save();

            activity('security')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('Verificación en dos pasos activada.');
        });

        $request->session()->forget(self::PENDING_SECRET_KEY);

        return redirect()->route('two-factor.show')
            ->with(self::RECOVERY_CODES_FLASH_KEY, $codes['plain'])
            ->with('status', 'two-factor-enabled');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        if ($redirect = $this->requirePasswordConfirmation($request)) {
            return $redirect;
        }

        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('two-factor.show');
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        DB::transaction(function () use ($user, $codes, $request): void {
            $user->forceFill(['two_factor_recovery_codes' => $codes['hashes']])->save();

            activity('security')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('Códigos de recuperación de la verificación en dos pasos regenerados.');
        });

        return redirect()->route('two-factor.show')
            ->with(self::RECOVERY_CODES_FLASH_KEY, $codes['plain'])
            ->with('status', 'recovery-codes-regenerated');
    }

    public function disable(Request $request): RedirectResponse
    {
        if ($redirect = $this->requirePasswordConfirmation($request)) {
            return $redirect;
        }

        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('two-factor.show');
        }

        DB::transaction(function () use ($user, $request): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            activity('security')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('Verificación en dos pasos desactivada.');
        });

        $request->session()->forget(self::PENDING_SECRET_KEY);

        if ($user->requiresTwoFactorAuthentication()) {
            SecurityAlert::send(
                'Verificación en dos pasos desactivada',
                "La cuenta de administración {$user->name} ({$user->email}) desactivó su verificación en dos pasos.",
                ['IP' => (string) $request->ip()],
            );
        }

        return redirect()->route('two-factor.show')->with('status', 'two-factor-disabled');
    }

    /**
     * Misma regla que el middleware password.confirm (auth.password_timeout), para las acciones POST.
     */
    private function requirePasswordConfirmation(Request $request): ?RedirectResponse
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if ((time() - $confirmedAt) > (int) config('auth.password_timeout', 10800)) {
            return redirect()->route('two-factor.show');
        }

        return null;
    }
}
