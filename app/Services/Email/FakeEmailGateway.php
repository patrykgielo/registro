<?php

declare(strict_types=1);

namespace App\Services\Email;

use Illuminate\Support\Facades\Log;

/**
 * Fake Email Gateway for Testing
 *
 * Prevents actual email sending in test environment.
 * Logs email attempts for verification in tests.
 */
class FakeEmailGateway implements EmailGatewayInterface
{
    /**
     * Fake send - logs email but doesn't actually send.
     *
     * @param  string  $to  Recipient email address
     * @param  string  $subject  Email subject line
     * @param  string  $htmlBody  HTML email body
     * @param  string|null  $textBody  Plain text email body (optional)
     * @param  array  $metadata  Additional data
     * @return bool Always returns true
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $metadata = []
    ): bool {
        Log::info('FakeEmailGateway: Email captured (not sent)', [
            'to' => $to,
            'subject' => $subject,
            'metadata' => $metadata,
        ]);

        return true;
    }
}
