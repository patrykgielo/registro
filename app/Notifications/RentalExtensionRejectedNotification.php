<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\OrderItemExtensionRequest;
use App\Services\Email\EmailService;
use App\Support\Settings\SettingsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class RentalExtensionRejectedNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public OrderItemExtensionRequest $extensionRequest)
    {
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return 'rental-extension-rejected:'.$this->extensionRequest->id;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        $req = $this->extensionRequest;
        $appName = app(SettingsManager::class)->appName();

        try {
            $emailService->sendFromTemplate(
                TemplateKey::RENTAL_EXTENSION_REJECTED->value,
                $notifiable->preferred_language ?? 'pl',
                $notifiable->email,
                [
                    'customer_name' => trim($notifiable->first_name.' '.$notifiable->last_name),
                    'order_number' => $req->order->order_number,
                    'service_name' => $req->orderItem->service_name,
                    'requested_end_date' => $req->requested_end_date->format('d.m.Y'),
                    'rejection_reason' => $req->rejection_reason ?? '',
                    'orders_url' => route('orders.show', $req->order_id),
                    'app_name' => $appName,
                ],
                [
                    'extension_request_id' => $req->id,
                    'notification' => 'RentalExtensionRejectedNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('RentalExtensionRejectedNotification failed', [
                'extension_request_id' => $req->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
