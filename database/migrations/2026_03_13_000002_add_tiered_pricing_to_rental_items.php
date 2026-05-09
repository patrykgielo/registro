<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_items', function (Blueprint $table) {
            $table->decimal('price_per_day_long', 10, 2)->nullable()->after('price_per_week');
            $table->unsignedSmallInteger('price_threshold_days')->nullable()->after('price_per_day_long');
            $table->string('brand')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('rental_items', function (Blueprint $table) {
            $table->dropColumn(['price_per_day_long', 'price_threshold_days', 'brand']);
        });
    }
};
