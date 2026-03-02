<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Models\SmsEvent;
use App\Models\SmsSend;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CleanupOldSmsLogsJob
 *
 * Scheduled Job (runs daily at 2:30 AM)
 * Deletes SMS logs older than 90 days (GDPR compliance)
 *
 * Business Logic:
 * - Deletes SmsSend records older than 90 days
 * - Cascade deletes related SmsEvent records (via foreign key)
 * - Keeps SmsSuppression records indefinitely (opt-out list)
 * - Respects GDPR 90-day retention policy
 * - Logs deletion statistics
 *
 * Scheduled: Daily at 2:30 AM via Laravel Scheduler
 * Queue: default
 */
class CleanupOldSmsLogsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 600; // 10 minutes (may delete many records)

    /**
     * Retention period in days (GDPR compliance).
     */
    private const RETENTION_DAYS = 90;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * Get the unique ID for the job (ensures only one instance runs at a time).
     */
    public function uniqueId(): string
    {
        return 'cleanup-old-sms-logs:'.now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[CleanupOldSmsLogsJob] Starting SMS logs cleanup');

        $cutoffDate = Carbon::now()->subDays(self::RETENTION_DAYS);

        Log::info('[CleanupOldSmsLogsJob] Deleting records older than {date}', [
            'date' => $cutoffDate->format('Y-m-d H:i:s'),
            'retention_days' => self::RETENTION_DAYS,
        ]);

        $stats = [
            'sms_sends_deleted' => 0,
            'sms_events_deleted' => 0,
        ];

        try {
            // Step 1: Delete old SmsEvent records first (to avoid foreign key issues if cascade not working)
            $eventsDeleted = SmsEvent::whereHas('smsSend', function ($query) use ($cutoffDate) {
                $query->where('created_at', '<', $cutoffDate);
            })->delete();

            $stats['sms_events_deleted'] = $eventsDeleted;

            Log::info('[CleanupOldSmsLogsJob] Deleted old SmsEvent records', [
                'count' => $eventsDeleted,
            ]);

            // Step 2: Delete old SmsSend records
            $sendsDeleted = SmsSend::where('created_at', '<', $cutoffDate)->delete();

            $stats['sms_sends_deleted'] = $sendsDeleted;

            Log::info('[CleanupOldSmsLogsJob] Deleted old SmsSend records', [
                'count' => $sendsDeleted,
            ]);

            Log::info('[CleanupOldSmsLogsJob] Cleanup completed successfully', $stats);
        } catch (\Exception $e) {
            Log::error('[CleanupOldSmsLogsJob] Cleanup failed', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }
}
