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
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('vehicle_type_id')->nullable()->after('cancellation_reason')->constrained()->onDelete('set null');
            $table->foreignId('car_brand_id')->nullable()->after('vehicle_type_id')->constrained()->onDelete('set null');
            $table->foreignId('car_model_id')->nullable()->after('car_brand_id')->constrained()->onDelete('set null');
            $table->year('vehicle_year')->nullable()->after('car_model_id');
            $table->string('vehicle_custom_brand', 100)->nullable()->after('vehicle_year');
            $table->string('vehicle_custom_model', 100)->nullable()->after('vehicle_custom_brand');

            $table->index('vehicle_type_id');
            $table->index('car_brand_id');
            $table->index('car_model_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['vehicle_type_id']);
            $table->dropForeign(['car_brand_id']);
            $table->dropForeign(['car_model_id']);
            $table->dropIndex(['vehicle_type_id']);
            $table->dropIndex(['car_brand_id']);
            $table->dropIndex(['car_model_id']);
            $table->dropColumn(['vehicle_type_id', 'car_brand_id', 'car_model_id', 'vehicle_year', 'vehicle_custom_brand', 'vehicle_custom_model']);
        });
    }
};
