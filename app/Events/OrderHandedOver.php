<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Handed Over Event
 *
 * Dispatched when an admin marks equipment as handed over to the customer
 * (confirmed -> in_progress, "Wydano klientowi" action).
 * Triggers a handover confirmation email to the customer.
 */
class OrderHandedOver
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Order  $order  The handed-over order
     */
    public function __construct(
        public Order $order
    ) {}
}
