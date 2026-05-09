<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Confirmed Event
 *
 * Dispatched when an admin transitions an order to the 'confirmed' state.
 * Triggers a confirmation email to the customer.
 */
class OrderConfirmed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Order  $order  The confirmed order
     */
    public function __construct(
        public Order $order
    ) {}
}
