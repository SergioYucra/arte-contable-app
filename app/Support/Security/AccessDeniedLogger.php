<?php

namespace App\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * P7 (H8, A09:2025): un 403 a un usuario con sesión queda en la bitácora de seguridad (alguien
 * intentó algo que su nivel no permite). Una fila por usuario, método y ruta cada 10 minutos, porque
 * la bitácora no se depura.
 *
 * Se llama mientras Laravel arma la respuesta de error: si falla, se reporta y la respuesta 403
 * sale igual.
 */
final class AccessDeniedLogger
{
    public const DEDUPE_SECONDS = 600;

    public static function log(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        try {
            $route = $request->route()?->getName() ?? $request->path();
            $key = 'auth-log:403:'.sha1($user->getKey().'|'.$request->method().'|'.$route);

            if (! Cache::add($key, true, self::DEDUPE_SECONDS)) {
                return;
            }

            activity('security')
                ->causedBy($user)
                ->withProperties([
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'route' => $route,
                    'ip' => $request->ip(),
                ])
                ->log('Acceso denegado (403).');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
