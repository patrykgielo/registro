<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * Email Gateway Interface
 *
 * Abstraction layer for email delivery providers (SMTP, API-based services, etc.).
 * Allows swapping email backends without changing business logic.
 */
interface EmailGatewayInterface
{
    /**
     * Send an email via the gateway.
     *
     * @param  string  $to  Recipient email address
     * @param  string  $subject  Email subject line
     * @param  string  $htmlBody  HTML email body
     * @param  string|null  $textBody  Plain text email body (optional)
     * @param  array  $metadata  Additional data (CC, BCC, attachments, headers, etc.)
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
    ): bool;
}
