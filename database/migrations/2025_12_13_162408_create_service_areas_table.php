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
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();

            // Geographic data
            $table->string('city_name', 100)->index();
            $table->decimal('latitude', 10, 8);  // e.g., 52.22970000
            $table->decimal('longitude', 11, 8); // e.g., 21.01220000
            $table->unsignedInteger('radius_km'); // e.g., 50

            // Administrative
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            // Metadata
            $table->text('description')->nullable(); // e.g., "Greater Warsaw Area"
            $table->string('color_hex', 7)->default('#4CAF50'); // Map circle color

            $table->timestamps();

            // Spatial index for efficient geographic queries
            $table->index(['latitude', 'longitude'], 'service_area_coords_index');

            // Unique constraint: same center cannot have duplicate radii
            $table->unique(['latitude', 'longitude', 'radius_km'], 'unique_service_area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_areas');
    }
};
