<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    private const OFFLINE_PAYMENT_METHODS = ['cash', 'bank_transfer'];

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
     * Records a manually-collected offline payment (cash / bank transfer at
     * pickup) and transitions the order pending_payment -> paid — the exact
     * same legal transition the P24 webhook uses, so downstream logic
     * (reconciliation guard, deposit workflow, protocols) needs no changes.
     *
     * Mirrors Przelewy24Service::handleWebhook(): lock the row, re-check
     * status under the lock (defends against a double-click / concurrent
     * "odnotuj wpłatę" submission), create the Payment audit row, transition,
     * stamp paid_at — all inside one transaction. OrderPaid is dispatched
     * AFTER the transaction commits (not inside it, unlike
     * Przelewy24Service's pre-existing pattern — see notifications.md on
     * notify()-inside-DB::transaction()) so a rollback can never leave a
     * "your order was paid" email in flight for a payment that didn't stick.
     *
     * $amount is staff-entered and NOT required to equal $order->total_amount
     * — we don't support partial payments (no instalments), but we DO support
     * a deliberate discount given in person at pickup, which is a real thing
     * in this business. A mismatch must therefore be POSSIBLE but never
     * ACCIDENTAL: any amount that doesn't equal the order total requires
     * $amountMismatchConfirmed = true AND a non-empty $notes explaining why —
     * enforced here, not only in the Filament form, so no caller can silently
     * mark an order paid for the wrong amount with nothing downstream
     * (deposit workflow, protocols, state machine) ever re-checking it.
     *
     * @throws \InvalidArgumentException when $method is invalid, or the amount doesn't
     *                                   match the order total and the mismatch wasn't
     *                                   explicitly confirmed with a reason
     * @throws \LogicException when the order is not awaiting payment
     */
    public function recordOfflinePayment(
        Order $order,
        float $amount,
        string $method,
        ?string $notes,
        int $recordedByUserId,
        bool $amountMismatchConfirmed = false,
    ): Order {
        if (! in_array($method, self::OFFLINE_PAYMENT_METHODS, strict: true)) {
            throw new \InvalidArgumentException("Nieprawidłowa metoda rozliczenia: '{$method}'.");
        }

        $amountCents = (int) round($amount * 100);
        $expectedCents = (int) round(((float) $order->total_amount) * 100);

        if ($amountCents !== $expectedCents) {
            if (! $amountMismatchConfirmed) {
                throw new \InvalidArgumentException(sprintf(
                    'Kwota (%s zł) różni się od sumy zamówienia (%s zł). Jeśli to zamierzone (np. rabat przy odbiorze), potwierdź rozbieżność.',
                    number_format($amount, 2, ',', ' '),
                    number_format((float) $order->total_amount, 2, ',', ' '),
                ));
            }

            if ($notes === null || trim($notes) === '') {
                throw new \InvalidArgumentException('Podaj powód rozbieżności kwoty w notatce.');
            }
        }

        $order = DB::transaction(function () use ($order, $amount, $method, $notes, $recordedByUserId): Order {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending_payment') {
                throw new \LogicException("Zamówienie o statusie '{$locked->status}' nie oczekuje na płatność.");
            }

            Payment::create([
                'order_id' => $locked->id,
                'organization_id' => $locked->organization_id,
                'method' => $method,
                'amount' => (int) round($amount * 100),
                'currency' => $locked->currency,
                'status' => 'success',
                'recorded_by' => $recordedByUserId,
                'notes' => $notes,
                'verified_at' => now(),
            ]);

            $locked->status()->transitionTo('paid');
            $locked->update(['paid_at' => now()]);

            return $locked;
        });

        event(new OrderPaid($order));

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
