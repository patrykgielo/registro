<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Faza 6 kroki 6.3/6.4 (plan-wdrozenia.md) — SubmitCheckoutRequest's
 * `pickup_location_id` gate, driven through the real HTTP checkout flow
 * (unlike CartServicePickupLocationTest, which drives CartService directly).
 * Same `actingAsTenant()`/payload helpers as CheckoutFlowTest.php.
 */
class CheckoutPickupLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        config([
            'przelewy24.merchant_id' => 12345,
            'przelewy24.reports_key' => 'reports-key',
            'przelewy24.crc' => 'crc-value',
        ]);

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

    private function cartWithItem(Organization $org, ?int $locationId = null): Cart
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 5,
        ]);

        $cart = Cart::factory()->active()->create([
            'user_id' => $this->user->id,
            'organization_id' => $org->id,
            'location_id' => $locationId,
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
    // Zero-location tenant — untouched, zero-regression baseline
    // -------------------------------------------------------------------------

    public function test_zero_location_tenant_checks_out_successfully_with_no_pickup_location(): void
    {
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->once()->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake');
        });

        $this->cartWithItem($this->org);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/fake');
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'pickup_location_id' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Single-location tenant — zero extra steps
    // -------------------------------------------------------------------------

    public function test_single_location_tenant_checks_out_successfully_with_zero_extra_steps(): void
    {
        $location = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->once()->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake');
        });

        // No location_id passed to cartWithItem() — CartService::getOrCreateCart()
        // stamps it automatically from LocationContext for a single-location
        // tenant, exactly as it would for a real customer who never sees a
        // switcher (LocationContext::selectionRequired() is false).
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);
        $response->assertRedirect();

        $checkoutResponse = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $checkoutResponse->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/fake');
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
        ]);
    }

    // -------------------------------------------------------------------------
    // Multi-location tenant — ambiguous, fail-closed
    // -------------------------------------------------------------------------

    public function test_multi_location_tenant_with_nothing_selected_is_rejected(): void
    {
        Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        $this->cartWithItem($this->org, locationId: null);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionHasErrors('pickup_location_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_multi_location_tenant_with_a_location_already_on_the_cart_checks_out_successfully(): void
    {
        [$locationA, $locationB] = Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->once()->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake');
        });

        $this->cartWithItem($this->org, locationId: $locationB->id);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/fake');
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'pickup_location_id' => $locationB->id,
            'pickup_location_name' => $locationB->name,
        ]);
    }

    // -------------------------------------------------------------------------
    // Cross-tenant / inactive location — defense in depth
    // -------------------------------------------------------------------------

    public function test_a_location_belonging_to_another_organization_on_the_cart_is_rejected(): void
    {
        $otherOrg = Organization::factory()->equipmentRental()->create();
        $foreignLocation = Location::factory()->for($otherOrg, 'organization')->create(['is_active' => true]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        // A cart's location_id pointing at another tenant's Location cannot
        // happen through any normal write path (LocationContext::set() and
        // CartService::getOrCreateCart() both scope by the current tenant) —
        // this proves the validation layer itself is defense-in-depth
        // against a corrupted/stale row, not just "our own code never
        // produces this".
        $this->cartWithItem($this->org, locationId: $foreignLocation->id);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionHasErrors('pickup_location_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_deactivated_location_on_the_cart_is_rejected(): void
    {
        $location = Location::factory()->for($this->org, 'organization')->create(['is_active' => false]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        $this->cartWithItem($this->org, locationId: $location->id);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionHasErrors('pickup_location_id');
        $this->assertDatabaseCount('orders', 0);
    }
}
