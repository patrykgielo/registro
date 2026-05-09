<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Support\TenantFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DEV ONLY — fake payment bypass for non-production environments.
 *
 * Uses the authenticated user's profile data to create the order — no form
 * validation required.  Transitions the order straight to `paid`, then
 * redirects to the standard return page.  Zero Przelewy24 involvement.
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

    public function pay(Request $request): RedirectResponse
    {
        // Defence in depth — abort hard if somehow reached in production.
        abort_if(app()->isProduction(), 404);

        $org = TenantFeature::currentTenant();
        abort_unless($org !== null, 404);

        $user = auth()->user();

        try {
            $cart = $this->cart->getOrCreateCart($org, $user);

            abort_if($cart->items()->count() === 0, 422);

            // Build minimal checkout data from the authenticated user's profile.
            $checkoutData = [
                'customer_first_name' => $user->first_name ?? 'Test',
                'customer_last_name' => $user->last_name ?? 'User',
                'customer_email' => $user->email,
                'customer_phone' => $user->phone ?? '+48000000000',
                'invoice_requested' => false,
                'ip' => $request->ip(),
            ];

            $order = $this->cart->convertToOrder($cart, $checkoutData);

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
