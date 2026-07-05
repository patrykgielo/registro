<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentReconciliationAlertNotification;
use Asantibanez\LaravelEloquentStateMachines\Exceptions\TransitionNotAllowedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use Przelewy24\Exceptions\Przelewy24Exception;
use Przelewy24\Przelewy24;

class Przelewy24Service
{
    protected function client(): Przelewy24
    {
        return new Przelewy24(
            merchantId: config('przelewy24.merchant_id'),
            reportsKey: config('przelewy24.reports_key'),
            crc: config('przelewy24.crc'),
            isLive: config('przelewy24.is_live'),
            posId: config('przelewy24.pos_id'),
        );
    }

    /**
     * Register a transaction with Przelewy24 and return the payment gateway URL.
     *
     * @throws Przelewy24Exception
     */
    public function registerTransaction(Order $order): string
    {
        $sessionId = 'ORDER-'.$order->id.'-'.time();
        $amount = (int) round($order->total_amount * 100);

        $p24 = $this->client();

        $response = $p24->transactions()->register(
            sessionId: $sessionId,
            amount: $amount,
            description: 'Zamówienie '.$order->order_number,
            email: $order->customer_email,
            urlReturn: route('checkout.return'),
            urlStatus: route('webhooks.p24'),
        );

        $order->update([
            'p24_session_id' => $sessionId,
            'p24_token' => $response->token(),
            'p24_amount' => $amount,
        ]);

        return $response->gatewayUrl();
    }

    /**
     * Handle an incoming Przelewy24 webhook notification.
     */
    public function handleWebhook(array $payload): void
    {
        $p24 = $this->client();

        $notification = $p24->handleWebhook($payload);

        $isSignValid = $notification->isSignValid(
            sessionId: $notification->sessionId(),
            amount: $notification->amount(),
            originAmount: $notification->originAmount(),
            orderId: $notification->orderId(),
            methodId: $notification->methodId(),
            statement: $notification->statement(),
        );

        if (! $isSignValid) {
            Log::warning('Przelewy24: invalid webhook signature', [
                'session_id' => $notification->sessionId(),
            ]);

            return;
        }

        $sessionId = $notification->sessionId();
        $orderId = $notification->orderId();
        $amount = $notification->amount();

        // Cheap, lock-free pre-check: skips the network round-trip to P24's
        // verify() API entirely for unknown sessions or orders already marked
        // paid. This is just an early-exit optimisation, NOT the authoritative
        // idempotency guard — that's the locked re-check performed below,
        // AFTER the network call.
        $order = Order::where('p24_session_id', $sessionId)->first();

        if (! $order) {
            Log::warning('Przelewy24: order not found for webhook', [
                'session_id' => $sessionId,
            ]);

            return;
        }

        if ($order->status === 'paid') {
            return;
        }

        try {
            // Deliberately OUTSIDE any DB transaction/lock: this is a live
            // network call to P24's API (Guzzle client timeout ~30s) and must
            // never hold a DB row lock (or an open transaction/connection)
            // across it — doing so under a webhook retry burst during a P24
            // slowdown could exhaust the DB connection pool and PHP-FPM
            // workers simultaneously.
            $p24->transactions()->verify($sessionId, $orderId, $amount);
        } catch (Przelewy24Exception $e) {
            try {
                Payment::create([
                    'order_id' => $order->id,
                    'organization_id' => $order->organization_id,
                    'p24_session_id' => $sessionId,
                    'p24_order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => 'PLN',
                    'status' => 'failed',
                    'webhook_payload' => $payload,
                    'verified_at' => null,
                ]);
            } catch (QueryException $queryException) {
                Log::error('Przelewy24: failed to record failed-payment audit row (possible concurrent webhook / duplicate)', [
                    'order_id' => $order->id,
                    'session_id' => $sessionId,
                    'message' => $queryException->getMessage(),
                ]);
            }

            Log::error('Przelewy24: transaction verification failed', [
                'session_id' => $sessionId,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // Money is confirmed captured by P24 at this point (verify()
        // succeeded) — everything from here is a short, lock-scoped
        // transaction that never spans the network call above. The lock is
        // (re-)acquired here, AFTER the round-trip, specifically to protect
        // against a concurrent webhook delivery for the same session
        // completing the transition while THIS delivery was blocked on the
        // P24 API call — re-checking status !== 'paid' under this fresh lock
        // is what actually makes the whole flow idempotent, not the earlier
        // lock-free pre-check.
        DB::transaction(function () use ($order, $sessionId, $orderId, $amount, $payload): void {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $order || $order->status === 'paid') {
                return;
            }

            $wasCancelled = $order->status === 'cancelled';

            try {
                Payment::create([
                    'order_id' => $order->id,
                    'organization_id' => $order->organization_id,
                    'p24_session_id' => $sessionId,
                    'p24_order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => 'PLN',
                    'status' => 'success',
                    'webhook_payload' => $payload,
                    'verified_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Most likely a concurrent duplicate webhook hitting the
                // unique constraint on payments.p24_session_id (e.g. a retry
                // from P24 racing a separate request/process — the
                // lockForUpdate() above only serialises against other callers
                // going through THIS transaction). Log with full order
                // context instead of an unhelpful bare constraint-violation
                // trace, and don't attempt the transition — we can't tell
                // whether the winning writer already completed it.
                Log::error('Przelewy24: failed to record successful payment (possible concurrent webhook / duplicate)', [
                    'order_id' => $order->id,
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            try {
                $order->status()->transitionTo('paid');
            } catch (TransitionNotAllowedException|ValidationException $e) {
                // A verified, successful payment exists but the order is in a
                // status the state machine refuses to move to 'paid' (e.g.
                // refunded/completed, or the cancelled -> paid reconciliation
                // guard itself rejecting for some reason). This must never be
                // silently swallowed — it means real captured money is not
                // reflected on the Order and needs manual staff review.
                Log::critical('Przelewy24: payment captured but order transition to paid was blocked — needs manual reconciliation', [
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                ]);

                NotificationFacade::send(
                    User::role('super-admin')->get(),
                    new PaymentReconciliationAlertNotification($order, 'blocked', $e->getMessage())
                );

                return;
            }

            $order->update(['paid_at' => now()]);

            if ($wasCancelled) {
                // Late webhook reconciliation: orders:cleanup-expired already
                // cancelled this order (TTL race) before this genuine P24
                // success webhook arrived. The order was just recovered back
                // to 'paid' by the transition above — flag this loudly so
                // staff notice a previously "dead" order came back to life and
                // can double-check inventory/reservation conflicts.
                Log::warning('Przelewy24: late payment reconciled — order was cancelled by TTL cleanup before webhook arrived', [
                    'order_id' => $order->id,
                    'session_id' => $sessionId,
                ]);

                NotificationFacade::send(
                    User::role('super-admin')->get(),
                    new PaymentReconciliationAlertNotification($order, 'reconciled')
                );
            }

            event(new OrderPaid($order));
        });
    }
}
