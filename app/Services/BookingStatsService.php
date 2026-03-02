<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\DB;

class BookingStatsService
{
    /**
     * Increment booking count for a service
     * Called after successful appointment creation
     */
    public static function incrementBookingCount(Service $service): void
    {
        $service->increment('booking_count_today');
        $service->increment('booking_count_week');
        $service->increment('booking_count_month');
        $service->increment('booking_count_total');
    }

    /**
     * Increment view count for a service
     * Called when service page/card is viewed
     */
    public static function incrementViewCount(Service $service): void
    {
        $service->increment('view_count_today');
        $service->increment('view_count_week');
    }

    /**
     * Reset daily stats for all services
     * Should be called via cron job daily at midnight
     *
     * Cron: 0 0 * * * cd /var/www/projects/registro/app && php artisan booking:reset-daily-stats
     */
    public static function resetDailyStats(): void
    {
        DB::table('services')->update([
            'booking_count_today' => 0,
            'view_count_today' => 0,
            'stats_reset_daily' => now(),
        ]);
    }

    /**
     * Reset weekly stats for all services
     * Should be called via cron job weekly on Monday at midnight
     *
     * Cron: 0 0 * * 1 cd /var/www/projects/registro/app && php artisan booking:reset-weekly-stats
     */
    public static function resetWeeklyStats(): void
    {
        DB::table('services')->update([
            'booking_count_week' => 0,
            'view_count_week' => 0,
            'stats_reset_weekly' => now(),
        ]);
    }

    /**
     * Reset monthly stats for all services
     * Should be called via cron job monthly on 1st at midnight
     *
     * Cron: 0 0 1 * * cd /var/www/projects/registro/app && php artisan booking:reset-monthly-stats
     */
    public static function resetMonthlyStats(): void
    {
        DB::table('services')->update([
            'booking_count_month' => 0,
        ]);
    }

    /**
     * Get booking stats summary for a service
     *
     * @return array{today: int, week: int, month: int, total: int}
     */
    public static function getBookingStats(Service $service): array
    {
        return [
            'today' => $service->booking_count_today,
            'week' => $service->booking_count_week,
            'month' => $service->booking_count_month,
            'total' => $service->booking_count_total,
        ];
    }

    /**
     * Get view stats summary for a service
     *
     * @return array{today: int, week: int}
     */
    public static function getViewStats(Service $service): array
    {
        return [
            'today' => $service->view_count_today,
            'week' => $service->view_count_week,
        ];
    }

    /**
     * Get conversion rate for a service
     * (bookings / views * 100)
     */
    public static function getConversionRate(Service $service, string $period = 'week'): float
    {
        $bookings = match ($period) {
            'today' => $service->booking_count_today,
            'week' => $service->booking_count_week,
            'month' => $service->booking_count_month,
            default => $service->booking_count_week,
        };

        $views = match ($period) {
            'today' => $service->view_count_today,
            'week' => $service->view_count_week,
            default => $service->view_count_week,
        };

        if ($views === 0) {
            return 0.0;
        }

        return round(($bookings / $views) * 100, 2);
    }
}
