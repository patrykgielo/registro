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
 * Order Accepted Offline Notification
 *
 * Sent immediately after checkout when the customer chose "pay at pickup"
 * (settlement_method = 'offline').
 * - 'customer' recipient → ORDER_ACCEPTED_OFFLINE template. Deliberately its
 *   OWN template, NOT ORDER_PAID — "zostało opłacone" would be false at this
 *   point; nothing has been paid yet, only reserved. ORDER_PAID is sent
 *   later, once staff records the actual cash/transfer payment
 *   (OrderService::recordOfflinePayment()).
 * - 'admin' recipient → ADMIN_NEW_ORDER template (same key OrderPaidNotification
 *   uses for its own admin branch — "a new order needs your attention" holds
 *   regardless of settlement method), with `payment_note` distinguishing
 *   "paid online" from "pay at pickup" (ClickUp 123k99cvc55: before this, an
 *   offline-accepted order never reached the owner at all).
 *
 * Queue: emails.
 */
class OrderAcceptedOfflineNotification extends Notification implements ShouldQueue
{
    use BuildsOrderRentalEmailVariables;
    use Queueable;

    /**
     * @param  string  $recipientType  'customer' or 'admin'
     */
    public function __construct(
        public Order $order,
        public string $recipientType = 'customer'
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
            if ($this->recipientType === 'admin') {
                $emailService->sendFromTemplate(
                    TemplateKey::ADMIN_NEW_ORDER->value,
                    $language,
                    $notifiable->email,
                    array_merge(
                        [
                            'customer_name' => $customerName,
                            'order_number' => $order->order_number,
                            'total_amount' => number_format((float) $order->total_amount, 2, ',', ' '),
                            'payment_note' => $language === 'en'
                                ? 'Pay at pickup (cash or bank transfer)'
                                : 'Płatność przy odbiorze (gotówka lub przelew)',
                            'admin_url' => url('/admin/orders'),
                            'app_name' => $settings->appName(),
                        ],
                        $this->buildRentalVariables($order)
                    ),
                    [
                        'order_id' => $order->id,
                        'recipient_type' => 'admin',
                        'notification' => 'OrderAcceptedOfflineNotification',
                    ],
                    organization: $order->organization
                );

                return;
            }

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
                ],
                organization: $order->organization
            );
        } catch (\Exception $e) {
            Log::error('OrderAcceptedOfflineNotification failed', [
                'order_id' => $order->id,
                'recipient_type' => $this->recipientType,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
