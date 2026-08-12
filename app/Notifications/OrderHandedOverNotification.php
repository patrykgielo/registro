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
 * Order Handed Over Notification
 *
 * Sent to the customer when an admin marks the equipment as handed over
 * (confirmed -> in_progress, "Wydano klientowi" action). Doubles as the
 * customer's own record of what they received, for use if the handover is
 * later disputed.
 *
 * Queue: emails | Unique per order for 5 minutes.
 */
class OrderHandedOverNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Order $order
    ) {
        $this->onQueue('emails');
    }

    /**
     * Get the unique ID for the notification.
     */
    public function uniqueId(): string
    {
        return 'order-handed-over:'.$this->order->id;
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

        $order->loadMissing('items');

        try {
            $emailService->sendFromTemplate(
                TemplateKey::ORDER_HANDED_OVER->value,
                $language,
                $notifiable->email,
                array_merge(
                    [
                        'customer_name' => $customerName,
                        'order_number' => $order->order_number,
                        'orders_url' => route('orders.index'),
                        'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
                    ],
                    $this->buildItemsListVariables($order)
                ),
                [
                    'order_id' => $order->id,
                    'notification' => 'OrderHandedOverNotification',
                ]
            );
        } catch (\Exception $e) {
            Log::error('OrderHandedOverNotification failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build the rental item list for this order. Mirrors
     * OrderPaidNotification::buildRentalVariables() minus the deposit/pickup
     * fields, which do not apply once the equipment is already with the
     * customer. Queue-safe: reads $order->items directly, no SettingsManager.
     *
     * @return array<string, string>
     */
    private function buildItemsListVariables(Order $order): array
    {
        $itemsHtml = '';
        $itemsText = '';

        foreach ($order->items as $item) {
            $dates = ($item->start_date && $item->end_date)
                ? $item->start_date->format('d.m.Y').' – '.$item->end_date->format('d.m.Y')
                : '';
            $qty = $item->quantity > 1 ? ' × '.$item->quantity : '';

            $itemsHtml .= '<tr>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">'
                .htmlspecialchars($item->service_name.$qty)
                .'<br><small style="color:#6b7280;">'.$dates.'</small>'
                .'</td>'
                .'</tr>';

            $itemsText .= '- '.$item->service_name.$qty.($dates ? ' ('.$dates.')' : '')."\n";
        }

        $itemsListHtml = $itemsHtml
            ? '<table style="width:100%;border-collapse:collapse;font-size:14px;">'.$itemsHtml.'</table>'
            : '';

        return [
            'items_list_html' => $itemsListHtml,
            'items_list_text' => rtrim($itemsText),
        ];
    }
}
