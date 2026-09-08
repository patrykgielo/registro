<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Cart;
use App\Services\Analytics\AnalyticsEventDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MarkCartsAbandonedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AnalyticsEventDispatcher $dispatcher): void
    {
        $cutoff = now()->subMinutes(30);

        Cart::active()
            // Sum of units, not row count — see CheckoutController::show()'s
            // 'checkout.started' for why this field must mean the same thing across the
            // whole checkout funnel (checkout.started / cart.abandoned / order.completed).
            ->withSum('items', 'quantity')
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($carts) use ($dispatcher): void {
                foreach ($carts as $cart) {
                    $cart->update(['status' => 'abandoned', 'abandoned_at' => now()]);

                    $dispatcher->trackForCart($cart, 'cart.abandoned', [
                        'cart_id' => $cart->id,
                        'item_count' => $cart->items_sum_quantity ?? 0,
                        'checkout_started' => $cart->checkout_started_at !== null,
                        'last_step' => $cart->last_checkout_step,
                    ]);
                }
            });
    }
}
