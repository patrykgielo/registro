<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Service;
use App\Notifications\InquiryNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ServiceInquiryController extends Controller
{
    public function store(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $recipient = settings('checkout.inquiry_email')
            ?: settings('email.from_address');

        if (! empty($recipient)) {
            Notification::route('mail', $recipient)
                ->notify(new InquiryNotification($service, $data));
        }

        return response()->json(['success' => true]);
    }
}
