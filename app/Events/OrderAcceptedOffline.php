<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Accepted Offline Event
 *
 * Dispatched right after checkout when the customer chose "pay at pickup"
 * (settlement_method = 'offline'). The order is NOT paid yet — it's merely
 * reserved (pending_payment, blocking inventory) — so this is deliberately a
 * different event from OrderPaid, which fires later once staff actually
 * records the cash/transfer payment (OrderService::recordOfflinePayment()).
 */
class OrderAcceptedOffline
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Order $order
    ) {}
}
