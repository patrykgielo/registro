<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\PrivacyHelper;
use App\Http\Controllers\Controller;
use App\Models\SmsEvent;
use App\Models\SmsSend;
use App\Models\SmsSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SMSAPI Webhook Controller
 *
 * Handles delivery status webhooks from SMSAPI.pl
 * Updates sms_sends status and creates sms_events for tracking.
 */
class SmsApiWebhookController extends Controller
{
    /**
     * Handle SMSAPI delivery status webhook.
     *
     * Expected payload format:
     * {
     *   "id": "smsapi_message_id",
     *   "status": "DELIVERED|SENT|FAILED|INVALID|EXPIRED",
     *   "to": "+48501234567",
     *   "date_sent": "2025-11-12 10:30:00",
     *   "error_code": "optional_error_code"
     * }
     */
    public function handleDeliveryStatus(Request $request): JsonResponse
    {
        try {
            // Verify webhook signature for security
            if (! $this->verifyWebhookSignature($request)) {
                Log::warning('SMSAPI webhook: Invalid signature', [
                    'ip' => $request->ip(),
                    'payload' => $request->all(),
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                ], 401);
            }

            // Log incoming webhook for debugging
            Log::info('SMSAPI webhook received', [
                'payload' => $request->all(),
                'headers' => $request->headers->all(),
            ]);

            // Validate required fields
            $validated = $request->validate([
                'id' => 'required|string',
                'status' => 'required|string',
                'to' => 'nullable|string',
                'date_sent' => 'nullable|string',
                'error_code' => 'nullable|string',
            ]);

            $smsId = $validated['id'];
            $status = $validated['status'];
            $phone = $validated['to'] ?? null;
            $errorCode = $validated['error_code'] ?? null;

            // Find the SMS send record
            $smsSend = SmsSend::where('sms_id', $smsId)->first();

            if (! $smsSend) {
                Log::warning('SMSAPI webhook: SMS send not found', [
                    'sms_id' => $smsId,
                    'status' => $status,
                ]);

                // Return 200 to prevent retries for unknown SMS IDs
                return response()->json([
                    'success' => true,
                    'message' => 'SMS send not found, webhook accepted',
                ], 200);
            }

            // Map SMSAPI status to our event types
            $eventType = $this->mapStatusToEventType($status);

            // Create event record
            SmsEvent::create([
                'organization_id' => $smsSend->organization_id,
                'sms_send_id' => $smsSend->id,
                'event_type' => $eventType,
                'occurred_at' => now(),
                'event_data' => [
                    'smsapi_status' => $status,
                    'error_code' => $errorCode,
                    'phone' => $phone,
                    'webhook_data' => $validated,
                ],
            ]);

            // Update SMS send status
            $this->updateSmsSendStatus($smsSend, $eventType);

            // Handle suppression list updates
            $this->handleSuppressionList($smsSend, $eventType, $phone);

            Log::info('SMSAPI webhook processed successfully', [
                'sms_id' => $smsId,
                'event_type' => $eventType,
                'sms_send_id' => $smsSend->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('SMSAPI webhook validation failed', [
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('SMSAPI webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Map SMSAPI status to our event type.
     *
     * @param  string  $smsapiStatus  SMSAPI status string
     * @return string Event type (sent|delivered|failed|invalid_number|expired)
     */
    private function mapStatusToEventType(string $smsapiStatus): string
    {
        return match (strtoupper($smsapiStatus)) {
            'SENT', 'QUEUE' => 'sent',
            'DELIVERED', 'ACCEPTED' => 'delivered',
            'FAILED', 'REJECTED', 'ERROR' => 'failed',
            'INVALID', 'INVALID_NUMBER', 'INVALID_SENDER' => 'invalid_number',
            'EXPIRED', 'NOT_DELIVERED' => 'expired',
            default => 'failed', // Default to failed for unknown statuses
        };
    }

    /**
     * Update SMS send status based on event type.
     */
    private function updateSmsSendStatus(SmsSend $smsSend, string $eventType): void
    {
        // Only update if status is more "final" than current
        $statusPriority = [
            'pending' => 0,
            'sent' => 1,
            'delivered' => 2, // Final success
            'failed' => 2,    // Final failure
            'invalid_number' => 2, // Final failure
            'expired' => 2,   // Final failure
        ];

        $currentPriority = $statusPriority[$smsSend->status] ?? 0;
        $newPriority = $statusPriority[$eventType] ?? 0;

        if ($newPriority > $currentPriority) {
            $smsSend->update(['status' => $eventType]);

            Log::info('SMS send status updated', [
                'sms_send_id' => $smsSend->id,
                'old_status' => $smsSend->status,
                'new_status' => $eventType,
            ]);
        }
    }

    /**
     * Handle suppression list updates based on event type.
     */
    private function handleSuppressionList(SmsSend $smsSend, string $eventType, ?string $phone): void
    {
        $phoneToSuppress = $phone ?? $smsSend->phone_to;

        if (! $phoneToSuppress) {
            return;
        }

        // Add to suppression list for invalid numbers
        if ($eventType === 'invalid_number') {
            SmsSuppression::suppress($phoneToSuppress, 'invalid_number');

            Log::info('Phone number added to suppression list', [
                'phone' => PrivacyHelper::maskPhone($phoneToSuppress),
                'reason' => 'invalid_number',
            ]);

            return;
        }

        // Check for repeated failures and suppress
        if ($eventType === 'failed') {
            $failureCount = SmsSend::where('phone_to', $phoneToSuppress)
                ->where('status', 'failed')
                ->count();

            // Suppress after 3 consecutive failures
            if ($failureCount >= 3) {
                SmsSuppression::suppress($phoneToSuppress, 'failed_repeatedly');

                Log::warning('Phone number suppressed due to repeated failures', [
                    'phone' => PrivacyHelper::maskPhone($phoneToSuppress),
                    'failure_count' => $failureCount,
                ]);
            }
        }
    }

    /**
     * Known SMSAPI.pl IP ranges for webhook requests.
     * Updated: 2026-01. Verify at https://www.smsapi.pl/ if webhooks fail.
     */
    private const SMSAPI_IP_RANGES = [
        '195.201.229.',  // SMSAPI EU servers
        '195.201.230.',
        '195.201.231.',
        '78.133.254.',   // SMSAPI PL servers
        '78.133.255.',
    ];

    /**
     * Verify SMSAPI webhook signature or IP whitelist.
     *
     * Security layers (in order):
     * 1. HMAC signature (if SMSAPI_WEBHOOK_SECRET is configured)
     * 2. IP whitelist fallback (if no signature but IP is from SMSAPI)
     * 3. In production without any security: REJECT
     * 4. In development without security: ALLOW with warning
     *
     * @return bool True if request is verified as legitimate
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        $webhookSecret = config('services.smsapi.webhook_secret');
        $clientIp = $request->ip();

        // Layer 1: If webhook secret is configured, verify signature
        if (! empty($webhookSecret)) {
            $providedSignature = $request->header('X-SMSAPI-Signature');

            if (empty($providedSignature)) {
                Log::warning('SMSAPI webhook: Missing X-SMSAPI-Signature header', [
                    'ip' => $clientIp,
                ]);

                return false;
            }

            $payload = $request->getContent();
            $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

            if (hash_equals($expectedSignature, $providedSignature)) {
                return true;
            }

            Log::warning('SMSAPI webhook: Invalid signature', [
                'ip' => $clientIp,
            ]);

            return false;
        }

        // Layer 2: No secret configured - check IP whitelist
        $isFromSmsapi = $this->isIpFromSmsapi($clientIp);

        if ($isFromSmsapi) {
            Log::info('SMSAPI webhook: Verified by IP whitelist (no secret configured)', [
                'ip' => $clientIp,
            ]);

            return true;
        }

        // Layer 3: Production without security - REJECT
        if (app()->environment('production')) {
            Log::error('SMSAPI webhook REJECTED in production: No webhook secret configured and IP not whitelisted', [
                'ip' => $clientIp,
                'recommendation' => 'Set SMSAPI_WEBHOOK_SECRET in .env for production security',
            ]);

            return false;
        }

        // Layer 4: Development without security - ALLOW with warning
        Log::warning('SMSAPI webhook: Verification skipped in development (no secret, IP not whitelisted)', [
            'ip' => $clientIp,
            'environment' => app()->environment(),
        ]);

        return true;
    }

    /**
     * Check if IP address belongs to SMSAPI.pl servers.
     */
    private function isIpFromSmsapi(string $ip): bool
    {
        foreach (self::SMSAPI_IP_RANGES as $range) {
            if (str_starts_with($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
