<?php

use App\Http\Controllers\Admin\BookController;
use App\Http\Controllers\Admin\SubscriptionAdminController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

// Módulo Base: Administración de Usuarios y Roles (Capa Core)
Route::middleware(['auth', 'verified', 'permission:manage-users'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
        Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resend-verification');

        // Gestión de Libros, Ediciones y Páginas Protegidas
        Route::get('/libros', [BookController::class, 'index'])->name('books.index');
        Route::post('/libros', [BookController::class, 'store'])->middleware('throttle:30,1')->name('books.store');
        Route::post('/libros/{id}', [BookController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1')->name('books.update');
        Route::delete('/libros/{book}', [BookController::class, 'destroy'])->middleware('throttle:30,1')->name('books.destroy');
        Route::post('/libros/{id}/restaurar', [BookController::class, 'restore'])->whereNumber('id')->middleware('throttle:30,1')->name('books.restore');

        Route::post('/libros/{book}/ediciones', [BookController::class, 'storeEdition'])->middleware('throttle:30,1')->name('books.editions.store');
        Route::patch('/ediciones/{edition}', [BookController::class, 'updateEdition'])->middleware('throttle:30,1')->name('books.editions.update');
        Route::put('/ediciones/{edition}/indice', [BookController::class, 'updateToc'])->middleware('throttle:30,1')->name('books.editions.toc');
        Route::post('/ediciones/{edition}/paginas', [BookController::class, 'uploadPages'])->middleware('throttle:120,1')->name('books.editions.upload-pages');
        Route::delete('/ediciones/{edition}/paginas', [BookController::class, 'purgePages'])->middleware('throttle:30,1')->name('books.editions.purge-pages');
        Route::delete('/ediciones/{edition}', [BookController::class, 'destroyEdition'])->middleware('throttle:30,1')->name('books.editions.destroy');
        Route::get('/ediciones/{edition}/paginas/{page}', [BookController::class, 'previewPage'])->whereNumber('page')->middleware('throttle:120,1')->name('books.editions.preview-page');

        // Gestión de Suscripciones, Planes de Acceso y Solicitudes de Pago
        Route::get('/suscripciones', [SubscriptionAdminController::class, 'index'])->name('subscriptions.index');
        Route::post('/suscripciones/planes', [SubscriptionAdminController::class, 'storePlan'])->middleware('throttle:30,1')->name('subscriptions.plans.store');
        Route::post('/suscripciones/planes/{id}', [SubscriptionAdminController::class, 'updatePlan'])->whereNumber('id')->middleware('throttle:30,1')->name('subscriptions.plans.update');
        Route::delete('/suscripciones/planes/{plan}', [SubscriptionAdminController::class, 'destroyPlan'])->middleware('throttle:30,1')->name('subscriptions.plans.destroy');
        Route::post('/suscripciones/planes/{id}/restaurar', [SubscriptionAdminController::class, 'restorePlan'])->whereNumber('id')->middleware('throttle:30,1')->name('subscriptions.plans.restore');

        Route::post('/suscripciones/solicitudes/{paymentRequest}/aprobar', [SubscriptionAdminController::class, 'approvePaymentRequest'])->middleware('throttle:30,1')->name('subscriptions.requests.approve');
        Route::post('/suscripciones/solicitudes/{paymentRequest}/rechazar', [SubscriptionAdminController::class, 'rejectPaymentRequest'])->middleware('throttle:30,1')->name('subscriptions.requests.reject');
        Route::get('/suscripciones/solicitudes/{paymentRequest}/comprobante', [SubscriptionAdminController::class, 'previewProof'])->middleware('throttle:60,1')->name('subscriptions.requests.proof');

        Route::post('/suscripciones/codigos', [SubscriptionAdminController::class, 'storeAccessCode'])->middleware('throttle:30,1')->name('subscriptions.codes.store');
        Route::post('/suscripciones/codigos/{accessCode}/revocar', [SubscriptionAdminController::class, 'revokeAccessCode'])->middleware('throttle:30,1')->name('subscriptions.codes.revoke');
    });

require __DIR__.'/auth.php';
