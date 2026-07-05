<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite indexes required for statistics snapshot aggregation.
 *
 * Without these, the hourly RecalculateDailyStatisticsJob would do full-table
 * scans on large datasets. Each index covers the exact (org, status, date)
 * pattern used in StatisticsService::liveForDate() and the Job's DB::table queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Supports: WHERE organization_id = ? AND status = 'paid' AND DATE(paid_at) = ?
            $table->index(['organization_id', 'status', 'paid_at'], 'orders_org_status_paid_at_index');
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Supports: WHERE organization_id = ? AND status IN (...) AND appointment_date = ?
            $table->index(['organization_id', 'status', 'appointment_date'], 'appointments_org_status_date_index');
        });

        Schema::table('rentals', function (Blueprint $table) {
            // Supports: WHERE organization_id = ? AND status IN (...) AND start_date = ?
            $table->index(['organization_id', 'status', 'start_date'], 'rentals_org_status_start_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_org_status_paid_at_index');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_org_status_date_index');
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropIndex('rentals_org_status_start_date_index');
        });
    }
};
