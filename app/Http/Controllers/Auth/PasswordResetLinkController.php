<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * V2 (A07:2025): respuesta única, exista o no una cuenta activa con ese correo.
     */
    public const NEUTRAL_STATUS = 'Si el correo pertenece a una cuenta activa, te enviamos un enlace para restablecer la contraseña. Revisa tu bandeja de entrada y la carpeta de spam.';

    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * V2: antes la pantalla mostraba el estado del broker tal cual ("Hemos enviado…" frente a
     * "No encontramos ningún usuario…") y delataba qué correos tienen cuenta. Ahora la respuesta es
     * siempre la misma y el envío ocurre después de responder (defer), para que tampoco lo delate
     * el tiempo que tarda el servidor de correo.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            ...Turnstile::rules(),
        ]);

        $email = (string) $validated['email'];

        defer(function () use ($email): void {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return back()->with('status', self::NEUTRAL_STATUS);
    }
}
