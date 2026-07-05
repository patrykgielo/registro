<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-aggregated daily statistics snapshots.
 *
 * Architecture decision: 3 rows per day per tenant (one per source).
 * All UI reads from this table; raw tables are only touched by the hourly job.
 * The unique constraint on (organization_id, date, source) enables safe upserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistics_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('source', ['orders', 'appointments', 'rentals']);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('computed_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'date', 'source']);
            $table->index(['date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistics_daily_snapshots');
    }
};
