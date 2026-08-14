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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Rental Return Overdue Notification
 *
 * Sent once per order item when its rental period has ended
 * (`order_items.end_date` is in the past) and the order is still
 * `in_progress` — i.e. the equipment has not been marked returned. Fires
 * exactly once per item, ever, not daily — see
 * app/docs/features/rental-return-reminders.md's "one overdue notice, not a
 * repeating one" decision.
 *
 * No `ShouldBeUnique` — see RentalReturnDueSoonNotification's docblock for
 * why. Deduplication is EmailService's `message_key`, keyed on
 * `order_item_id` alone (no `end_date`, unlike the due-soon reminder): once
 * an item has been flagged overdue, it stays flagged for that item
 * regardless of how many times the job re-finds it while still
 * `in_progress`.
 *
 * Queue: emails.
 */
class RentalReturnOverdueNotification extends Notification implements ShouldQueue
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
        $daysOverdue = $item->end_date !== null
            ? (int) $item->end_date->diffInDays(Carbon::today())
            : 0;

        try {
            $emailService->sendFromTemplate(
                TemplateKey::RENTAL_RETURN_OVERDUE->value,
                $language,
                $notifiable->email,
                [
                    'customer_name' => $customerName,
                    'order_number' => $order->order_number,
                    'service_name' => $item->service_name,
                    'return_date' => $item->end_date?->format('d.m.Y') ?? '',
                    'days_overdue' => (string) $daysOverdue,
                    'orders_url' => route('orders.index'),
                    'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                ],
                [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'reminder_type' => 'overdue',
                    'notification' => 'RentalReturnOverdueNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('RentalReturnOverdueNotification failed', [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
