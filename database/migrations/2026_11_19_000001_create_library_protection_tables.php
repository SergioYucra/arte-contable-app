<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas del módulo de protección de los libros (app/Modules/LibraryProtection).
 *
 * Son tablas nuevas: no se agregan columnas a `users` para no chocar con la rama
 * fix/revision-3, que modifica ese modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Registro de quién vio cada página, una fila por persona, página y día (hora de Bolivia).
        // Sirve para el tope diario, para detectar lectura no humana y para rastrear filtraciones.
        Schema::create('book_page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('book_edition_id')->constrained('book_editions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->date('viewed_on');
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');
            $table->string('ip_address', 45)->nullable();

            $table->unique(['user_id', 'book_edition_id', 'page', 'viewed_on'], 'book_page_views_unique_daily');
            $table->index(['user_id', 'viewed_on']);
            $table->index(['user_id', 'first_viewed_at']);
            $table->index('viewed_on');
        });

        // Un dispositivo a la vez: qué dispositivo tiene el turno de lectura de cada cuenta.
        // Se guarda solo el hash del identificador del dispositivo, nunca el valor de la cookie.
        Schema::create('reading_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('last_seen_at');
        });

        // Pausas de lectura (automáticas por lectura no humana o pedidos trampa).
        Schema::create('reading_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('reason', 40);
            $table->json('details')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'released_at', 'ends_at']);
        });

        // Código personal que va impreso en cada página: el administrador lo busca para saber
        // de quién es una página filtrada.
        Schema::create('reader_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('code', 9)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reader_codes');
        Schema::dropIfExists('reading_holds');
        Schema::dropIfExists('reading_leases');
        Schema::dropIfExists('book_page_views');
    }
};
