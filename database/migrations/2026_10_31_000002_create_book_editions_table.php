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
        Schema::create('book_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('edition_name', 100);
            $table->unsignedInteger('total_pages')->default(0);
            $table->unsignedInteger('sample_pages_count')->default(12);
            $table->json('toc_json')->nullable();
            $table->enum('status', ['draft', 'ready'])->default('draft');
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['book_id', 'is_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_editions');
    }
};
