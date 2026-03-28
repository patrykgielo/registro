<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\SubmitCheckoutRequest;
use App\Services\Cart\CartService;
use App\Support\TenantFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * DEV ONLY — fake payment bypass for non-production environments.
 *
 * Accepts the same checkout form as CheckoutController::submit(), creates the
 * order via CartService, transitions it straight to `paid`, then redirects to
 * the standard return page.  Zero Przelewy24 involvement.
 *
 * This controller MUST NEVER be registered in production.
 * The route is wrapped in `if (! app()->isProduction())` in routes/web.php, and
 * this method adds a second defence-in-depth abort at the top.
 */
class FakePaymentController extends Controller
{
    public function __construct(
        protected CartService $cart,
    ) {}

    public function pay(SubmitCheckoutRequest $request): RedirectResponse
    {
        // Defence in depth — abort hard if somehow reached in production.
        abort_if(app()->isProduction(), 404);

        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        try {
            $cart = $this->cart->getOrCreateCart($org, auth()->user());

            $order = $this->cart->convertToOrder(
                $cart,
                array_merge($request->validated(), ['ip' => $request->ip()])
            );

            // Assign a fake session ID so CheckoutController::return() can look up the order.
            $fakeSessionId = 'fake-'.$order->id;

            $order->update([
                'paid_at' => now(),
                'p24_session_id' => $fakeSessionId,
            ]);

            $order->status()->transitionTo('paid');

            return redirect()->route('checkout.return', [
                'sessionId' => $fakeSessionId,
            ]);
        } catch (\Exception $e) {
            Log::error('[DEV] Fake payment failed', ['exception' => $e, 'user_id' => auth()->id()]);

            return redirect()->back()->withErrors(['general' => '[DEV] Fake pay failed: '.$e->getMessage()]);
        }
    }
}
