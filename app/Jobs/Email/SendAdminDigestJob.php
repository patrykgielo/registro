<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Enums\TemplateKey;
use App\Models\Appointment;
use App\Models\EmailSend;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\Email\EmailService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SendAdminDigestJob
 *
 * Scheduled Job (runs daily at 8:00 AM)
 * Sends daily summary email to admins with statistics
 *
 * Business Logic:
 * - Collects statistics from last 24 hours:
 *   - New appointments (all statuses)
 *   - Confirmed appointments
 *   - Cancelled appointments
 *   - Completed appointments
 *   - Upcoming appointments for today
 *   - Email stats (sent, failed, bounced)
 * - Sends to all users with 'admin' or 'super-admin' role
 * - Respects email suppression list
 *
 * Scheduled: Daily at 8:00 AM via Laravel Scheduler
 * Queue: emails
 */
class SendAdminDigestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the job (ensures only one instance runs at a time).
     */
    public function uniqueId(): string
    {
        return 'send-admin-digest:'.now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(EmailService $emailService): void
    {
        Log::info('[SendAdminDigestJob] Starting admin digest email processing');

        // Collect statistics from last 24 hours
        $yesterday = Carbon::yesterday();
        $today = Carbon::today();

        $stats = [
            // Appointment stats (created yesterday)
            'new_appointments' => Appointment::whereBetween('created_at', [$yesterday, $today])->count(),
            'confirmed_appointments' => Appointment::where('status', 'confirmed')
                ->whereBetween('created_at', [$yesterday, $today])
                ->count(),
            'cancelled_appointments' => Appointment::where('status', 'cancelled')
                ->whereBetween('updated_at', [$yesterday, $today])
                ->count(),
            'completed_appointments' => Appointment::where('status', 'completed')
                ->whereBetween('updated_at', [$yesterday, $today])
                ->count(),

            // Upcoming appointments for today
            'today_appointments' => Appointment::whereIn('status', ['confirmed', 'pending'])
                ->whereDate('appointment_date', $today)
                ->count(),

            // Email stats (sent yesterday)
            'emails_sent' => EmailSend::where('status', 'sent')
                ->whereBetween('sent_at', [$yesterday, $today])
                ->count(),
            'emails_failed' => EmailSend::where('status', 'failed')
                ->whereBetween('created_at', [$yesterday, $today])
                ->count(),
            'emails_bounced' => EmailSend::where('status', 'bounced')
                ->whereBetween('created_at', [$yesterday, $today])
                ->count(),
        ];

        // Get recent appointments for listing
        $recentAppointments = Appointment::with(['customer', 'service'])
            ->whereBetween('created_at', [$yesterday, $today])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($apt) => [
                'id' => $apt->id,
                'customer_name' => $apt->customer_name,
                'customer_email' => $apt->customer_email,
                'service_name' => $apt->service?->name ?? 'N/A',
                'appointment_date' => $apt->appointment_date->format('Y-m-d'),
                'appointment_time' => $apt->start_time->format('H:i'),
                'status' => $apt->status,
                'created_at' => $apt->created_at->format('Y-m-d H:i'),
            ])
            ->toArray();

        // Get today's appointments
        $todayAppointments = Appointment::with(['customer', 'service'])
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereDate('appointment_date', $today)
            ->orderBy('start_time')
            ->get()
            ->map(fn ($apt) => [
                'id' => $apt->id,
                'customer_name' => $apt->customer_name,
                'service_name' => $apt->service?->name ?? 'N/A',
                'time' => $apt->start_time->format('H:i'),
                'status' => $apt->status,
                'location' => $apt->formatted_location,
            ])
            ->toArray();

        Log::info('[SendAdminDigestJob] Collected statistics', $stats);

        // Get all admin users
        $admins = User::role(['super-admin', 'admin'])->get();

        $sentCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($admins as $admin) {
            try {
                // Skip if email is suppressed
                if (EmailSuppression::isSuppressed($admin->email)) {
                    Log::warning('[SendAdminDigestJob] Skipping admin digest - email suppressed', [
                        'admin_id' => $admin->id,
                        'email' => $admin->email,
                    ]);
                    $skippedCount++;

                    continue;
                }

                // Get admin language preference
                $language = $admin->preferred_language ?? 'pl';

                // Prepare email data
                $data = [
                    'admin_name' => $admin->name,
                    'digest_date' => $yesterday->format('Y-m-d'),
                    'new_appointments' => $stats['new_appointments'],
                    'confirmed_appointments' => $stats['confirmed_appointments'],
                    'cancelled_appointments' => $stats['cancelled_appointments'],
                    'completed_appointments' => $stats['completed_appointments'],
                    'today_appointments' => $stats['today_appointments'],
                    'emails_sent' => $stats['emails_sent'],
                    'emails_failed' => $stats['emails_failed'],
                    'emails_bounced' => $stats['emails_bounced'],
                    'recent_appointments' => $recentAppointments,
                    'today_appointments_list' => $todayAppointments,
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                    'admin_panel_url' => config('app.url').'/admin',
                ];

                // Send email
                $emailService->sendFromTemplate(
                    TemplateKey::ADMIN_DAILY_DIGEST->value,
                    $language,
                    $admin->email,
                    $data,
                    [
                        'admin_id' => $admin->id,
                        'digest_date' => $yesterday->format('Y-m-d'),
                    ]
                );

                $sentCount++;

                Log::info('[SendAdminDigestJob] Admin digest sent successfully', [
                    'admin_id' => $admin->id,
                    'email' => $admin->email,
                ]);
            } catch (\Exception $e) {
                $failedCount++;

                Log::error('[SendAdminDigestJob] Failed to send admin digest', [
                    'admin_id' => $admin->id,
                    'email' => $admin->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SendAdminDigestJob] Completed admin digest processing', [
            'sent' => $sentCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
        ]);
    }
}
