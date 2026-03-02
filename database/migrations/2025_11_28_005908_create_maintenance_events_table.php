<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('maintenance_events', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['deployment', 'prelaunch', 'scheduled', 'emergency']);
            $table->enum('action', ['enabled', 'disabled']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_events');

        // Clean up Redis state (if migration failed mid-way)
        try {
            Cache::store('redis')->forget('maintenance:mode');
            Cache::store('redis')->forget('maintenance:config');
            Cache::store('redis')->forget('maintenance:enabled_at');
        } catch (\Exception $e) {
            Log::warning('Redis cleanup failed during migration rollback: '.$e->getMessage());
        }
    }
};
