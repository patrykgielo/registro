<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Analytics\AnalyticsEventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordAnalyticsOnOrderPaid implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'analytics';

    public function __construct(private readonly AnalyticsEventDispatcher $dispatcher) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        $this->dispatcher->trackForOrder($order, 'order.completed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => $order->total_amount,
            // Sum of units, not row count — see CheckoutController::show()'s 'checkout.started'
            // for why: since Faza 3 krok 2, a single cart quantity expands into that many
            // OrderItem rows of quantity 1 each, so ->count() here would no longer mean the
            // same thing as 'checkout.started''s item_count for the same cart/order.
            // (int) cast: Builder::sum() returns the raw PDO value uncast — MySQL
            // returns SUM() over an integer column as a numeric STRING (DECIMAL
            // result type), unlike SQLite. See MarkCartsAbandonedJob's matching cast.
            'item_count' => (int) $order->items()->sum('quantity'),
            'is_b2b' => $order->customer_type === 'business',
        ]);
    }
}
