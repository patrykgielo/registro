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
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('address', 500);
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('place_id', 255)->nullable();
            $table->json('components')->nullable();
            $table->string('nickname', 50)->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index('user_id', 'idx_user_addresses_user');
            $table->index(['latitude', 'longitude'], 'idx_user_addresses_coords');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
