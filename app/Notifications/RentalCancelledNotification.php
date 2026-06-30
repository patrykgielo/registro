<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Rental;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Rental Cancelled Notification
 *
 * Sent to the customer when a rental is cancelled (by admin or offboarding).
 * Queue: emails | Unique per rental for 5 minutes.
 */
class RentalCancelledNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Rental $rental,
        public string $reason = ''
    ) {
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return 'rental-cancelled:'.$this->rental->id;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        $language = $notifiable->preferred_language ?? 'pl';
        $rental = $this->rental->load(['service', 'customer']);
        $customerName = trim(($rental->first_name ?? $rental->customer?->first_name ?? '').' '.($rental->last_name ?? $rental->customer?->last_name ?? ''));

        try {
            $emailService->sendFromTemplate(
                TemplateKey::RENTAL_CANCELLED->value,
                $language,
                $notifiable->email,
                [
                    'customer_name' => $customerName ?: 'Klient',
                    'service_name' => $rental->service?->name ?? 'N/A',
                    'start_date' => $rental->start_date?->format('Y-m-d') ?? 'N/A',
                    'end_date' => $rental->end_date?->format('Y-m-d') ?? 'N/A',
                    'reason' => $this->reason ?: 'Nie podano powodu',
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'rental_id' => $rental->id,
                    'notification' => 'RentalCancelledNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('RentalCancelledNotification failed', [
                'rental_id' => $rental->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
