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
 * Order Cancelled Notification
 *
 * Sent to the customer when an order is cancelled (by admin or expiry).
 * Queue: emails | Unique per order for 5 minutes.
 */
class OrderCancelledNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string  $reason  Optional cancellation reason
     */
    public function __construct(
        public Order $order,
        public string $reason = ''
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'order-cancelled:'.$this->order->id;
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
        $customerName = trim($order->customer_first_name.' '.$order->customer_last_name);

        try {
            $emailService->sendFromTemplate(
                TemplateKey::ORDER_CANCELLED->value,
                $language,
                $notifiable->email,
                [
                    'customer_name' => $customerName,
                    'order_number' => $order->order_number,
                    'reason' => $this->reason ?: 'Nie podano powodu',
                    'orders_url' => route('orders.index'),
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'order_id' => $order->id,
                    'notification' => 'OrderCancelledNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('OrderCancelledNotification failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
