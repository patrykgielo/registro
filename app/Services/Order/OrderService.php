<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;

class OrderService
{
    /**
     * Cancels an order by transitioning its status via the state machine.
     *
     * @throws \LogicException when order status does not allow cancellation
     */
    public function cancel(Order $order, string $reason): Order
    {
        if (! in_array($order->status, ['pending_payment', 'paid'], strict: true)) {
            throw new \LogicException("Zamówienie o statusie '{$order->status}' nie może zostać anulowane");
        }

        $order->status()->transitionTo('cancelled', ['reason' => $reason]);

        $order->update(['cancelled_at' => now()]);

        return $order;
    }

    /**
     * Cancels all expired pending_payment orders and returns the count.
     */
    public function cleanupExpired(): int
    {
        $cancelled = 0;

        Order::expired()->get()->each(function (Order $order) use (&$cancelled): void {
            $this->cancel($order, 'TTL expired');
            $cancelled++;
        });

        return $cancelled;
    }
}
