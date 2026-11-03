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
        Schema::create('reading_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('book_edition_id')
                ->constrained('book_editions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedInteger('last_page')->default(1);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['user_id', 'book_edition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_states');
    }
};
