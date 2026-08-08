<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Helpers\PrivacyHelper;
use App\Mail\SmsSpendingAlertMail;
use App\Models\SmsEvent;
use App\Models\SmsSend;
use App\Models\SmsSuppression;
use App\Models\SmsTemplate;
use App\Support\Settings\SettingsManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SMS Service
 *
 * Core SMS sending service with template rendering, idempotency, and suppression list.
 * Integrates with SmsGateway for actual delivery.
 */
class SmsService
{
    /**
     * Create a new SMS Service instance.
     */
    public function __construct(
        private readonly SmsGatewayInterface $gateway,
        private readonly SettingsManager $settings
    ) {}

    /**
     * Send SMS from template with full tracking and error handling.
     *
     * This is the main entry point for sending SMS in the application.
     *
     * @param  string  $templateKey  Template identifier (e.g., 'appointment-reminder-24h')
     * @param  string  $language  Language code ('pl', 'en')
     * @param  string  $recipient  Recipient phone number in international format (+48...)
     * @param  array  $data  Variables to render in template
     * @param  array  $metadata  Additional data for tracking (user_id, appointment_id, etc.)
     * @return \App\Models\SmsSend The SMS send record
     *
     * @throws \Exception If SMS is suppressed or template not found
     */
    public function sendFromTemplate(
        string $templateKey,
        string $language,
        string $recipient,
        array $data,
        array $metadata = []
    ): SmsSend {
        // Step 1: Check if SMS is enabled globally
        $smsSettings = $this->settings->group('sms');
        if (! ($smsSettings['enabled'] ?? true)) {
            Log::warning('SMS sending is disabled globally', [
                'recipient' => PrivacyHelper::maskPhone($recipient),
                'template' => $templateKey,
            ]);

            throw new \Exception('SMS sending is currently disabled in system settings.');
        }

        // Step 2: Check suppression list
        if (SmsSuppression::isSuppressed($recipient)) {
            Log::warning('SMS blocked by suppression list', [
                'recipient' => PrivacyHelper::maskPhone($recipient),
                'template' => $templateKey,
            ]);

            throw new \Exception("Phone number {$recipient} is suppressed and cannot receive SMS.");
        }

        // Step 3: Check SMS consent (GDPR compliance)
        // Determine if this is marketing SMS based on template key or metadata
        $isMarketingSms = $this->isMarketingTemplate($templateKey, $metadata);

        if (isset($metadata['user_id'])) {
            $user = \App\Models\User::find($metadata['user_id']);
            if ($user) {
                // Marketing SMS requires explicit marketing consent
                if ($isMarketingSms && ! $user->hasSmsMarketingConsent()) {
                    Log::warning('SMS marketing blocked: user has not given marketing consent', [
                        'recipient' => PrivacyHelper::maskPhone($recipient),
                        'user_id' => $metadata['user_id'],
                        'template' => $templateKey,
                    ]);

                    throw new \Exception('User has not given SMS marketing consent or has opted out.');
                }

                // Transactional SMS requires basic SMS consent
                if (! $isMarketingSms && ! $user->hasSmsConsent()) {
                    Log::warning('SMS blocked: user has not given consent or has opted out', [
                        'recipient' => PrivacyHelper::maskPhone($recipient),
                        'user_id' => $metadata['user_id'],
                        'template' => $templateKey,
                    ]);

                    throw new \Exception('User has not given SMS consent or has opted out.');
                }
            }
        }

        // Step 4: Check spending limits (daily and monthly)
        $this->checkSpendingLimits();

        // Step 5: Fetch template from database — tenant override if one exists, else the
        // global (NULL-organization) template. See SmsTemplate::resolveActive() docblock.
        $template = SmsTemplate::resolveActive($templateKey, $language);

        if (! $template) {
            throw new \Exception("SMS template '{$templateKey}' not found for language '{$language}'.");
        }

        // Step 6: Generate unique message key for idempotency
        $messageKey = $this->generateMessageKey($templateKey, $recipient, $metadata);

        // Step 7: Check for duplicate (idempotency check)
        $existingSend = SmsSend::where('message_key', $messageKey)->first();

        if ($existingSend) {
            Log::info('Duplicate SMS send detected, returning existing record', [
                'message_key' => $messageKey,
                'sms_send_id' => $existingSend->id,
            ]);

            return $existingSend;
        }

        // Step 8: Render template
        $messageBody = $this->renderTemplate($template, $data);

        // Step 9: Validate message length
        $lengthInfo = $this->gateway->calculateMessageLength($messageBody);
        if ($lengthInfo['length'] > $template->max_length) {
            Log::warning('SMS message exceeds max length', [
                'template' => $templateKey,
                'length' => $lengthInfo['length'],
                'max_length' => $template->max_length,
            ]);

            // Truncate message if too long
            $messageBody = mb_substr($messageBody, 0, $template->max_length);
            $lengthInfo = $this->gateway->calculateMessageLength($messageBody);
        }

        // Step 10: Create SmsSend record (status='pending')
        $smsSend = SmsSend::create([
            'template_key' => $templateKey,
            'language' => $language,
            'phone_to' => $recipient,
            'message_body' => $messageBody,
            'status' => 'pending',
            'metadata' => $metadata,
            'message_key' => $messageKey,
            'message_length' => $lengthInfo['length'],
            'message_parts' => $lengthInfo['parts'],
        ]);

        // Step 11: Try to send via SmsGateway
        try {
            $response = $this->gateway->send(
                $recipient,
                $messageBody,
                $metadata
            );

            // Success: mark as sent and store SMS ID
            $smsSend->update([
                'status' => 'sent',
                'sent_at' => now(),
                'sms_id' => $response['sms_id'],
            ]);

            // Create 'sent' event
            SmsEvent::create([
                'organization_id' => $smsSend->organization_id,
                'sms_send_id' => $smsSend->id,
                'event_type' => 'sent',
                'occurred_at' => now(),
                'event_data' => [
                    'sent_at' => now()->toISOString(),
                    'sms_id' => $response['sms_id'],
                    'gateway' => 'smsapi',
                ],
            ]);

            Log::info('SMS sent successfully', [
                'sms_send_id' => $smsSend->id,
                'recipient' => PrivacyHelper::maskPhone($recipient),
                'template' => $templateKey,
                'sms_id' => $response['sms_id'],
            ]);
        } catch (\Throwable $e) {
            // Failure: mark as failed
            $smsSend->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Create 'failed' event
            SmsEvent::create([
                'organization_id' => $smsSend->organization_id,
                'sms_send_id' => $smsSend->id,
                'event_type' => 'failed',
                'occurred_at' => now(),
                'event_data' => [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toISOString(),
                ],
            ]);

            Log::error('SMS sending failed', [
                'sms_send_id' => $smsSend->id,
                'recipient' => PrivacyHelper::maskPhone($recipient),
                'template' => $templateKey,
                'error' => $e->getMessage(),
            ]);

            // Re-throw exception for queue retry
            throw $e;
        }

        return $smsSend;
    }

