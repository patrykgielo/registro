<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Returned Event
 *
 * Dispatched when an admin marks equipment as returned by the customer
 * (in_progress -> completed, "Sprzęt zwrócony" action).
 * Triggers a return confirmation email to the customer.
 */
class OrderReturned
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Order  $order  The completed order
     */
    public function __construct(
        public Order $order
    ) {}
}
