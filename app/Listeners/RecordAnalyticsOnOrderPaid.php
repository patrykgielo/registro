<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Analytics\AnalyticsEventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordAnalyticsOnOrderPaid implements ShouldQueue
{
    public string $queue = 'analytics';

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        (new AnalyticsEventDispatcher)->trackForOrder($order, 'order.completed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => $order->total_amount,
            'item_count' => $order->items()->count(),
            'is_b2b' => $order->customer_type === 'business',
        ]);
    }
}
