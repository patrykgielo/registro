<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecalculateDailyStatisticsJob;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Dispatch RecalculateDailyStatisticsJob for one or more dates/orgs.
 *
 * Usage examples:
 *   php artisan statistics:recalculate
 *   php artisan statistics:recalculate --date=2026-05-01
 *   php artisan statistics:recalculate --from=2026-05-01 --to=2026-05-10
 *   php artisan statistics:recalculate --org=42
 */
class StatisticsRecalculateCommand extends Command
{
    protected $signature = 'statistics:recalculate
        {--date=     : Single date (YYYY-MM-DD). Defaults to today}
        {--from=     : Start of date range (YYYY-MM-DD). Overrides --date}
        {--to=       : End of date range (YYYY-MM-DD). Defaults to today when --from is set}
        {--org=      : Organization ID. Defaults to all organizations}';

    protected $description = 'Dispatch statistics recalculation jobs for given date(s) and organization(s)';

    public function handle(): int
    {
        [$from, $to] = $this->resolveDateRange();
        $orgId = $this->option('org') ? (int) $this->option('org') : null;

        if ($orgId !== null && ! Organization::where('id', $orgId)->exists()) {
            $this->error("Organization with ID {$orgId} not found.");

            return self::FAILURE;
        }

        $days = 0;
        $current = $from->copy();

        while ($current->lte($to)) {
            dispatch(new RecalculateDailyStatisticsJob($current->copy(), $orgId));
            $current->addDay();
            $days++;
        }

        $orgLabel = $orgId ? "org={$orgId}" : 'all orgs';
        $this->info("Dispatched {$days} job(s) for {$orgLabel}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $date = $this->option('date');

        if ($from !== null) {
            return [
                Carbon::parse($from)->startOfDay(),
                $to !== null ? Carbon::parse($to)->startOfDay() : Carbon::today(),
            ];
        }

        $day = $date !== null ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return [$day, $day];
    }
}
