<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Delete organization data export ZIPs older than N days (GDPR art. 5(1)(e)).
 *
 * Export files contain PII and must not accumulate indefinitely.
 * Default retention: config/retention.php export_files_days (8 days).
 * Rationale: TTL on signed URL is 7 days — files are inaccessible after that,
 * so keeping them beyond 8 days serves no purpose and violates data minimisation.
 */
class CleanupOrganizationExportsCommand extends Command
{
    protected $signature = 'organizations:cleanup-exports
        {--days= : Override retention period in days (default: config/retention.export_files_days)}';

    protected $description = 'Delete organization data export ZIPs older than N days (GDPR art. 5(1)(e)).';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('retention.export_files_days', 8));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        foreach (Storage::disk('local')->allFiles('exports') as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff->timestamp) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        Log::info('organizations:cleanup-exports completed', [
            'days' => $days,
            'deleted' => $deleted,
        ]);

        $this->info("Deleted {$deleted} export file(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
