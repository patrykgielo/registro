<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Jobs\IngestAnalyticsEventsJob;
use App\Models\Cart;
use App\Models\Order;

class AnalyticsEventDispatcher
{
    public function trackForCart(Cart $cart, string $event, array $properties = []): void
    {
        IngestAnalyticsEventsJob::dispatch(
            [['event' => $event, 'url' => '', 'properties' => $properties, 'timestamp' => now()->toISOString()]],
            [
                'organization_id' => $cart->organization_id,
                'user_id' => $cart->user_id,
                'session_id' => 'server-cart-'.$cart->id,
                'received_at' => now()->format('Y-m-d H:i:s'),
            ]
        )->onQueue('analytics');
    }

    public function trackForOrder(Order $order, string $event, array $properties = []): void
    {
        IngestAnalyticsEventsJob::dispatch(
            [['event' => $event, 'url' => '', 'properties' => $properties, 'timestamp' => now()->toISOString()]],
            [
                'organization_id' => $order->organization_id,
                'user_id' => $order->user_id,
                'session_id' => 'server-order-'.$order->id,
                'received_at' => now()->format('Y-m-d H:i:s'),
            ]
        )->onQueue('analytics');
    }
}
