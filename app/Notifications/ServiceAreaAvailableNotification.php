<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\ServiceAreaWaitlist;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Service Area Available Notification
 *
 * Sent to a waitlist entry's email address when the admin marks
 * their requested location as now covered by a service area.
 */
class ServiceAreaAvailableNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public ServiceAreaWaitlist $waitlist,
    ) {
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return 'service-area-available:'.$this->waitlist->id;
    }

    public function uniqueFor(): int
    {
        return 300; // 5 minutes
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
        try {
            $emailService->sendFromTemplate(
                TemplateKey::SERVICE_AREA_AVAILABLE->value,
                'pl',
                $this->waitlist->email,
                [
                    'name' => $this->waitlist->name ?? 'Klientko/Kliencie',
                    'requested_address' => $this->waitlist->requested_address,
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'waitlist_id' => $this->waitlist->id,
                    'notification' => 'ServiceAreaAvailableNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('ServiceAreaAvailableNotification failed', [
                'waitlist_id' => $this->waitlist->id,
                'email' => $this->waitlist->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
