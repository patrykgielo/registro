<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        // This suite is P24-only by design (every payload below submits
        // 'online') — give the machine real-looking credentials so
        // SettingsManager::isOnlineSettlementEnabled() is true on its own
        // merit, not by accident of availableSettlementMethods()'s ['online']
        // fail-safe (which only applies when NEITHER method is available).
        config([
            'przelewy24.merchant_id' => 12345,
            'przelewy24.reports_key' => 'reports-key',
            'przelewy24.crc' => 'crc-value',
        ]);

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->user = User::factory()->create();
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the project.
     */
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    /**
     * Build a valid checkout form payload (natural person / B2C).
     *
     * @return array<string, mixed>
     */
    private function validCheckoutPayload(): array
    {
        return [
            // Customer type
            'customer_type' => 'natural_person',
            // Settlement method — this suite is P24-only by design; setUp() gives
            // the machine P24 credentials so 'online' is genuinely available (not
            // relying on availableSettlementMethods()'s ['online'] fail-safe).
            'settlement_method' => 'online',
            // Personal data
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'customer_email' => 'jan.kowalski@test.pl',
            'customer_phone' => '500100200',
            // PESEL — valid checksum
            'customer_pesel' => '44051401458',
            // Contract address
            'customer_street' => 'Marszałkowska',
            'customer_building' => '1',
            'customer_apartment' => null,
            'customer_city' => 'Warszawa',
            'customer_postal_code' => '00-001',
            // Invoice (optional for natural person)
            'invoice_requested' => false,
            // Legal acceptances
            'terms_accepted' => true,
            'rodo_accepted' => true,
            'withdrawal_exclusion_accepted' => true,
        ];
    }

    /**
     * Create an active cart with one item for the given user + org.
     */
    private function cartWithItem(User $user, Organization $org, Service $service): Cart
    {
        $cart = Cart::factory()->active()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'quantity' => 1,
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        return $cart;
    }

    // -------------------------------------------------------------------------
    // Guest protection
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login_on_submit(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Empty / no active cart
    // -------------------------------------------------------------------------

    public function test_submit_with_empty_cart_redirects_back_with_general_error(): void
    {
        // CartService::convertToOrder() now guards against empty carts and
        // throws CartNotActiveException before creating any Order — P24 is
        // never reached, so no Order/OrderCancelled compensation path fires
        // either. The CheckoutController catch block redirects back with a
        // 'general' error flash regardless of which exception fired.
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect();
        $response->assertSessionHasErrors('general');

        $this->assertDatabaseCount('orders', 0);
    }

    // -------------------------------------------------------------------------
    // Happy path — full checkout creates order and redirects to payment URL
    // -------------------------------------------------------------------------

    public function test_checkout_creates_order_and_redirects_to_payment_url(): void
    {
        $fakePaymentUrl = 'https://sandbox.przelewy24.pl/trnRequest/fake-token';

        $this->mock(Przelewy24Service::class, function ($mock) use ($fakePaymentUrl) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andReturn($fakePaymentUrl);
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect($fakePaymentUrl);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'customer_email' => 'jan.kowalski@test.pl',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'status' => 'pending_payment',
        ]);
    }

    public function test_cart_status_becomes_converted_after_successful_checkout(): void
    {
        $fakePaymentUrl = 'https://sandbox.przelewy24.pl/trnRequest/fake-token';

        $this->mock(Przelewy24Service::class, function ($mock) use ($fakePaymentUrl) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andReturn($fakePaymentUrl);
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $cart = $this->cartWithItem($this->user, $this->org, $service);

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'status' => 'converted',
        ]);
    }

    public function test_order_items_are_persisted_after_checkout(): void
    {
        $fakePaymentUrl = 'https://sandbox.przelewy24.pl/trnRequest/fake-token';

        $this->mock(Przelewy24Service::class, function ($mock) use ($fakePaymentUrl) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andReturn($fakePaymentUrl);
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $order = Order::where('user_id', $this->user->id)->first();

        $this->assertNotNull($order);
        $this->assertEquals(1, $order->items()->count());
    }

    // -------------------------------------------------------------------------
    // Validation — required fields
    // -------------------------------------------------------------------------

    public function test_missing_customer_email_fails_validation(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $payload = $this->validCheckoutPayload();
        unset($payload['customer_email']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        // A real validation error must still look like one — default bag,
        // not the 'availability' bag used for equipment-unavailable messaging.
        $response->assertSessionHasErrors('customer_email');
        $this->assertFalse(session('errors')->getBag('availability')->any());
    }

    public function test_missing_customer_first_name_fails_validation(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $payload = $this->validCheckoutPayload();
        unset($payload['customer_first_name']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionHasErrors('customer_first_name');
    }

    public function test_missing_customer_last_name_fails_validation(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $payload = $this->validCheckoutPayload();
        unset($payload['customer_last_name']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionHasErrors('customer_last_name');
    }

    // -------------------------------------------------------------------------
    // Availability conflict at checkout — must not be reported as a payment
    // failure (see CheckoutController::submit()'s RentalUnavailableException
    // catch, added ahead of the generic \Throwable one).
    // -------------------------------------------------------------------------

    public function test_availability_conflict_at_checkout_is_not_reported_as_payment_failure(): void
    {
        // P24 must never be reached: convertToOrder() throws before any Order
        // is created, so there is nothing to register a transaction for.
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 1,
        ]);

        $cart = $this->cartWithItem($this->user, $this->org, $service);

        // Another customer grabs the only unit for the exact same dates in the
        // meantime — a stale cart is now unfulfillable.
        $competingOrder = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create()->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $competingOrder->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'start_date' => $cart->items->first()->start_date,
            'end_date' => $cart->items->first()->end_date,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect();

        // Availability, not a generic payment-failure 'general' error.
        $response->assertSessionHasErrors([], null, 'availability');
        $this->assertFalse(session('errors')->getBag('default')->has('general'));

        $this->assertDatabaseCount('orders', 1); // only the competing order
    }

    public function test_checkout_reports_all_unavailable_items_from_a_multi_item_cart(): void
    {
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        $wiertarka = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'name' => 'Wiertarka udarowa',
            'quantity_total' => 1,
        ]);
        $betoniarka = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'name' => 'Betoniarka',
            'quantity_total' => 1,
        ]);

        $cart = Cart::factory()->active()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
        ]);

        $dates = ['start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString()];

        foreach ([$wiertarka, $betoniarka] as $service) {
            CartItem::factory()->create(array_merge([
                'cart_id' => $cart->id,
                'service_id' => $service->id,
                'quantity' => 1,
                'rental_days' => 3,
                'unit_price' => 100.00,
                'total_price' => 300.00,
            ], $dates));
        }

        // Both already fully booked by another customer for the same dates.
        $competingOrder = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create()->id,
        ]);
        foreach ([$wiertarka, $betoniarka] as $service) {
            OrderItem::factory()->create(array_merge([
                'order_id' => $competingOrder->id,
                'service_id' => $service->id,
                'quantity' => 1,
            ], $dates));
        }

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect();

        $messages = implode(' ', session('errors')->getBag('availability')->all());

        $this->assertStringContainsString('Wiertarka udarowa', $messages);
        $this->assertStringContainsString('Betoniarka', $messages);
    }

    // -------------------------------------------------------------------------
    // P24 failure — checkout rolls back gracefully
    // -------------------------------------------------------------------------

    public function test_przelewy24_failure_returns_error_flash_without_redirect_to_payment(): void
    {
        // The compensation path (OrderService::cancel()) fires OrderCancelled,
        // which sends a templated customer notification — irrelevant here.
        Notification::fake();

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \RuntimeException('P24 connection refused'));
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect();
        $response->assertSessionHasErrors('general');
    }

    // -------------------------------------------------------------------------
    // P24 failure — compensation: order cancelled, cart restored to usable state
    // -------------------------------------------------------------------------

    public function test_przelewy24_failure_cancels_orphaned_order(): void
    {
        // OrderService::cancel($order, $reason, notify: false) is used for this
        // compensation path, so no customer notification is ever dispatched —
        // see test_przelewy24_failure_does_not_send_customer_cancellation_email()
        // below. Notification::fake() here is just defensive isolation.
        Notification::fake();

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \RuntimeException('P24 connection refused'));
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $order = Order::where('user_id', $this->user->id)->first();

        $this->assertNotNull($order);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_przelewy24_failure_does_not_send_customer_cancellation_email(): void
    {
        // The customer never saw a completed order (they're mid-checkout,
        // about to see a generic retry flash) — a "your order was cancelled"
        // email would just be confusing noise ahead of an immediate, successful
        // retry. See OrderService::cancel(..., notify: false).
        Notification::fake();

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \RuntimeException('P24 connection refused'));
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->cartWithItem($this->user, $this->org, $service);

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        Notification::assertNotSentTo($this->user, OrderCancelledNotification::class);
    }

    public function test_przelewy24_failure_restores_cart_to_active_with_items_intact(): void
    {
        Notification::fake();

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \RuntimeException('P24 connection refused'));
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $cart = $this->cartWithItem($this->user, $this->org, $service);

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $cart->refresh();

        $this->assertSame('active', $cart->status);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_przelewy24_failure_then_retry_reuses_same_cart_and_succeeds(): void
    {
        Notification::fake();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $cart = $this->cartWithItem($this->user, $this->org, $service);

        // Laravel caches the resolved controller instance on the Route object
        // for the lifetime of the test, so both requests below share the SAME
        // Przelewy24Service mock instance — configure it with two sequential
        // expectations (fail once, then succeed) rather than rebinding the
        // container mid-test (a second $this->mock() call would have no
        // effect on the already-instantiated controller).
        $fakePaymentUrl = 'https://sandbox.przelewy24.pl/trnRequest/retry-token';
        $this->mock(Przelewy24Service::class, function ($mock) use ($fakePaymentUrl) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \RuntimeException('P24 connection refused'));

            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andReturn($fakePaymentUrl);
        });

        // First attempt fails at the P24 registration step.
        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        // Retry with the exact same (now-reactivated) cart — no re-adding items.
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect($fakePaymentUrl);

        $cart->refresh();
        $this->assertSame('converted', $cart->status);

        $this->assertDatabaseHas('orders', [
            'cart_id' => $cart->id,
            'status' => 'pending_payment',
        ]);
    }
}
