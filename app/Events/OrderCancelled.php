<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Cancelled Event
 *
 * Dispatched when an order is cancelled (by admin or TTL expiry).
 * Triggers a cancellation email to the customer.
 */
class OrderCancelled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Order  $order  The cancelled order
     * @param  string  $reason  Optional cancellation reason
     * @param  bool  $notify  Whether the customer-facing cancellation email should be
     *                        sent. False for internal-compensation scenarios (e.g. P24
     *                        registration failure) where the customer never actually
     *                        saw a completed order — see OrderService::cancel().
     */
    public function __construct(
        public Order $order,
        public string $reason = '',
        public bool $notify = true,
    ) {}
}
