<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Appointment;
use App\Services\Email\EmailService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Appointment Rescheduled Notification
 *
 * Sent when an appointment date/time is changed.
 * Informs customer about the new schedule.
 */
class AppointmentRescheduledNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $whoChanged  'customer' or 'staff'
     */
    public function __construct(
        public Appointment $appointment,
        public Carbon $oldDate,
        public Carbon $newDate,
        public string $whoChanged = 'staff'
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'appointment-rescheduled:'.$this->appointment->id.':'.$this->newDate->timestamp;
    }

    /**
     * Get the number of seconds the unique lock should be maintained.
     */
    public function uniqueFor(): int
    {
        return 300; // 5 minutes
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    /**
     * Send via EmailService channel.
     *
     * @param  mixed  $notifiable
     */
    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        $language = $notifiable->preferred_language ?? 'pl';

        // Load relationships
        $appointment = $this->appointment->load(['service', 'customer']);

        try {
            $emailService->sendFromTemplate(
                TemplateKey::APPOINTMENT_RESCHEDULED->value,
                $language,
                $notifiable->email,
                [
                    'customer_name' => $appointment->customer->name,
                    'service_name' => $appointment->service->name,
                    'old_date' => $this->oldDate->format('Y-m-d H:i'),
                    'new_date' => $this->newDate->format('Y-m-d H:i'),
                    'who_changed' => $this->whoChanged,
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'appointment_id' => $appointment->id,
                    'notification' => 'AppointmentRescheduledNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('AppointmentRescheduledNotification failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
