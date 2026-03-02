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
            $table->string('location_address', 500)->nullable()->after('notes');
            $table->double('location_latitude', 10, 8)->nullable()->after('location_address');
            $table->double('location_longitude', 11, 8)->nullable()->after('location_latitude');
            $table->string('location_place_id')->nullable()->after('location_longitude');
            $table->json('location_components')->nullable()->after('location_place_id');
            $table->index(['location_latitude', 'location_longitude'], 'location_coords_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('location_coords_index');
            $table->dropColumn([
                'location_address',
                'location_latitude',
                'location_longitude',
                'location_place_id',
                'location_components',
            ]);
        });
    }
};
