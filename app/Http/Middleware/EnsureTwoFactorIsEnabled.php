<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P4 (H1): Administradores y Superadministradores no entran al panel sin verificación en dos pasos.
 * Los demás niveles pueden activarla desde su perfil, pero no se les exige.
 */
class EnsureTwoFactorIsEnabled
{
    public const MESSAGE = 'Tu nivel de acceso exige la verificación en dos pasos. Actívala para continuar.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresTwoFactorAuthentication() && ! $user->hasEnabledTwoFactorAuthentication()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            return redirect()->route('two-factor.show')->with('two-factor-required', self::MESSAGE);
        }

        return $next($request);
    }
}
