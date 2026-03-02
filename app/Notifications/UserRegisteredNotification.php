<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * User Registered Notification
 *
 * Sent when a new user registers on the platform.
 * Queued with uniqueness to prevent duplicate welcome emails.
 */
class UserRegisteredNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public User $user
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'user-registered:'.$this->user->id;
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

        try {
            $emailService->sendFromTemplate(
                TemplateKey::USER_REGISTERED->value,
                $language,
                $notifiable->email,
                [
                    'user_name' => $notifiable->name,
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                    'user_email' => $notifiable->email,
                ],
                [
                    'user_id' => $notifiable->id,
                    'notification' => 'UserRegisteredNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('UserRegisteredNotification failed', [
                'user_id' => $notifiable->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
