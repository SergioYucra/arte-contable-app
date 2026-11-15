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
        Schema::create('quick_link_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order'], 'idx_ql_categories_active_sort');
        });

        Schema::create('quick_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quick_link_category_id')
                ->constrained('quick_link_categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('title', 120);
            $table->string('description', 255)->nullable();
            $table->string('url', 500);
            $table->string('logo_path', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['quick_link_category_id', 'is_active', 'sort_order'],
                'idx_quick_links_cat_active_sort'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_links');
        Schema::dropIfExists('quick_link_categories');
    }
};