    /**
     * Render SMS template via plain {{variable}} substitution (no Blade/PHP execution).
     *
     * @param  array  $data  Variables to render
     * @return string Rendered message body
     */
    public function renderTemplate(SmsTemplate $template, array $data): string
    {
        try {
            // Use SmsTemplate's render method — plain {{variable}} string substitution only,
            // never compiled/executed as Blade or PHP
            return $template->render($data);
        } catch (\Throwable $e) {
            Log::error('SMS template rendering failed', [
                'template_key' => $template->key,
                'language' => $template->language,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception("Failed to render SMS template: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate unique message key for idempotency.
     *
     * Format: md5("{template_key}:{recipient}:{metadata_json}")
     */
    private function generateMessageKey(string $templateKey, string $recipient, array $metadata): string
    {
        $metadataString = json_encode($metadata, JSON_THROW_ON_ERROR);

        return md5("{$templateKey}:{$recipient}:{$metadataString}");
    }

    /**
     * Send test SMS to verify configuration.
     *
     * @param  string  $recipient  Phone number to send test SMS
     * @param  string  $language  Language code ('pl', 'en')
     *
     * @throws \Exception
     */
    public function sendTestSms(string $recipient, string $language = 'pl'): SmsSend
    {
        $testMessage = $language === 'pl'
            ? 'To jest testowa wiadomość SMS z systemu Registro.'
            : 'This is a test SMS message from Registro system.';

        // Create SmsSend record directly (no template)
        $smsSend = SmsSend::create([
            'template_key' => 'test-message',
            'language' => $language,
            'phone_to' => $recipient,
            'message_body' => $testMessage,
            'status' => 'pending',
            'metadata' => ['type' => 'test'],
            'message_key' => md5('test:'.$recipient.':'.now()->timestamp),
        ]);

        try {
            $response = $this->gateway->send($recipient, $testMessage, ['test_mode' => false]);

            $smsSend->update([
                'status' => 'sent',
                'sent_at' => now(),
                'sms_id' => $response['sms_id'],
                'message_length' => $response['message_length'],
                'message_parts' => $response['message_parts'],
            ]);

            SmsEvent::create([
                'organization_id' => $smsSend->organization_id,
                'sms_send_id' => $smsSend->id,
                'event_type' => 'sent',
                'occurred_at' => now(),
                'event_data' => ['sms_id' => $response['sms_id']],
            ]);

            Log::info('Test SMS sent successfully', [
                'sms_send_id' => $smsSend->id,
                'recipient' => PrivacyHelper::maskPhone($recipient),
            ]);
        } catch (\Throwable $e) {
            $smsSend->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $smsSend;
    }

    /**
     * Check daily and monthly SMS spending limits.
     *
     * Throws exception if limits are exceeded.
     * Sends alert email when threshold is reached.
     *
     * @throws \Exception If spending limit is exceeded
     */
    private function checkSpendingLimits(): void
    {
        $smsSettings = $this->settings->group('sms');

        $dailyLimit = $smsSettings['daily_limit'] ?? config('services.sms.daily_limit', 500);
        $monthlyLimit = $smsSettings['monthly_limit'] ?? config('services.sms.monthly_limit', 10000);
        $alertThreshold = $smsSettings['alert_threshold'] ?? config('services.sms.alert_threshold', 80);
        $alertEmail = $smsSettings['alert_email'] ?? config('services.sms.alert_email');

        // Count SMS sent today
        $todayCount = SmsSend::whereDate('created_at', today())->count();

        // Count SMS sent this month
        $monthCount = SmsSend::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        // Check daily limit
        if ($todayCount >= $dailyLimit) {
            Log::error('Daily SMS limit exceeded', [
                'today_count' => $todayCount,
                'daily_limit' => $dailyLimit,
            ]);

            throw new \Exception("Daily SMS limit of {$dailyLimit} messages exceeded. Sent: {$todayCount}.");
        }

        // Check monthly limit
        if ($monthCount >= $monthlyLimit) {
            Log::error('Monthly SMS limit exceeded', [
                'month_count' => $monthCount,
                'monthly_limit' => $monthlyLimit,
            ]);

            throw new \Exception("Monthly SMS limit of {$monthlyLimit} messages exceeded. Sent: {$monthCount}.");
        }

        // Check if we should send alert (e.g., 80% threshold)
        $dailyThreshold = ($dailyLimit * $alertThreshold) / 100;
        $monthlyThreshold = ($monthlyLimit * $alertThreshold) / 100;

        if ($todayCount >= $dailyThreshold && ! cache()->has('sms_daily_alert_sent_'.today()->toDateString())) {
            $this->sendSpendingAlert('daily', $todayCount, $dailyLimit, $alertEmail);
            cache()->put('sms_daily_alert_sent_'.today()->toDateString(), true, now()->endOfDay());
        }

        if ($monthCount >= $monthlyThreshold && ! cache()->has('sms_monthly_alert_sent_'.now()->format('Y-m'))) {
            $this->sendSpendingAlert('monthly', $monthCount, $monthlyLimit, $alertEmail);
            cache()->put('sms_monthly_alert_sent_'.now()->format('Y-m'), true, now()->endOfMonth());
        }
    }

    /**
     * Send spending alert email to admin.
     *
     * @param  string  $period  'daily' or 'monthly'
     * @param  int  $currentCount  Current SMS count
     * @param  int  $limit  SMS limit
     * @param  string|null  $email  Alert email address
     */
    private function sendSpendingAlert(string $period, int $currentCount, int $limit, ?string $email): void
    {
        if (empty($email)) {
            Log::warning('SMS spending alert email not configured');

            return;
        }

        $percentage = (int) round(($currentCount / $limit) * 100);

        Log::warning("SMS {$period} spending threshold reached", [
            'current_count' => $currentCount,
            'limit' => $limit,
            'percentage' => $percentage,
            'alert_email' => $email,
        ]);

        try {
            Mail::to($email)->queue(new SmsSpendingAlertMail($period, $currentCount, $limit, $percentage));

            Log::info("SMS spending alert email sent to {$email}", [
                'period' => $period,
                'percentage' => $percentage,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send SMS spending alert email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a template is for marketing purposes.
     *
     * Marketing templates require explicit marketing consent (GDPR).
     * Transactional templates (reminders, confirmations) only need basic SMS consent.
     *
     * @param  string  $templateKey  Template identifier
     * @param  array  $metadata  Additional metadata (may contain 'is_marketing' flag)
     * @return bool True if marketing template, false if transactional
     */
    private function isMarketingTemplate(string $templateKey, array $metadata): bool
    {
        // Allow explicit override via metadata
        if (isset($metadata['is_marketing'])) {
            return (bool) $metadata['is_marketing'];
        }

        // Marketing template keys (require explicit consent)
        $marketingTemplates = [
            'promotion-',
            'marketing-',
            'offer-',
            'discount-',
            'newsletter-',
            'campaign-',
        ];

        foreach ($marketingTemplates as $prefix) {
            if (str_starts_with($templateKey, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
