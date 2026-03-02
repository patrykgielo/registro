<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Helpers\PrivacyHelper;
use App\Support\Settings\SettingsManager;
use Illuminate\Support\Facades\Log;
use Smsapi\Client\Curl\SmsapiHttpClient;
use Smsapi\Client\Feature\Sms\Bag\SendSmsBag;

/**
 * SMSAPI.pl Gateway Implementation
 *
 * Sends SMS via SMSAPI.pl using official PHP SDK.
 * Configuration loaded from SettingsManager at runtime.
 *
 * Requirements:
 * - Composer package: composer require smsapi/php-client
 * - SMSAPI account with OAuth token
 *
 * Documentation: https://www.smsapi.pl/docs
 */
class SmsApiGateway implements SmsGatewayInterface
{
    /**
     * SMSAPI Service instance (PlService or ComService).
     */
    private $service = null;

    /**
     * Create a new SMSAPI Gateway instance.
     */
    public function __construct(
        private readonly SettingsManager $settings
    ) {}

    /**
     * Send an SMS via SMSAPI.pl.
     *
     * @param  string  $to  Recipient phone number in international format (+48...)
     * @param  string  $message  SMS message content (plain text)
     * @param  array  $metadata  Additional data (sender_name, test_mode, etc.)
     * @return array{sms_id: string, message_length: int, message_parts: int} SMS ID and metadata
     *
     * @throws \Exception If sending fails
     */
    public function send(
        string $to,
        string $message,
        array $metadata = []
    ): array {
        // Initialize outside try so catch block can reference them
        $smsSettings = $this->settings->group('sms');
        $senderName = $metadata['sender_name'] ?? $smsSettings['sender_name'] ?? 'Registro';
        $testMode = $metadata['test_mode'] ?? $smsSettings['test_mode'] ?? false;

        try {
            // Initialize SMSAPI service
            $service = $this->getService();

            // Normalize phone number (remove spaces, dashes)
            $to = $this->normalizePhoneNumber($to);

            // Validate phone number
            if (! $this->validatePhoneNumber($to)) {
                throw new \Exception("Invalid phone number format: {$to}. Must be in international format (e.g., +48501234567)");
            }

            // Calculate message length
            $lengthInfo = $this->calculateMessageLength($message);

            // Prepare SMS bag
            $sms = SendSmsBag::withMessage($to, $message);
            $sms->from = $senderName;

            // Enable test mode if configured (sandbox)
            if ($testMode) {
                $sms->test = true;
            }

            // Log SMS sending attempt
            Log::debug('Sending SMS via SMSAPI', [
                'to' => PrivacyHelper::maskPhone($to),
                'from' => $senderName,
                'test_mode' => $testMode,
                'message_length' => $lengthInfo['length'],
                'message_parts' => $lengthInfo['parts'],
                'message_preview' => mb_substr($message, 0, 50).'...',
            ]);

            // Send SMS via SMSAPI
            $smsFeature = $service->smsFeature();
            $response = $smsFeature->sendSms($sms);

            // Extract SMS ID from response
            $smsId = $response->id ?? 'unknown';

            Log::info('SMS sent successfully via SMSAPI', [
                'to' => PrivacyHelper::maskPhone($to),
                'sms_id' => $smsId,
                'message_length' => $lengthInfo['length'],
                'message_parts' => $lengthInfo['parts'],
                'test_mode' => $testMode,
            ]);

            return [
                'sms_id' => $smsId,
                'message_length' => $lengthInfo['length'],
                'message_parts' => $lengthInfo['parts'],
            ];
        } catch (\Throwable $e) {
            Log::error('SMSAPI sending failed', [
                'to' => PrivacyHelper::maskPhone($to),
                'from' => $senderName,
                'test_mode' => $testMode,
                'error' => $e->getMessage(),
                'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown',
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception("Failed to send SMS via SMSAPI: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate phone number format.
     *
     * Checks if phone number is in international format (+48...).
     *
     * @param  string  $phoneNumber  Phone number to validate
     * @return bool True if valid international format
     */
    public function validatePhoneNumber(string $phoneNumber): bool
    {
        // International format: +[country code][number]
        // Example: +48501234567 (Poland)
        // Pattern: +{1,4 digits}{6-14 digits}
        return (bool) preg_match('/^\+\d{1,4}\d{6,14}$/', $phoneNumber);
    }

    /**
     * Calculate SMS length and number of parts.
     *
     * GSM-7 encoding: 160 chars per part (153 for multi-part)
     * Unicode encoding: 70 chars per part (67 for multi-part)
     *
     * @param  string  $message  SMS message content
     * @return array{length: int, parts: int, encoding: string} Length info
     */
    public function calculateMessageLength(string $message): array
    {
        $length = mb_strlen($message);
        $isUnicode = $this->containsUnicode($message);

        // Determine encoding and limits
        if ($isUnicode) {
            $encoding = 'Unicode';
            $singlePartLimit = 70;
            $multiPartLimit = 67;
        } else {
            $encoding = 'GSM-7';
            $singlePartLimit = 160;
            $multiPartLimit = 153;
        }

        // Calculate number of parts
        if ($length <= $singlePartLimit) {
            $parts = 1;
        } else {
            $parts = (int) ceil($length / $multiPartLimit);
        }

        return [
            'length' => $length,
            'parts' => $parts,
            'encoding' => $encoding,
        ];
    }

    /**
     * Initialize and return SMSAPI service (PlService or ComService).
     *
     * @return \Smsapi\Client\Service\SmsapiPlService|\Smsapi\Client\Service\SmsapiComService
     *
     * @throws \Exception If configuration is invalid
     */
    private function getService()
    {
        if ($this->service !== null) {
            return $this->service;
        }

        // Get API token from config (env)
        $apiToken = config('services.smsapi.token');
        if (empty($apiToken)) {
            throw new \Exception('SMSAPI token not configured. Set SMSAPI_TOKEN in .env file');
        }

        // Determine service (pl or com) - check settings first, then config
        $smsSettings = $this->settings->group('sms');
        $serviceType = $smsSettings['service'] ?? config('services.smsapi.service', 'pl');

        // Create SMSAPI HTTP client (Curl adapter)
        $client = new SmsapiHttpClient;

        // Get the appropriate service (pl or com)
        if ($serviceType === 'pl') {
            $this->service = $client->smsapiPlService($apiToken);
        } else {
            $this->service = $client->smsapiComService($apiToken);
        }

        Log::debug('SMSAPI service initialized', [
            'service' => $serviceType,
            'token_prefix' => substr($apiToken, 0, 10).'...',
        ]);

        return $this->service;
    }

    /**
     * Normalize phone number by removing spaces and dashes.
     *
     * @param  string  $phoneNumber  Phone number to normalize
     * @return string Normalized phone number
     */
    private function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/[\s\-]/', '', $phoneNumber);
    }

    /**
     * Check if message contains Unicode characters.
     *
     * @param  string  $message  Message to check
     * @return bool True if contains Unicode
     */
    private function containsUnicode(string $message): bool
    {
        // GSM-7 character set (simplified check)
        // If any character is outside GSM-7, treat as Unicode
        return (bool) preg_match('/[^\x00-\x7F]/', $message);
    }
}
