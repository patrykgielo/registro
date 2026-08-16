<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Order;
use App\Notifications\Concerns\BuildsOrderRentalEmailVariables;
use App\Services\Email\EmailService;
use App\Support\Settings\SettingsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Order Accepted Offline Notification (customer only)
 *
 * Sent immediately after checkout when the customer chose "pay at pickup"
 * (settlement_method = 'offline'). Deliberately its OWN template, NOT
 * ORDER_PAID — "zostało opłacone" would be false at this point; nothing has
 * been paid yet, only reserved. ORDER_PAID is sent later, once staff records
 * the actual cash/transfer payment (OrderService::recordOfflinePayment()).
 *
 * Queue: emails.
 */
class OrderAcceptedOfflineNotification extends Notification implements ShouldQueue
{
    use BuildsOrderRentalEmailVariables;
    use Queueable;

    public function __construct(
        public Order $order
    ) {
        $this->onQueue('emails');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        $language = $notifiable->preferred_language ?? 'pl';
        $order = $this->order;
        $settings = app(SettingsManager::class);

        $customerName = trim($order->customer_first_name.' '.$order->customer_last_name);

        $order->loadMissing(['items', 'organization']);

        try {
            $emailService->sendFromTemplate(
                TemplateKey::ORDER_ACCEPTED_OFFLINE->value,
                $language,
                $notifiable->email,
                array_merge(
                    [
                        'customer_name' => $customerName,
                        'order_number' => $order->order_number,
                        'total_amount' => number_format((float) $order->total_amount, 2, ',', ' '),
                        'hold_until' => $order->expires_at?->format('d.m.Y H:i') ?? '',
                        'orders_url' => route('orders.index'),
                        'app_name' => $settings->appName(),
                    ],
                    $this->buildRentalVariables($order)
                ),
                [
                    'order_id' => $order->id,
                    'recipient_type' => 'customer',
                    'notification' => 'OrderAcceptedOfflineNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('OrderAcceptedOfflineNotification failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
