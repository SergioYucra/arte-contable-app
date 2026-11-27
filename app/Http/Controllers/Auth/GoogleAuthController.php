<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\GoogleAuthException;
use App\Http\Controllers\Controller;
use App\Services\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $googleAuth,
    ) {}

    /**
     * Inicia el flujo OAuth 2.0 redirigiendo a la pantalla de selección de cuenta de Google.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $origin = $request->query('from') === 'register' ? 'register' : 'login';

        if (! $this->googleAuth->isConfigured()) {
            return redirect()->route($origin)->withErrors([
                'google' => 'El acceso con Google no está configurado en este momento.',
            ]);
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);
        $request->session()->put('google_oauth_origin', $origin);

        return redirect()->away($this->googleAuth->authorizationUrl($state));
    }

    /**
     * Recibe el callback de Google, valida CSRF (state), resuelve la cuenta e inicia sesión.
     */
    public function callback(Request $request): RedirectResponse
    {
        $origin = $request->session()->pull('google_oauth_origin') === 'register' ? 'register' : 'login';
        $expectedState = (string) $request->session()->pull('google_oauth_state', '');
        $receivedState = (string) $request->query('state', '');

        if ($request->filled('error')) {
            $message = $request->query('error') === 'access_denied'
                ? 'Se canceló el acceso con Google.'
                : 'No se pudo completar el acceso con Google. Inténtalo nuevamente.';

            return redirect()->route($origin)->withErrors(['google' => $message]);
        }

        $code = trim((string) $request->query('code', ''));
        if ($expectedState === '' || $receivedState === '' || ! hash_equals($expectedState, $receivedState) || $code === '') {
            return redirect()->route($origin)->withErrors([
                'google' => 'No se pudo validar la solicitud de Google. Inténtalo nuevamente.',
            ]);
        }

        if (! $this->googleAuth->isConfigured()) {
            return redirect()->route($origin)->withErrors([
                'google' => 'El acceso con Google no está configurado en este momento.',
            ]);
        }

        try {
            $profile = $this->googleAuth->fetchVerifiedProfile($code);
            $user = $this->googleAuth->authenticateOrRegister($profile, $request->ip());
        } catch (GoogleAuthException $e) {
            return redirect()->route($origin)->withErrors(['google' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route($origin)->withErrors([
                'google' => 'Ocurrió un inconveniente al ingresar con Google. Inténtalo nuevamente.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
