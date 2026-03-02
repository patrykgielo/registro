<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Appointment;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Appointment Cancelled Notification
 *
 * Sent when an appointment is cancelled.
 * Includes cancellation reason if provided.
 */
class AppointmentCancelledNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string|null  $reason  Cancellation reason
     */
    public function __construct(
        public Appointment $appointment,
        public ?string $reason = null
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'appointment-cancelled:'.$this->appointment->id;
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
                TemplateKey::APPOINTMENT_CANCELLED->value,
                $language,
                $notifiable->email,
                [
                    'customer_name' => $appointment->customer->name,
                    'service_name' => $appointment->service->name,
                    'appointment_date' => $appointment->appointment_date->format('Y-m-d'),
                    'reason' => $this->reason ?? 'Nie podano powodu',
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'appointment_id' => $appointment->id,
                    'notification' => 'AppointmentCancelledNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('AppointmentCancelledNotification failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
