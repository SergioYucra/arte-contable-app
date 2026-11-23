<?php

use App\Modules\LibraryProtection\Controllers\ProtectionPanelController;
use App\Modules\LibraryProtection\Controllers\ReaderDeviceController;
use Illuminate\Support\Facades\Route;

/*
| Rutas del módulo de protección de los libros. Las carga LibraryProtectionServiceProvider
| (así no se toca bootstrap/app.php, que modifica la rama fix/revision-3).
| La ruta que entrega las páginas sigue en routes/web.php (library.editions.page).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    // "Leer aquí": pasar la lectura a este dispositivo.
    Route::post('/biblioteca/lector/aqui', [ReaderDeviceController::class, 'claim'])
        ->middleware('throttle:10,1,library-reader-claim')
        ->name('library.reader.claim');
});

Route::middleware(['web', 'auth', 'verified', 'permission:manage-users'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/libros/proteccion', [ProtectionPanelController::class, 'index'])
            ->name('library-protection.index');
        Route::get('/libros/proteccion/datos', [ProtectionPanelController::class, 'data'])
            ->middleware('throttle:60,1,library-protection-data')
            ->name('library-protection.data');
        Route::get('/libros/proteccion/codigo', [ProtectionPanelController::class, 'lookup'])
            ->middleware('throttle:30,1,library-protection-lookup')
            ->name('library-protection.lookup');
        Route::post('/libros/proteccion/pausas/{hold}/reactivar', [ProtectionPanelController::class, 'release'])
            ->whereNumber('hold')
            ->middleware('throttle:30,1,library-protection-release')
            ->name('library-protection.holds.release');
    });
