<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailRevertController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSettingsController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    // P4: segundo paso del login (cuentas con verificación en dos pasos activa).
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');

    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1');
});

// V4: enlace "No fui yo" que recibe el correo anterior tras un cambio de correo. Firmado y sin sesión:
// el dueño legítimo normalmente ya no puede entrar a su cuenta.
Route::get('email/revert/{user}', EmailRevertController::class)
    ->whereNumber('user')
    ->middleware(['signed', 'throttle:6,1'])
    ->name('email.revert');

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::put('email/change-address', [EmailVerificationNotificationController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('verification.update');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    // P4: configuración de la verificación en dos pasos. La página pide confirmar la contraseña;
    // las acciones lo vuelven a comprobar en el controlador.
    Route::middleware('verified')->group(function () {
        Route::get('two-factor', [TwoFactorSettingsController::class, 'show'])
            ->middleware('password.confirm')
            ->name('two-factor.show');

        Route::middleware('throttle:6,1')->group(function () {
            Route::post('two-factor', [TwoFactorSettingsController::class, 'enable'])->name('two-factor.enable');
            Route::post('two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])->name('two-factor.confirm');
            Route::post('two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])->name('two-factor.recovery-codes');
            Route::delete('two-factor', [TwoFactorSettingsController::class, 'disable'])->name('two-factor.disable');
        });
    });
});
