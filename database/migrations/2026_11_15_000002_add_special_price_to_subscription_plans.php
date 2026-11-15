<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precio especial por tiempo limitado (opcional) en cada plan, y en cada pago el nombre de la
     * promoción con la que se pagó.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->decimal('special_price', 10, 2)->nullable()->after('price');
            $table->string('special_label', 60)->nullable()->after('special_price');
            $table->timestamp('special_starts_at')->nullable()->after('special_label');
            $table->timestamp('special_ends_at')->nullable()->after('special_starts_at');
            // El QR del banco lleva el monto: el precio especial necesita el suyo.
            $table->string('special_qr_image_path', 255)->nullable()->after('qr_image_path');
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('special_price_label', 80)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('special_price_label');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['special_price', 'special_label', 'special_starts_at', 'special_ends_at', 'special_qr_image_path']);
        });
    }
};
