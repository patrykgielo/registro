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
 * Password Reset Notification
 *
 * Sent when a user requests a password reset.
 * Contains secure token link for resetting password.
 */
class PasswordResetNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $token  Password reset token
     */
    public function __construct(
        public User $user,
        public string $token
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'password-reset:'.$this->user->id.':'.substr($this->token, 0, 8);
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

        // Build password reset URL
        $resetUrl = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ], false));

        try {
            $emailService->sendFromTemplate(
                TemplateKey::PASSWORD_RESET->value,
                $language,
                $notifiable->email,
                [
                    'user_name' => $notifiable->name,
                    'reset_url' => $resetUrl,
                    'token' => $this->token,
                ],
                [
                    'user_id' => $notifiable->id,
                    'notification' => 'PasswordResetNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('PasswordResetNotification failed', [
                'user_id' => $notifiable->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
