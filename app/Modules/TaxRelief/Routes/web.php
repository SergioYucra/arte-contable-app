<?php

use App\Modules\TaxRelief\Controllers\TaxReliefController;
use Illuminate\Support\Facades\Route;

/*
| Calculadora de alivio tributario (vista del estudiante).
| Cargado por TaxReliefServiceProvider con el grupo "web" (sesión + CSRF).
|
| Acceso: sesión iniciada, correo verificado y rol Estudiante (User) o del
| equipo. Límites por persona: "tax-relief" y "tax-relief-lookup".
*/

Route::middleware(['auth', 'verified', 'role:User|Manager|Admin|SuperAdmin'])
    ->prefix('herramientas/alivio-tributario')
    ->name('tax-relief.')
    ->group(function () {
        Route::get('/', [TaxReliefController::class, 'index'])->name('index');

        Route::post('/calcular', [TaxReliefController::class, 'calculate'])
            ->middleware('throttle:tax-relief')
            ->name('calculate');

        Route::post('/desglose', [TaxReliefController::class, 'breakdown'])
            ->middleware('throttle:tax-relief')
            ->name('breakdown');

        Route::get('/vencimiento', [TaxReliefController::class, 'dueDate'])
            ->middleware('throttle:tax-relief-lookup')
            ->name('due-date');
    });
