<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Service;
use App\Notifications\InquiryNotification;
use App\Support\Settings\SettingsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ServiceInquiryController extends Controller
{
    public function store(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->price_on_request, 422, 'This service does not accept price inquiries.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $settings = app(SettingsManager::class);
        $recipient = $settings->get('checkout.inquiry_email')
            ?: $settings->get('email.from_address');

        if (empty($recipient)) {
            Log::warning('ServiceInquiry: no recipient configured', [
                'service_id' => $service->id,
                'sender_email' => $data['email'],
            ]);

            return response()->json(['success' => false, 'message' => 'Nie można wysłać zapytania. Skontaktuj się bezpośrednio.'], 503);
        }

        Notification::route('mail', $recipient)
            ->notify(new InquiryNotification($service, $data));

        return response()->json(['success' => true]);
    }
}
