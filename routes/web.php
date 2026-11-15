<?php

use App\Http\Controllers\Admin\BookController;
use App\Http\Controllers\Admin\MembershipMemberController;
use App\Http\Controllers\Admin\QuickLinkCategoryController;
use App\Http\Controllers\Admin\QuickLinkController;
use App\Http\Controllers\Admin\SubscriptionAdminController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\LibraryController;
use App\Http\Controllers\Student\MembershipController;
use App\Http\Controllers\Student\QuickLinkStudentController;
use App\Http\Controllers\Student\StudyNoteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sitemap.xml', function () {
    $urls = [
        [
            'loc' => url('/'),
            'changefreq' => 'weekly',
            'priority' => '1.0',
        ],
        [
            'loc' => route('register'),
            'changefreq' => 'monthly',
            'priority' => '0.8',
        ],
        [
            'loc' => route('login'),
            'changefreq' => 'monthly',
            'priority' => '0.7',
        ],
    ];

    $xml = view('sitemap', ['urls' => $urls])->render();

    return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
})->name('sitemap');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

// Portal de Membresía y Biblioteca del Alumno
Route::middleware(['auth', 'verified'])->group(function () {
    // Fase A: Biblioteca / Catálogo del Estudiante y Lector Protegido
    Route::get('/biblioteca', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/leer/{edition}', [LibraryController::class, 'read'])->whereNumber('edition')->name('library.reader');
    Route::get('/biblioteca/ediciones/{edition}/paginas/{page}', [LibraryController::class, 'streamPage'])
        ->whereNumber(['edition', 'page'])
        ->middleware('throttle:120,1')
        ->name('library.editions.page');
    Route::post('/biblioteca/ediciones/{edition}/progreso', [LibraryController::class, 'saveProgress'])
        ->whereNumber('edition')
        ->middleware('throttle:60,1')
        ->name('library.editions.progress');

    // Cuaderno de Estudio (candado por membresía vigente en StudyNotePolicy)
    Route::get('/biblioteca/ediciones/{edition}/apuntes', [StudyNoteController::class, 'index'])
        ->whereNumber('edition')
        ->middleware('throttle:60,1')
        ->name('library.notes.index');
    Route::post('/biblioteca/ediciones/{edition}/apuntes', [StudyNoteController::class, 'store'])
        ->whereNumber('edition')
        ->middleware('throttle:30,1')
        ->name('library.notes.store');
    Route::put('/biblioteca/apuntes/{note}', [StudyNoteController::class, 'update'])
        ->whereNumber('note')
        ->middleware('throttle:120,1')
        ->name('library.notes.update');
    Route::delete('/biblioteca/apuntes/{note}', [StudyNoteController::class, 'destroy'])
        ->whereNumber('note')
        ->middleware('throttle:30,1')
        ->name('library.notes.destroy');

    Route::get('/membresia', [MembershipController::class, 'index'])->name('membership.index');
    Route::post('/membresia/pago', [MembershipController::class, 'storePayment'])->middleware('throttle:10,1')->name('membership.payment.store');
    Route::post('/membresia/canjear', [MembershipController::class, 'redeemCode'])->middleware('throttle:10,1')->name('membership.redeem');
    Route::get('/membresia/solicitudes/{paymentRequest}/comprobante', [MembershipController::class, 'streamProof'])->middleware('throttle:60,1')->name('membership.proof');

    Route::get('/accesos-rapidos', [QuickLinkStudentController::class, 'index'])->name('quick-links.index');
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

        // Listados paginados ("Ver más") y "Personas con acceso"
        Route::get('/suscripciones/solicitudes', [SubscriptionAdminController::class, 'reviewedRequests'])->middleware('throttle:60,1')->name('subscriptions.requests.index');
        Route::get('/suscripciones/codigos', [SubscriptionAdminController::class, 'accessCodes'])->middleware('throttle:60,1')->name('subscriptions.codes.index');
        Route::get('/suscripciones/personas', [MembershipMemberController::class, 'index'])->middleware('throttle:60,1')->name('subscriptions.members.index');
        Route::get('/suscripciones/personas/{user}/historial', [MembershipMemberController::class, 'history'])->whereNumber('user')->middleware('throttle:60,1')->name('subscriptions.members.history');
        Route::post('/suscripciones/personas/{user}/quitar-acceso', [MembershipMemberController::class, 'revoke'])->whereNumber('user')->middleware('throttle:30,1')->name('subscriptions.members.revoke');

        // Gestión de Accesos Rápidos y Categorías Oficiales
        Route::get('/accesos-rapidos', [QuickLinkController::class, 'index'])->name('quick-links.index');
        Route::post('/accesos-rapidos', [QuickLinkController::class, 'store'])->middleware('throttle:30,1')->name('quick-links.store');
        Route::post('/accesos-rapidos/{id}', [QuickLinkController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1')->name('quick-links.update');
        Route::patch('/accesos-rapidos/{quickLink}/estado', [QuickLinkController::class, 'toggleActive'])->whereNumber('quickLink')->middleware('throttle:30,1')->name('quick-links.toggle-active');
        Route::delete('/accesos-rapidos/{quickLink}', [QuickLinkController::class, 'destroy'])->whereNumber('quickLink')->middleware('throttle:30,1')->name('quick-links.destroy');
        Route::post('/accesos-rapidos/{id}/restaurar', [QuickLinkController::class, 'restore'])->whereNumber('id')->middleware('throttle:30,1')->name('quick-links.restore');

        Route::post('/accesos-rapidos/categorias', [QuickLinkCategoryController::class, 'store'])->middleware('throttle:30,1')->name('quick-links.categories.store');
        Route::put('/accesos-rapidos/categorias/{category}', [QuickLinkCategoryController::class, 'update'])->whereNumber('category')->middleware('throttle:30,1')->name('quick-links.categories.update');
        Route::patch('/accesos-rapidos/categorias/{category}/estado', [QuickLinkCategoryController::class, 'toggleActive'])->whereNumber('category')->middleware('throttle:30,1')->name('quick-links.categories.toggle-active');
        Route::delete('/accesos-rapidos/categorias/{category}', [QuickLinkCategoryController::class, 'destroy'])->whereNumber('category')->middleware('throttle:30,1')->name('quick-links.categories.destroy');
        Route::post('/accesos-rapidos/categorias/{id}/restaurar', [QuickLinkCategoryController::class, 'restore'])->whereNumber('id')->middleware('throttle:30,1')->name('quick-links.categories.restore');
    });

require __DIR__.'/auth.php';
