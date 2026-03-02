<?php

declare(strict_types=1);

namespace App\Channels;

use App\Services\Email\EmailService;
use Illuminate\Notifications\Notification;

/**
 * Custom notification channel that sends emails through EmailService.
 *
 * Replaces Laravel's built-in MailChannel for notifications that use
 * EmailService::sendFromTemplate(), preventing double-send issues.
 *
 * EmailService provides: database templates, EmailSend tracking,
 * idempotency, suppression lists, and GDPR consent checking.
 */
class EmailServiceChannel
{
    public function __construct(
        private readonly EmailService $emailService
    ) {}

    /**
     * Send the given notification via EmailService.
     *
     * @param  mixed  $notifiable
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $notification->toEmailService($notifiable, $this->emailService);
    }
}
