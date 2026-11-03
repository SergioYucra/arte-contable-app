<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('book_edition_id')
                ->nullable()
                ->constrained('book_editions')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->foreignId('payment_request_id')
                ->nullable()
                ->unique()
                ->constrained('payment_requests')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('access_code_id')
                ->nullable()
                ->unique()
                ->constrained('access_codes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->string('active_session_token', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
