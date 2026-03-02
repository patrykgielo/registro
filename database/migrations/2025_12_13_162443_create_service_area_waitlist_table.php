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
        Schema::create('service_area_waitlist', function (Blueprint $table) {
            $table->id();

            // User contact info
            $table->string('email', 255)->index();
            $table->string('name', 100)->nullable();
            $table->string('phone', 20)->nullable();

            // Requested location
            $table->string('requested_address', 500);
            $table->decimal('requested_latitude', 10, 8);
            $table->decimal('requested_longitude', 11, 8);
            $table->string('requested_place_id', 255)->nullable();

            // Metadata
            $table->decimal('distance_to_nearest_area_km', 8, 2)->nullable(); // For admin reference
            $table->string('nearest_area_city', 100)->nullable();

            // Status tracking
            $table->enum('status', ['pending', 'contacted', 'area_added', 'declined'])->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->timestamp('notified_at')->nullable();

            // Session tracking (prevent spam)
            $table->string('session_id', 255)->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            // Prevent duplicate submissions from same email for same location
            $table->unique(['email', 'requested_latitude', 'requested_longitude'], 'unique_waitlist_entry');

            // Spatial index
            $table->index(['requested_latitude', 'requested_longitude'], 'waitlist_coords_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_area_waitlist');
    }
};
