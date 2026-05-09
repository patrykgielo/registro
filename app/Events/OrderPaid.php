<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Paid Event
 *
 * Dispatched when a Przelewy24 webhook confirms successful payment.
 * Triggers confirmation emails to customer and admin/org owner.
 */
class OrderPaid
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Order  $order  The paid order
     */
    public function __construct(
        public Order $order
    ) {}
}
