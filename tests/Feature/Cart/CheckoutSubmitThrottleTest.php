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
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pins the "checkout-submit" named rate limiter (AppServiceProvider::boot()):
 * it only counts an attempt when CheckoutController::submit() actually
 * created an Order, not every POST to the route. A customer iterating
 * through this form's validation errors (PESEL/NIP/REGON checksums, business
 * address, three consent checkboxes) must not burn the same 10/min budget as
 * a real order — the abuse vector this limiter defends against is order
 * CREATION (it briefly holds inventory and calls the P24 gateway), which is
 * already bounded by equipment availability.
 *
 * Deliberately does NOT disable ThrottleRequests (unlike CheckoutFlowTest) —
 * that middleware is exactly what's under test here.
 */
class CheckoutSubmitThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->user = User::factory()->create();
    }

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
     * @return array<string, mixed>
     */
    private function validCheckoutPayload(): array
    {
        return [
            'customer_type' => 'natural_person',
            'settlement_method' => 'online',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'customer_email' => 'jan.kowalski@test.pl',
            'customer_phone' => '500100200',
            'customer_pesel' => '44051401458',
            'customer_street' => 'Marszałkowska',
            'customer_building' => '1',
            'customer_apartment' => null,
            'customer_city' => 'Warszawa',
            'customer_postal_code' => '00-001',
            'invoice_requested' => false,
            'terms_accepted' => true,
            'rodo_accepted' => true,
            'withdrawal_exclusion_accepted' => true,
        ];
    }

    private function cartWithItem(Service $service, int $startOffsetDays): Cart
    {
        $cart = Cart::factory()->active()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'start_date' => now()->addDays($startOffsetDays)->toDateString(),
            'end_date' => now()->addDays($startOffsetDays + 2)->toDateString(),
            'quantity' => 1,
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        return $cart;
    }

    public function test_repeated_validation_failures_do_not_exhaust_the_throttle_bucket(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($service, 1);

        $payload = $this->validCheckoutPayload();
        unset($payload['customer_email']);

        // 15 failed submissions — more than the 10/min cap — must all fail
        // validation the same way, never 429.
        foreach (range(1, 15) as $_) {
            $response = $this->actingAs($this->user)
                ->actingAsTenant($this->org)
                ->post(route('checkout.submit'), $payload);

            $response->assertSessionHasErrors('customer_email');
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_successful_order_creations_are_still_rate_limited(): void
    {
        $fakePaymentUrl = 'https://sandbox.przelewy24.pl/trnRequest/fake-token';

        $this->mock(Przelewy24Service::class, function ($mock) use ($fakePaymentUrl) {
            $mock->shouldReceive('registerTransaction')->andReturn($fakePaymentUrl);
        });

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 50,
        ]);

        // 10 real order-creating submissions — each its own cart/date range so
        // none collide on availability — exhaust the bucket.
        for ($i = 0; $i < 10; $i++) {
            $this->cartWithItem($service, $i * 10);

            $response = $this->actingAs($this->user)
                ->actingAsTenant($this->org)
                ->post(route('checkout.submit'), $this->validCheckoutPayload());

            $response->assertRedirect($fakePaymentUrl);
        }

        $this->assertDatabaseCount('orders', 10);

        // 11th real attempt: bucket exhausted, blocked before ever reaching
        // the controller (no 11th order created).
        $this->cartWithItem($service, 200);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertStatus(429);
        $this->assertDatabaseCount('orders', 10);
    }

    public function test_order_created_then_cancelled_by_a_p24_failure_still_counts_towards_the_budget(): void
    {
        // The order-creation side effect (brief inventory hold + DB write) is
        // exactly what this limiter guards against, regardless of what
        // happens downstream — even a P24 registration failure that
        // compensates (cancels the order, restores the cart) must count.
        Notification::fake();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 50,
        ]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \RuntimeException('P24 connection refused'));

            $mock->shouldReceive('registerTransaction')
                ->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake-token');
        });

        // 1st attempt: order created, then cancelled by the P24 failure.
        $this->cartWithItem($service, 1);
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionHasErrors('general');
        $this->assertSame('cancelled', Order::first()->status);

        // 9 more real, successful order-creating attempts — 10 total real
        // hits, exhausting the bucket. The first of these reuses the cart
        // OrderService::cancel()'s compensation already reactivated (still
        // 'active' with its original item) — CartService::getOrCreateCart()
        // enforces one active cart per user+org, so creating a brand new one
        // here would violate that unique constraint.
        for ($i = 0; $i < 9; $i++) {
            if ($i > 0) {
                $this->cartWithItem($service, ($i + 1) * 10);
            }

            $response = $this->actingAs($this->user)
                ->actingAsTenant($this->org)
                ->post(route('checkout.submit'), $this->validCheckoutPayload());

            $response->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/fake-token');
        }

        // 11th real attempt overall (1 cancelled + 9 successful so far):
        // blocked, proving the cancelled attempt already spent 1 of the 10.
        $this->cartWithItem($service, 200);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertStatus(429);
    }
}
