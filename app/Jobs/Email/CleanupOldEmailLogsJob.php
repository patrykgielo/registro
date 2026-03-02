<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Models\EmailEvent;
use App\Models\EmailSend;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CleanupOldEmailLogsJob
 *
 * Scheduled Job (runs daily at 2:00 AM)
 * Deletes email logs older than 90 days (GDPR compliance)
 *
 * Business Logic:
 * - Deletes EmailSend records older than 90 days
 * - Cascade deletes related EmailEvent records (via foreign key)
 * - Keeps EmailSuppression records indefinitely (unsubscribe list)
 * - Respects GDPR 90-day retention policy
 * - Logs deletion statistics
 *
 * Scheduled: Daily at 2:00 AM via Laravel Scheduler
 * Queue: default
 */
class CleanupOldEmailLogsJob implements ShouldBeUnique, ShouldQueue
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
        return 'cleanup-old-email-logs:'.now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[CleanupOldEmailLogsJob] Starting email logs cleanup');

        $cutoffDate = Carbon::now()->subDays(self::RETENTION_DAYS);

        Log::info('[CleanupOldEmailLogsJob] Deleting records older than {date}', [
            'date' => $cutoffDate->format('Y-m-d H:i:s'),
            'retention_days' => self::RETENTION_DAYS,
        ]);

        $stats = [
            'email_sends_deleted' => 0,
            'email_events_deleted' => 0,
        ];

        try {
            // Step 1: Delete old EmailEvent records first (to avoid foreign key issues if cascade not working)
            $eventsDeleted = EmailEvent::whereHas('emailSend', function ($query) use ($cutoffDate) {
                $query->where('created_at', '<', $cutoffDate);
            })->delete();

            $stats['email_events_deleted'] = $eventsDeleted;

            Log::info('[CleanupOldEmailLogsJob] Deleted old EmailEvent records', [
                'count' => $eventsDeleted,
            ]);

            // Step 2: Delete old EmailSend records
            $sendsDeleted = EmailSend::where('created_at', '<', $cutoffDate)->delete();

            $stats['email_sends_deleted'] = $sendsDeleted;

            Log::info('[CleanupOldEmailLogsJob] Deleted old EmailSend records', [
                'count' => $sendsDeleted,
            ]);

            Log::info('[CleanupOldEmailLogsJob] Cleanup completed successfully', $stats);
        } catch (\Exception $e) {
            Log::error('[CleanupOldEmailLogsJob] Cleanup failed', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }
}
