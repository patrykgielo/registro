<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\OrderAcceptedOffline;
use App\Exceptions\PaymentGatewayNotConfiguredException;
use App\Exceptions\RentalUnavailableException;
use App\Http\Requests\Checkout\SubmitCheckoutRequest;
use App\Models\Cart;
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

        // Computed once here (items.service already eager-loaded by getOrCreateCart())
        // instead of being summed twice in checkout/show.blade.php (JS payload + display block).
        $depositTotal = $cart->items->sum(fn ($item) => ($item->service->deposit_amount ?? 0) * $item->quantity);

        $availableSettlementMethods = $this->settings->availableSettlementMethods();
        $offlineReservationHoldHours = $this->settings->offlineReservationHoldHours();
        $peselRequired = $this->settings->isPeselRequired();

        return view('checkout.show', compact(
            'cart',
            'profileData',
            'checkoutSettings',
            'depositTotal',
            'availableSettlementMethods',
            'offlineReservationHoldHours',
            'peselRequired',
        ));
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
        } catch (RentalUnavailableException $e) {
            // MUST be caught before the generic \Throwable below — equipment
            // becoming unavailable between "add to cart" and checkout is normal
            // business reality, not a payment failure, and must not be reported
            // as "Nie udało się przetworzyć płatności": nothing was ever charged,
            // and convertToOrder() throws before creating any Order row here, so
            // there is nothing to compensate. Dedicated 'availability' bag — see
            // CartController::add() for why it stays out of the default bag.
            return redirect()->back()->withErrors($e->messages(), 'availability');
        } catch (\Throwable $e) {
            Log::error('Checkout failed: could not convert cart to order', ['exception' => $e, 'user_id' => auth()->id()]);

            return redirect()->back()->withErrors(['general' => 'Nie udało się przetworzyć płatności. Spróbuj ponownie.']);
        }

        // Marks this attempt as a real order creation for the "checkout-submit"
        // named rate limiter (AppServiceProvider::boot()) — set as soon as the
        // Order row exists (inventory is already briefly held at this point),
        // regardless of what happens downstream (P24 registration failure still
        // compensates/cancels the order, but the resource-consuming action this
        // limiter guards against already happened).
        //
        // Deliberately request()->attributes, NOT $request (the injected
        // SubmitCheckoutRequest): FormRequestServiceProvider builds it via
        // Request::createFrom($app['request'], $request), which snapshots
        // attributes into a brand-new ParameterBag (Request::initialize()) —
        // setting it on $request would be invisible to the RateLimiter
        // closure, which closes over the ORIGINAL request instance the
        // container bound in Kernel::sendRequestThroughRouter().
        request()->attributes->set('checkout_order_created', true);

        if ($order->isOfflineSettlement()) {
            return $this->submitOffline($cart, $order);
        }

        // \Throwable, NOT \Exception. A TypeError raised inside the payment
        // SDK is an \Error: on 2026-08-16 it sailed straight through a
        // `catch (\Exception)` here, so the customer saw a 500 AND the order
        // stayed orphaned in pending_payment with a 'converted' (unusable)
        // cart — the compensation below existed and simply never ran. There is
        // nothing this method can do about ANY throwable from registration
        // except compensate, so it catches everything it can catch.
        try {
            $paymentUrl = $this->p24->registerTransaction($order);
        } catch (\Throwable $e) {
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

            return redirect()->back()->withErrors([
                'general' => $this->registrationFailureMessage($e),
            ]);
        }

        $this->analytics->trackForCart($cart, 'checkout.submitted', [
            'order_id' => $order->id,
            'total_amount' => $order->total_amount,
        ]);

        return redirect($paymentUrl);
    }

    /**
     * User-facing copy for a failed P24 registration.
     *
     * "Spróbuj ponownie" is honest for a refused or timed-out gateway call, and
     * a lie for a gateway that has no credentials on this machine: retrying can
     * never work, and the customer would loop through order-create → cancel →
     * retry forever. That case gets copy that sends them to a human instead.
     *
     * This is the LAST line of defence, not the first: with the gateway
     * unconfigured, SettingsManager::availableSettlementMethods() already stops
     * offering 'online' — and SubmitCheckoutRequest then rejects it — for any
     * tenant that has pay-at-pickup enabled. What still reaches here is the
     * tenant with no other method at all, for whom online is kept as the
     * never-empty fallback, so the message must not point at an alternative
     * they do not have.
     */
    private function registrationFailureMessage(\Throwable $e): string
    {
        if ($e instanceof PaymentGatewayNotConfiguredException) {
            return 'Płatności online są chwilowo niedostępne. Prosimy o kontakt z wypożyczalnią — Twoje zamówienie nie zostało złożone i nic nie zostało pobrane.';
        }

        return 'Nie udało się przetworzyć płatności. Spróbuj ponownie.';
    }

    /**
     * Converts the cart into an order settled offline (pay at pickup) — no
     * Przelewy24 involvement at all. The order sits in pending_payment
     * (blocking inventory, per Order::scopeExpired()/OrderItem::
     * scopeBlockingAvailability()) until staff records the actual cash/
     * transfer payment in the panel (OrderService::recordOfflinePayment()).
     */
    private function submitOffline(Cart $cart, Order $order): RedirectResponse
    {
        $this->analytics->trackForCart($cart, 'checkout.submitted', [
            'order_id' => $order->id,
            'total_amount' => $order->total_amount,
            'settlement_method' => 'offline',
        ]);

        event(new OrderAcceptedOffline($order));

        return redirect()->route('checkout.return', ['order' => $order->id]);
    }

    /**
     * Handle the return from Przelewy24 payment gateway, or the immediate
     * redirect after an offline (pay-at-pickup) checkout submission.
     *
     * Both lookups are scoped to the authenticated user's own orders in the
     * current tenant — an `order` id alone never identifies someone else's
     * order (same trust boundary as `orders.show`).
     */
    public function return(Request $request): View
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $sessionId = $request->query('sessionId') ?? $request->query('p24_session_id');
        $orderId = $request->query('order');

        $query = Order::where('organization_id', $org->id)->where('user_id', auth()->id());

        $order = $sessionId !== null
            ? $query->where('p24_session_id', $sessionId)->first()
            : ($orderId !== null ? $query->find($orderId) : null);

        return view('checkout.return', compact('order'));
    }
}
