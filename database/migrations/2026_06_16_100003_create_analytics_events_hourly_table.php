<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events_hourly', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('event', 100);
            $table->dateTime('hour_bucket');
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('unique_sessions')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            $table->decimal('total_revenue', 12, 2)->nullable();

            $table->unique(['organization_id', 'event', 'hour_bucket'], 'aeh_unique_bucket');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events_hourly');
    }
};
