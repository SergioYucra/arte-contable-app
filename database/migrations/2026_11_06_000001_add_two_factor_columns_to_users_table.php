<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 (H1): verificación en dos pasos con app de códigos (TOTP).
 *
 * - two_factor_secret: secreto TOTP, cifrado con APP_KEY (cast 'encrypted' del modelo).
 * - two_factor_recovery_codes: hashes SHA-256 de los códigos de recuperación, también cifrados.
 *   Los códigos en claro se muestran una sola vez, al generarlos.
 * - two_factor_confirmed_at: el 2FA cuenta como activo solo después de confirmar un código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
