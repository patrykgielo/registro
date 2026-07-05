<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class OrderService
{
    /**
     * Cancels an order by transitioning its status via the state machine.
     *
     * Supports: pending_payment, paid, confirmed, in_progress.
     * in_progress cancellation is exceptional (e.g. tenant offboarding) and logged.
     *
     * @param  bool  $notify  Whether to send the customer-facing cancellation email.
     *                        Set to false for internal-compensation scenarios (e.g. P24
     *                        registration failure right after checkout) where the
     *                        customer never actually saw a completed order and a
     *                        "your order was cancelled" email would just be confusing
     *                        noise ahead of an immediate, successful retry.
     *
     * @throws \LogicException when order status does not allow cancellation
     */
    public function cancel(Order $order, string $reason, bool $notify = true): Order
    {
        if (! in_array($order->status, ['pending_payment', 'paid', 'confirmed', 'in_progress'], strict: true)) {
            throw new \LogicException("Zamówienie o statusie '{$order->status}' nie może zostać anulowane");
        }

        if ($order->status === 'in_progress') {
            Log::warning('OrderService::cancel: forcing cancellation of in_progress order', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);
        }

        $order->notifyOnCancel = $notify;

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
