<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
        // never reached. The CheckoutController catch block redirects back
        // with a 'general' error flash regardless of which exception fired.
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

        $response->assertSessionHasErrors('customer_email');
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
    // P24 failure — checkout rolls back gracefully
    // -------------------------------------------------------------------------

    public function test_przelewy24_failure_returns_error_flash_without_redirect_to_payment(): void
    {
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
}
