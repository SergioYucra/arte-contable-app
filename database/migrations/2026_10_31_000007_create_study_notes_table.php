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
        Schema::create('study_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('book_edition_id')
                ->constrained('book_editions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('title', 255);
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'book_edition_id', 'page_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_notes');
    }
};
