<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Anti-enumeración (N8): todo 404 JSON responde con el mismo cuerpo, sea un registro inexistente
        // (ModelNotFoundException) o una cuenta oculta por UserPolicy (denyAsNotFound). Así no se filtra
        // ni la existencia del registro ni el nombre de la clase del modelo. Los callbacks corren después
        // de prepareException(), cuando ambas excepciones ya son HttpException con estado 404.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() === 404 && $request->expectsJson()) {
                return response()->json(['message' => 'Recurso no encontrado.'], 404);
            }
        });
    })->create();
