<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('event', 100);
            $table->string('url', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('page_type', 50)->nullable();
            // varchar(20) instead of ENUM for SQLite compatibility
            $table->string('device_type', 20)->nullable();
            $table->unsignedSmallInteger('viewport_w')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_hash', 64)->nullable(); // retained for future abuse detection; not populated
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');

            // Primary analytics query pattern
            $table->index(['organization_id', 'occurred_at', 'event'], 'ae_org_time_event');
            // Session lookups
            $table->index(['organization_id', 'session_id'], 'ae_org_session');
            // User journey
            $table->index(['organization_id', 'user_id', 'occurred_at'], 'ae_org_user_time');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
