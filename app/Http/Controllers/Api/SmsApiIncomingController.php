<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\PrivacyHelper;
use App\Http\Controllers\Controller;
use App\Models\SmsSuppression;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SMSAPI Incoming Messages Controller
 *
 * Handles incoming SMS messages (replies) from SMSAPI.pl.
 * Primary use: Processing "STOP" opt-out requests (GDPR compliance).
 */
class SmsApiIncomingController extends Controller
{
    /**
     * Handle incoming SMS message from SMSAPI.
     *
     * Expected payload:
     * {
     *   "from": "+48501234567",
     *   "to": "your_sms_number",
     *   "message": "STOP",
     *   "date_received": "2025-11-13 14:30:00",
     *   "id": "incoming_sms_id"
     * }
     */
    public function handleIncoming(Request $request): JsonResponse
    {
        try {
            Log::info('SMSAPI incoming message received', [
                'payload' => $request->all(),
            ]);

            // Validate required fields
            $validated = $request->validate([
                'from' => 'required|string',
                'message' => 'required|string',
                'id' => 'nullable|string',
            ]);

            $phoneNumber = $validated['from'];
            $message = trim(strtoupper($validated['message']));

            // Check if this is an opt-out request
            if ($this->isOptOutMessage($message)) {
                $this->processOptOut($phoneNumber);

                Log::info('SMS opt-out processed successfully', [
                    'phone' => PrivacyHelper::maskPhone($phoneNumber),
                    'message' => $message,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Opt-out request processed',
                ], 200);
            }

            // Not an opt-out message - log and ignore
            Log::info('SMS incoming message (not opt-out)', [
                'phone' => PrivacyHelper::maskPhone($phoneNumber),
                'message_preview' => mb_substr($message, 0, 20),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message received',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('SMSAPI incoming message validation failed', [
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('SMSAPI incoming message processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Processing failed',
            ], 500);
        }
    }

    /**
     * Check if message is an opt-out request.
     *
     * Recognizes various opt-out keywords in multiple languages.
     *
     * @param  string  $message  Uppercase message content
     * @return bool True if opt-out message
     */
    private function isOptOutMessage(string $message): bool
    {
        $optOutKeywords = [
            'STOP',
            'UNSUB',
            'UNSUBSCRIBE',
            'CANCEL',
            'END',
            'QUIT',
            'REZYGNACJA',  // Polish
            'KONIEC',      // Polish
            'ANULUJ',      // Polish
        ];

        foreach ($optOutKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process opt-out request for given phone number.
     *
     * 1. Add to suppression list
     * 2. Find associated user and revoke SMS consent
     *
     * @param  string  $phoneNumber  Phone number to opt out
     */
    private function processOptOut(string $phoneNumber): void
    {
        // 1. Add to suppression list
        SmsSuppression::suppress($phoneNumber, 'STOP_reply');

        // 2. Find user by phone and revoke consent
        $user = User::where('phone_e164', $phoneNumber)->first();

        if ($user) {
            $user->revokeSmsConsent('STOP_reply');

            Log::info('User SMS consent revoked via STOP reply', [
                'user_id' => $user->id,
                'phone' => PrivacyHelper::maskPhone($phoneNumber),
            ]);
        } else {
            Log::info('Phone number suppressed (no associated user found)', [
                'phone' => PrivacyHelper::maskPhone($phoneNumber),
            ]);
        }
    }
}
