<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Order;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Order Paid Notification
 *
 * Sent after successful Przelewy24 payment.
 * - 'customer' recipient → ORDER_PAID template with orders_url
 * - 'admin'    recipient → ADMIN_NEW_ORDER template with admin_url
 *
 * Queue: emails | Unique per order+recipientType for 5 minutes.
 */
class OrderPaidNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $recipientType  'customer' or 'admin'
     */
    public function __construct(
        public Order $order,
        public string $recipientType = 'customer'
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'order-paid:'.$this->order->id.':'.$this->recipientType;
    }

    /**
     * Get the number of seconds the unique lock should be maintained.
     */
    public function uniqueFor(): int
    {
        return 300; // 5 minutes
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    /**
     * Send via EmailService channel.
     */
    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        $language = $notifiable->preferred_language ?? 'pl';
        $order = $this->order;
        $appName = app(\App\Support\Settings\SettingsManager::class)->appName();

        $customerName = trim($order->customer_first_name.' '.$order->customer_last_name);

        try {
            if ($this->recipientType === 'admin') {
                $emailService->sendFromTemplate(
                    TemplateKey::ADMIN_NEW_ORDER->value,
                    $language,
                    $notifiable->email,
                    [
                        'customer_name' => $customerName,
                        'order_number' => $order->order_number,
                        'total_amount' => number_format((float) $order->total_amount, 2, ',', ' '),
                        'admin_url' => url('/admin/orders'),
                        'app_name' => $appName,
                    ],
                    [
                        'order_id' => $order->id,
                        'recipient_type' => 'admin',
                        'notification' => 'OrderPaidNotification',
                    ]
                );
            } else {
                $emailService->sendFromTemplate(
                    TemplateKey::ORDER_PAID->value,
                    $language,
                    $notifiable->email,
                    [
                        'customer_name' => $customerName,
                        'order_number' => $order->order_number,
                        'total_amount' => number_format((float) $order->total_amount, 2, ',', ' '),
                        'orders_url' => route('orders.index'),
                        'app_name' => $appName,
                    ],
                    [
                        'order_id' => $order->id,
                        'recipient_type' => 'customer',
                        'notification' => 'OrderPaidNotification',
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error('OrderPaidNotification failed', [
                'order_id' => $order->id,
                'recipient_type' => $this->recipientType,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
