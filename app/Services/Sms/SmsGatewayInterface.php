<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * SMS Gateway Interface
 *
 * Abstraction layer for SMS delivery providers (SMSAPI.pl, Twilio, etc.).
 * Allows swapping SMS backends without changing business logic.
 */
interface SmsGatewayInterface
{
    /**
     * Send an SMS via the gateway.
     *
     * @param  string  $to  Recipient phone number in international format (+48...)
     * @param  string  $message  SMS message content (plain text)
     * @param  array  $metadata  Additional data (sender name, test mode, etc.)
     * @return array{sms_id: string, message_length: int, message_parts: int} SMS ID and metadata
     *
     * @throws \Exception If sending fails
     */
    public function send(
        string $to,
        string $message,
        array $metadata = []
    ): array;

    /**
     * Validate phone number format.
     *
     * @param  string  $phoneNumber  Phone number to validate
     * @return bool True if valid international format
     */
    public function validatePhoneNumber(string $phoneNumber): bool;

    /**
     * Calculate SMS length and number of parts.
     *
     * @param  string  $message  SMS message content
     * @return array{length: int, parts: int, encoding: string} Length info
     */
    public function calculateMessageLength(string $message): array;
}
