<?php

declare(strict_types=1);

namespace App\Jobs\Reminder;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\EmailSuppression;
use App\Models\ReminderConfig;
use App\Models\ReminderLog;
use App\Models\SmsSuppression;
use App\Services\Email\EmailService;
use App\Services\Sms\SmsService;
use App\Support\Settings\SettingsManager;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProcessRemindersJob - Unified Reminder Processing
 *
 * This job replaces the legacy hardcoded jobs:
 * - SendReminderSmsJob (24h, 2h)
 * - Send2hReminderSmsJob
 * - SendReminderEmailsJob (24h, 2h)
 * - Send2hReminderEmailsJob
 * - SendFollowUpSmsJob
 * - SendFollowUpEmailsJob
 *
 * Features:
 * - Reads configuration from reminder_configs table
 * - Uses reminder_logs for idempotency (no more boolean flags)
 * - Supports dynamic timing (any hour/minute offset)
 * - Admin can add/remove/configure reminders without code changes
 *
 * Scheduled: Hourly via Laravel Scheduler
 * Queue: reminders
 */
class ProcessRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes for processing all configs

    public function __construct()
    {
        $this->onQueue('reminders');
    }

    public function uniqueId(): string
    {
        return 'process-reminders:'.now()->format('Y-m-d-H');
    }

    public function handle(
        SmsService $smsService,
        EmailService $emailService,
        SettingsManager $settings
    ): void {
        Log::info('[ProcessRemindersJob] Starting unified reminder processing');

        $smsSettings = $settings->group('sms');
        $smsEnabled = $smsSettings['enabled'] ?? true;

        // Load all enabled configs ordered by priority
        $configs = ReminderConfig::enabled()
            ->orderBy('priority')
            ->get();

        if ($configs->isEmpty()) {
            Log::info('[ProcessRemindersJob] No enabled reminder configs found');

            return;
        }

        Log::info('[ProcessRemindersJob] Processing {count} reminder configurations', [
            'count' => $configs->count(),
        ]);

        $stats = [
            'configs_processed' => 0,
            'sms_sent' => 0,
            'email_sent' => 0,
            'skipped_suppressed' => 0,
            'skipped_already_sent' => 0,
            'failed' => 0,
        ];

        foreach ($configs as $config) {
            // Skip SMS configs if SMS is globally disabled
            if ($config->channel === 'sms' && ! $smsEnabled) {
                Log::debug('[ProcessRemindersJob] Skipping SMS config - SMS globally disabled', [
                    'config_id' => $config->id,
                    'config_name' => $config->name,
                ]);

                continue;
            }

            $this->processConfig($config, $smsService, $emailService, $settings, $stats);
            $stats['configs_processed']++;
        }

        Log::info('[ProcessRemindersJob] Completed unified reminder processing', $stats);
    }

    /**
     * Process a single reminder configuration
     */
    private function processConfig(
        ReminderConfig $config,
        SmsService $smsService,
        EmailService $emailService,
        SettingsManager $settings,
        array &$stats
    ): void {
        Log::debug('[ProcessRemindersJob] Processing config', [
            'config_id' => $config->id,
            'name' => $config->name,
            'channel' => $config->channel,
            'trigger_type' => $config->trigger_type,
            'trigger_hours' => $config->trigger_hours,
        ]);

        // Find appointments matching this config's time window
        $appointments = $this->findAppointmentsForConfig($config);

        if ($appointments->isEmpty()) {
            Log::debug('[ProcessRemindersJob] No appointments found for config', [
                'config_id' => $config->id,
            ]);

            return;
        }

        Log::info('[ProcessRemindersJob] Found {count} appointments for config "{name}"', [
            'count' => $appointments->count(),
            'config_id' => $config->id,
            'name' => $config->name,
        ]);

        foreach ($appointments as $appointment) {
            $this->processAppointmentReminder(
                $appointment,
                $config,
                $smsService,
                $emailService,
                $settings,
                $stats
            );
        }
    }

    /**
     * Find appointments that match the config's time window
     */
    private function findAppointmentsForConfig(ReminderConfig $config): Collection
    {
        $now = Carbon::now();
        $offsetMinutes = $config->getTriggerMinutesTotal();
        $bufferMinutes = $config->window_buffer_minutes;

        // Calculate time window based on trigger type
        if ($config->trigger_type === 'before') {
            // Before appointment: look for appointments in the future
            // e.g., 24h before with 60min buffer = appointments between 23h and 25h from now
            $windowStart = $now->copy()->addMinutes($offsetMinutes - $bufferMinutes);
            $windowEnd = $now->copy()->addMinutes($offsetMinutes + $bufferMinutes);
            $statusFilter = [AppointmentStatus::Confirmed]; // Only confirmed appointments get reminders
        } else {
            // After appointment (follow-up): look for completed appointments in the past
            // e.g., 24h after with 60min buffer = appointments between 23h and 25h ago
            $windowStart = $now->copy()->subMinutes($offsetMinutes + $bufferMinutes);
            $windowEnd = $now->copy()->subMinutes($offsetMinutes - $bufferMinutes);
            $statusFilter = [AppointmentStatus::Completed]; // Only completed appointments get follow-ups
        }

        $query = Appointment::query()
            ->with(['customer', 'service'])
            ->whereIn('status', $statusFilter)
            ->whereBetween(
                DB::raw("CONCAT(appointment_date, ' ', start_time)"),
                [$windowStart->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')]
            );

        // Add channel-specific filters
        if ($config->channel === 'sms') {
            $query->whereNotNull('phone')
                ->where('phone', '!=', '');
        } else {
            $query->whereNotNull('email')
                ->where('email', '!=', '');
        }

        return $query->get();
    }

    /**
     * Process a single appointment for a reminder config
     */
    private function processAppointmentReminder(
        Appointment $appointment,
        ReminderConfig $config,
        SmsService $smsService,
        EmailService $emailService,
        SettingsManager $settings,
        array &$stats
    ): void {
        // Check idempotency - was this reminder already sent?
        if (ReminderLog::alreadySent($appointment->id, $config->id)) {
            $stats['skipped_already_sent']++;
            Log::debug('[ProcessRemindersJob] Skipping - already sent', [
                'appointment_id' => $appointment->id,
                'config_id' => $config->id,
            ]);

            return;
        }

        // Check suppression
        if ($config->channel === 'sms') {
            if (SmsSuppression::isSuppressed($appointment->phone)) {
                $stats['skipped_suppressed']++;
                Log::debug('[ProcessRemindersJob] Skipping - phone suppressed', [
                    'appointment_id' => $appointment->id,
                    'phone' => $appointment->phone,
                ]);

                return;
            }
        } else {
            if (EmailSuppression::isSuppressed($appointment->email)) {
                $stats['skipped_suppressed']++;
                Log::debug('[ProcessRemindersJob] Skipping - email suppressed', [
                    'appointment_id' => $appointment->id,
                    'email' => $appointment->email,
                ]);

                return;
            }
        }

        // Create pending log entry (for idempotency)
        $log = ReminderLog::create([
            'appointment_id' => $appointment->id,
            'reminder_config_id' => $config->id,
            'channel' => $config->channel,
            'message_key' => ReminderLog::generateMessageKey($appointment->id, $config->id),
            'status' => 'pending',
        ]);

        try {
            // Prepare common data
            $data = $this->prepareTemplateData($appointment, $settings);
            $language = $appointment->customer?->preferred_language ?? 'pl';

            // Send via appropriate channel
            if ($config->channel === 'sms') {
                $result = $smsService->sendFromTemplate(
                    $config->template_key->value,
                    $language,
                    $appointment->phone,
                    $data,
                    [
                        'appointment_id' => $appointment->id,
                        'reminder_config_id' => $config->id,
                        'reminder_type' => $config->trigger_type,
                    ]
                );

                $log->markAsSent($result['sms_send_id'] ?? null);
                $stats['sms_sent']++;
            } else {
                $result = $emailService->sendFromTemplate(
                    $config->template_key->value,
                    $language,
                    $appointment->email,
                    $data,
                    [
                        'appointment_id' => $appointment->id,
                        'reminder_config_id' => $config->id,
                        'reminder_type' => $config->trigger_type,
                    ]
                );

                $log->markAsSent($result['email_send_id'] ?? null);
                $stats['email_sent']++;
            }

            Log::info('[ProcessRemindersJob] Reminder sent successfully', [
                'appointment_id' => $appointment->id,
                'config_id' => $config->id,
                'config_name' => $config->name,
                'channel' => $config->channel,
            ]);
        } catch (\Exception $e) {
            $stats['failed']++;
            $log->markAsFailed($e->getMessage());

            Log::error('[ProcessRemindersJob] Failed to send reminder', [
                'appointment_id' => $appointment->id,
                'config_id' => $config->id,
                'channel' => $config->channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Prepare template data from appointment
     */
    private function prepareTemplateData(Appointment $appointment, SettingsManager $settings): array
    {
        return [
            'customer_name' => trim(($appointment->first_name ?? '').' '.($appointment->last_name ?? '')),
            'appointment_date' => $appointment->appointment_date?->format('Y-m-d') ?? '',
            'appointment_time' => $appointment->start_time?->format('H:i') ?? '',
            'service_name' => $appointment->service?->name ?? 'N/A',
            'location_address' => $appointment->formatted_location ?? $appointment->location_address ?? '',
            'app_name' => $settings->appName(),
            'contact_phone' => $settings->get('contact.phone', ''),
            'contact_email' => config('mail.from.address'),
        ];
    }
}
