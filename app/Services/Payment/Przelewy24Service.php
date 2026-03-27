<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Przelewy24\Exceptions\Przelewy24Exception;
use Przelewy24\Przelewy24;

class Przelewy24Service
{
    private function client(): Przelewy24
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

        $order = Order::where('p24_session_id', $notification->sessionId())->first();

        if (! $order) {
            Log::warning('Przelewy24: order not found for webhook', [
                'session_id' => $notification->sessionId(),
            ]);

            return;
        }

        if ($order->status === 'paid') {
            return;
        }

        $sessionId = $notification->sessionId();
        $orderId = $notification->orderId();
        $amount = $notification->amount();

        try {
            $p24->transactions()->verify($sessionId, $orderId, $amount);

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

            $order->status()->transitionTo('paid');
            $order->update(['paid_at' => now()]);
        } catch (Przelewy24Exception $e) {
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

            Log::error('Przelewy24: transaction verification failed', [
                'session_id' => $sessionId,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
