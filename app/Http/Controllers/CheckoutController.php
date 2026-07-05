<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Checkout\SubmitCheckoutRequest;
use App\Models\Order;
use App\Services\Analytics\AnalyticsEventDispatcher;
use App\Services\Cart\CartService;
use App\Services\Order\OrderService;
use App\Services\Payment\Przelewy24Service;
use App\Support\Settings\SettingsManager;
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
        protected SettingsManager $settings,
        protected AnalyticsEventDispatcher $analytics,
        protected OrderService $orderService,
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

        if ($cart->checkout_started_at === null) {
            $cart->update(['checkout_started_at' => now()]);
            $this->analytics->trackForCart($cart, 'checkout.started', [
                'item_count' => $cart->items->count(),
                'cart_total' => $cart->items->sum('total_price'),
            ]);
        }

        $user = auth()->user();
        $profileData = [
            'customer_type' => $user->customer_type,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone_e164,
            'pesel' => $user->pesel,
            'street' => $user->street_name,
            'building' => $user->street_number,
            'city' => $user->city,
            'postal_code' => $user->postal_code,
            'company_name' => $user->company_name,
            'nip' => $user->nip,
            'regon' => $user->regon,
            'krs' => $user->krs,
            'billing_street' => $user->billing_street,
            'billing_building' => $user->billing_building_number,
            'billing_city' => $user->billing_city,
            'billing_postal' => $user->billing_postal_code,
        ];

        $orgName = $org->name ?? config('app.name');
        $checkoutSettings = [
            'terms_label' => $this->settings->get('checkout.terms_label', 'Akceptuję Regulamin Wypożyczalni i zapoznałem/am się z warunkami najmu sprzętu.'),
            'rodo_label' => str_replace(
                '{org_name}',
                $orgName,
                $this->settings->get('checkout.rodo_label', "Wyrażam zgodę na przetwarzanie moich danych osobowych przez {$orgName} w celu realizacji umowy najmu zgodnie z art. 6 ust. 1 lit. b) RODO. Dane będą przechowywane przez 5 lat od zakończenia umowy.")
            ),
            'withdrawal_label' => $this->settings->get('checkout.withdrawal_label', 'Przyjmuję do wiadomości, że w związku z określeniem terminów wynajmu (art. 38 ust. 1 pkt 12 Ustawy o Prawach Konsumenta) nie przysługuje mi prawo odstąpienia od umowy na odległość.'),
            'deposit_policy_note' => $this->settings->get('checkout.deposit_policy_note', 'Kaucja pobierana gotówką / kartą przy odbiorze sprzętu. Zwracana po oddaniu sprzętu w stanie nienaruszonym.'),
        ];

        return view('checkout.show', compact('cart', 'profileData', 'checkoutSettings'));
    }

    /**
     * Process the checkout and redirect to Przelewy24.
     */
    public function submit(SubmitCheckoutRequest $request): RedirectResponse
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $cart = $this->cart->getOrCreateCart($org, auth()->user());

        try {
            $order = $this->cart->convertToOrder(
                $cart,
                array_merge($request->validated(), ['ip' => $request->ip()])
            );
        } catch (\Exception $e) {
            Log::error('Checkout failed: could not convert cart to order', ['exception' => $e, 'user_id' => auth()->id()]);

            return redirect()->back()->withErrors(['general' => 'Nie udało się przetworzyć płatności. Spróbuj ponownie.']);
        }

        try {
            $paymentUrl = $this->p24->registerTransaction($order);
        } catch (\Exception $e) {
            // convertToOrder() already committed (Order pending_payment + Cart
            // 'converted'). Leaving it that way would orphan the order (it
            // blocks inventory until TTL) and strand the customer with an
            // empty, unusable cart. Compensate immediately: release the order
            // and restore the same cart so the customer can retry without
            // re-adding every item.
            //
            // notify: false — the customer never saw a completed order (they're
            // still mid-checkout, about to see a generic retry flash), so the
            // customer-facing "your order was cancelled" email would just be
            // confusing noise ahead of an immediate, successful retry.
            Log::error('Checkout failed: P24 registration error — cancelling orphaned order', [
                'exception' => $e,
                'order_id' => $order->id,
                'user_id' => auth()->id(),
            ]);

            $this->orderService->cancel($order, 'P24 registration failed', notify: false);
            $this->cart->reactivate($cart);

            return redirect()->back()->withErrors(['general' => 'Nie udało się przetworzyć płatności. Spróbuj ponownie.']);
        }

        $this->analytics->trackForCart($cart, 'checkout.submitted', [
            'order_id' => $order->id,
            'total_amount' => $order->total_amount,
        ]);

        return redirect($paymentUrl);
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
