<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Support\Security\AccessDeniedLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // D1: solo se aceptan peticiones (y se generan URLs) para el host de APP_URL y sus subdominios.
        // Evita que las cabeceras Host / X-Forwarded-Host del cliente alteren los enlaces generados
        // (por ejemplo, el de restablecer contraseña). Laravel omite este middleware en local y en tests.
        $middleware->trustHosts();

        // P14: proxies de confianza desde TRUSTED_PROXIES (vacío = sin proxy).
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        // P3: http:// → https:// en producción. P1: cabeceras de seguridad y CSP en toda respuesta.
        $middleware->append([ForceHttps::class, SecurityHeaders::class]);

        // V3: una sesión creada con una contraseña que ya cambió deja de valer en la siguiente petición.
        $middleware->authenticateSessions();

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'two-factor' => EnsureTwoFactorIsEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Los callbacks corren después de prepareException(): AuthorizationException y
        // ModelNotFoundException ya llegan como HttpException con su estado.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            // P7: un 403 a un usuario con sesión queda en la bitácora de seguridad.
            if ($e->getStatusCode() === 403) {
                AccessDeniedLogger::log($request);
            }

            // Anti-enumeración (N8): todo 404 JSON responde con el mismo cuerpo, sea un registro inexistente
            // (ModelNotFoundException) o una cuenta oculta por UserPolicy (denyAsNotFound). Así no se filtra
            // ni la existencia del registro ni el nombre de la clase del modelo.
            if ($e->getStatusCode() === 404 && $request->expectsJson()) {
                return response()->json(['message' => 'Recurso no encontrado.'], 404);
            }

            return null;
        });
    })->create();
