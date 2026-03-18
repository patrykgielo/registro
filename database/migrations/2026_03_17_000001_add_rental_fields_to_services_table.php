<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_type')->default('time_slot');
            $table->foreignId('rental_category_id')->nullable()->constrained('rental_categories')->nullOnDelete();
            $table->unsignedInteger('quantity_total')->nullable();
            $table->decimal('price_per_day', 10, 2)->nullable();
            $table->decimal('price_per_hour', 10, 2)->nullable();
            $table->decimal('price_per_week', 10, 2)->nullable();
            $table->decimal('price_per_day_long', 10, 2)->nullable();
            $table->unsignedSmallInteger('price_threshold_days')->nullable();
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->string('brand')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['rental_category_id']);
            $table->dropColumn([
                'service_type',
                'rental_category_id',
                'quantity_total',
                'price_per_day',
                'price_per_hour',
                'price_per_week',
                'price_per_day_long',
                'price_threshold_days',
                'deposit_amount',
                'brand',
            ]);
        });
    }
};
