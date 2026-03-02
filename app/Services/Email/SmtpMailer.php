<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Support\Settings\SettingsManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP Email Gateway Implementation
 *
 * Sends emails via SMTP using Laravel's Mail facade.
 * SMTP configuration loaded from SettingsManager at runtime.
 */
class SmtpMailer implements EmailGatewayInterface
{
    /**
     * Create a new SMTP Mailer instance.
     */
    public function __construct(
        private readonly SettingsManager $settings
    ) {}

    /**
     * Send an email via SMTP.
     *
     * @param  string  $to  Recipient email address
     * @param  string  $subject  Email subject line
     * @param  string  $htmlBody  HTML email body
     * @param  string|null  $textBody  Plain text email body (optional)
     * @param  array  $metadata  Additional data (not used for basic SMTP)
     * @return bool True if sent successfully
     *
     * @throws \Exception If sending fails
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $metadata = []
    ): bool {
        try {
            // Load SMTP settings from database
            $this->configureSmtp();

            // Send email using Laravel Mail facade
            Mail::mailer('smtp')->send([], [], function (Message $message) use ($to, $subject, $htmlBody, $textBody) {
                $message->to($to)
                    ->subject($subject);

                // Set HTML body
                $message->html($htmlBody);

                // Set plain text body if provided
                if ($textBody) {
                    $message->text($textBody);
                }
            });

            Log::info('Email sent successfully via SMTP', [
                'to' => $to,
                'subject' => $subject,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SMTP email sending failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception("Failed to send email via SMTP: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Configure Laravel's SMTP mailer at runtime using database settings.
     *
     * Overrides config/mail.php settings with values from SettingsManager.
     */
    private function configureSmtp(): void
    {
        $emailSettings = $this->settings->group('email');

        // Override SMTP configuration at runtime
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $emailSettings['smtp_host'] ?? env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => $emailSettings['smtp_port'] ?? env('MAIL_PORT', 587),
            'encryption' => $emailSettings['smtp_encryption'] ?? env('MAIL_ENCRYPTION', 'tls'),
            'username' => $emailSettings['smtp_username'] ?? env('MAIL_USERNAME'),
            'password' => $emailSettings['smtp_password'] ?? env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ]);

        // Set from address and name
        Config::set('mail.from', [
            'address' => $emailSettings['from_address'] ?? env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'name' => $emailSettings['from_name'] ?? env('MAIL_FROM_NAME', 'Example'),
        ]);

        Log::debug('SMTP configuration loaded from database', [
            'host' => $emailSettings['smtp_host'] ?? 'env fallback',
            'port' => $emailSettings['smtp_port'] ?? 'env fallback',
            'from' => $emailSettings['from_address'] ?? 'env fallback',
        ]);
    }
}
