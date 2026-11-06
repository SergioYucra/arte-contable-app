<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $pendingTwoFactorUser = $request->authenticate();

        $request->session()->regenerate();

        // P4: contraseña correcta y 2FA activo: la sesión todavía no se inicia, falta el código.
        if ($pendingTwoFactorUser) {
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
                'id' => $pendingTwoFactorUser->getKey(),
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(TwoFactorChallengeController::PENDING_MINUTES)->getTimestamp(),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
