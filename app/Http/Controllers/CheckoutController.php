<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Checkout\SubmitCheckoutRequest;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Payment\Przelewy24Service;
use App\Support\TenantFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $cart,
        protected Przelewy24Service $p24,
    ) {}

    /**
     * Display the checkout form.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $cart = $this->cart->getOrCreateCart($org, auth()->user());

        if ($cart->items->isEmpty()) {
            return redirect()->route('cart.show')
                ->withErrors(['general' => 'Twój koszyk jest pusty.']);
        }

        return view('checkout.show', compact('cart'));
    }

    /**
     * Process the checkout and redirect to Przelewy24.
     */
    public function submit(SubmitCheckoutRequest $request): RedirectResponse
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        try {
            $cart = $this->cart->getOrCreateCart($org, auth()->user());

            $order = $this->cart->convertToOrder(
                $cart,
                array_merge($request->validated(), ['ip' => $request->ip()])
            );

            $paymentUrl = $this->p24->registerTransaction($order);

            return redirect($paymentUrl);
        } catch (\Exception $e) {
            Log::error('Checkout failed', ['exception' => $e, 'user_id' => auth()->id()]);

            return redirect()->back()->withErrors(['general' => 'Nie udało się przetworzyć płatności. Spróbuj ponownie.']);
        }
    }

    /**
     * Handle the return from Przelewy24 payment gateway.
     */
    public function return(Request $request): View
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $sessionId = $request->query('sessionId') ?? $request->query('p24_session_id');

        $order = Order::where('p24_session_id', $sessionId)
            ->where('organization_id', $org->id)
            ->where('user_id', auth()->id())
            ->first();

        return view('checkout.return', compact('order'));
    }
}
