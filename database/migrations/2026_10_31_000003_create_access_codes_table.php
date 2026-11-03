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
        Schema::create('access_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('reference', 255)->nullable();
            $table->unsignedInteger('duration_days')->default(365);
            $table->foreignId('redeemed_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();

            $table->index(['code', 'redeemed_at', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_codes');
    }
};
