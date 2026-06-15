<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAnalyticsEventsCommand extends Command
{
    protected $signature = 'analytics:prune {--months=13 : Retention period in months}';

    protected $description = 'Delete analytics events older than the retention period (GDPR compliance)';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $cutoff = now()->subMonths($months)->startOfDay();

        $deleted = DB::table('analytics_events')
            ->where('occurred_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} analytics events older than {$months} months (before {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }
}
