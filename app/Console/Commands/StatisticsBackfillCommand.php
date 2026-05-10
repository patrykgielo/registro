<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecalculateDailyStatisticsJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Backfill statistics snapshots over a historical date range.
 *
 * Chunks the range into 30-day windows and dispatches one job per day.
 * This keeps individual jobs small and avoids memory exhaustion.
 *
 * Usage:
 *   php artisan statistics:backfill --from=2026-01-01
 *   php artisan statistics:backfill --from=2026-01-01 --to=2026-04-30
 */
class StatisticsBackfillCommand extends Command
{
    protected $signature = 'statistics:backfill
        {--from=     : Start date (YYYY-MM-DD, required)}
        {--to=       : End date (YYYY-MM-DD, defaults to yesterday)}';

    protected $description = 'Backfill daily statistics snapshots for a historical date range';

    public function handle(): int
    {
        $fromOption = $this->option('from');

        if ($fromOption === null) {
            $this->error('--from is required. Example: --from=2026-01-01');

            return self::INVALID;
        }

        $from = Carbon::parse($fromOption)->startOfDay();
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : Carbon::yesterday()->startOfDay();

        if ($from->greaterThan($to)) {
            $this->error("--from ({$from->toDateString()}) must be before --to ({$to->toDateString()}).");

            return self::INVALID;
        }

        $totalDays = (int) $from->diffInDays($to) + 1;
        $this->info("Backfilling {$totalDays} day(s) from {$from->toDateString()} to {$to->toDateString()}...");

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();

        // Process in 30-day windows to keep job payload size manageable
        $chunkSize = 30;
        $current = $from->copy();

        while ($current->lte($to)) {
            $chunkEnd = $current->copy()->addDays($chunkSize - 1);
            if ($chunkEnd->greaterThan($to)) {
                $chunkEnd = $to->copy();
            }

            $chunkCurrent = $current->copy();
            while ($chunkCurrent->lte($chunkEnd)) {
                dispatch(new RecalculateDailyStatisticsJob($chunkCurrent->copy()));
                $chunkCurrent->addDay();
                $bar->advance();
            }

            $current->addDays($chunkSize);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Dispatched {$totalDays} jobs.");

        return self::SUCCESS;
    }
}
