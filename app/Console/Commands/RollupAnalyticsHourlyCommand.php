<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RollupAnalyticsHourlyCommand extends Command
{
    protected $signature = 'analytics:rollup-hourly {--hours=2 : Number of past completed hours to process (use --hours=24 for catch-up)}';

    protected $description = 'Aggregate raw analytics events into hourly rollup buckets';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $driver = DB::getDriverName();

        // Only process completed hours — current hour is still accumulating
        $to = now()->startOfHour();
        $from = $to->copy()->subHours($hours);

        $processed = 0;
        $current = $from->copy();

        while ($current->lt($to)) {
            $next = $current->copy()->addHour();
            $this->rollupHour($current, $next, $driver);
            $current = $next;
            $processed++;
        }

        $this->info("Rolled up {$processed} hour bucket(s) from {$from->toDateTimeString()} to {$to->toDateTimeString()}.");

        return self::SUCCESS;
    }

    private function rollupHour(Carbon $from, Carbon $to, string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                // REPLACE INTO = DELETE + INSERT on PK/UNIQUE conflict.
                // Safe here because all columns are re-aggregated from raw events.
                // Avoids VALUES() (deprecated MySQL 8.0.20) and the row-alias workaround
                // which only applies to INSERT...VALUES, not INSERT...SELECT.
                DB::statement("
                    REPLACE INTO analytics_events_hourly
                        (organization_id, event, hour_bucket, event_count, unique_sessions, unique_users, total_revenue)
                    SELECT
                        organization_id,
                        event,
                        DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00') AS hour_bucket,
                        COUNT(*) AS event_count,
                        COUNT(DISTINCT session_id) AS unique_sessions,
                        COUNT(DISTINCT user_id) AS unique_users,
                        SUM(_revenue) AS total_revenue
                    FROM analytics_events
                    WHERE occurred_at >= ? AND occurred_at < ?
                    GROUP BY organization_id, event, DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00')
                ", [$from, $to]);
            } else {
                // SQLite: no generated columns, no DATE_FORMAT, no ON DUPLICATE KEY UPDATE
                DB::statement("
                    INSERT OR REPLACE INTO analytics_events_hourly
                        (organization_id, event, hour_bucket, event_count, unique_sessions, unique_users, total_revenue)
                    SELECT
                        organization_id,
                        event,
                        strftime('%Y-%m-%d %H:00:00', occurred_at) AS hour_bucket,
                        COUNT(*) AS event_count,
                        COUNT(DISTINCT session_id) AS unique_sessions,
                        COUNT(DISTINCT user_id) AS unique_users,
                        NULL AS total_revenue
                    FROM analytics_events
                    WHERE occurred_at >= ? AND occurred_at < ?
                    GROUP BY organization_id, event, strftime('%Y-%m-%d %H:00:00', occurred_at)
                ", [$from, $to]);
            }
        } catch (\Exception $e) {
            $this->error("Failed to roll up hour {$from->toDateTimeString()}: {$e->getMessage()}");

            throw $e;
        }
    }
}
