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
        Schema::table('services', function (Blueprint $table) {
            // Note: booking_count_today and booking_count_week already exist from previous migration
            // Only add the missing columns

            // Additional booking count tracking for social proof
            if (! Schema::hasColumn('services', 'booking_count_month')) {
                $table->unsignedInteger('booking_count_month')->default(0)->after('booking_count_week');
            }
            if (! Schema::hasColumn('services', 'booking_count_total')) {
                $table->unsignedInteger('booking_count_total')->default(0)->after('booking_count_month');
            }

            // View count tracking for "X people viewed today"
            if (! Schema::hasColumn('services', 'view_count_today')) {
                $table->unsignedInteger('view_count_today')->default(0)->after('booking_count_total');
            }
            if (! Schema::hasColumn('services', 'view_count_week')) {
                $table->unsignedInteger('view_count_week')->default(0)->after('view_count_today');
            }

            // Last reset timestamps (for daily/weekly resets)
            if (! Schema::hasColumn('services', 'stats_reset_daily')) {
                $table->date('stats_reset_daily')->nullable()->after('view_count_week');
            }
            if (! Schema::hasColumn('services', 'stats_reset_weekly')) {
                $table->date('stats_reset_weekly')->nullable()->after('stats_reset_daily');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'booking_count_today',
                'booking_count_week',
                'booking_count_month',
                'booking_count_total',
                'view_count_today',
                'view_count_week',
                'stats_reset_daily',
                'stats_reset_weekly',
            ]);
        });
    }
};
