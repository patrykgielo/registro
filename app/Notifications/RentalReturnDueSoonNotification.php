<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Rental Return Due Soon Notification
 *
 * Sent to the customer the day before a single order item's rental period
 * ends (`order_items.end_date` is tomorrow), while the order is still
 * `in_progress`. One notification per order item, not per order — see
 * app/docs/features/rental-return-reminders.md for why items, not orders,
 * are the reminder unit.
 *
 * No `ShouldBeUnique` — deliberately, per notifications.md: it has no effect
 * on Notification subclasses in this Laravel version. Deduplication is
 * EmailService's `message_key` (template + recipient + metadata), keyed here
 * on `order_item_id` + the item's own `end_date`, so a later extension that
 * pushes the date out earns its own fresh reminder instead of silently
 * reusing a stale one.
 *
 * Queue: emails.
 */
class RentalReturnDueSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order,
        public OrderItem $item
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
        $item = $this->item;
        $customerName = trim($order->customer_first_name.' '.$order->customer_last_name);

        try {
            $emailService->sendFromTemplate(
                TemplateKey::RENTAL_RETURN_DUE_SOON->value,
                $language,
                $notifiable->email,
                [
                    'customer_name' => $customerName,
                    'order_number' => $order->order_number,
                    'service_name' => $item->service_name,
                    'return_date' => $item->end_date?->format('d.m.Y') ?? '',
                    'orders_url' => route('orders.index'),
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'end_date' => $item->end_date?->toDateString(),
                    'reminder_type' => 'due_soon',
                    'notification' => 'RentalReturnDueSoonNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('RentalReturnDueSoonNotification failed', [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
