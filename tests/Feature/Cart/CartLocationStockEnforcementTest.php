<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Faza 6 krok 6.2 fix (rental-availability.md, "pickup location = stock
 * location until Phase 7") — closes the gap where `CartController::add()`
 * never forwarded a `$locationId` to `CartService::addItem()`, so every real
 * add-to-cart validated against the tenant-WIDE pool regardless of which
 * branch the customer had selected: a product page tile could say
 * "unavailable in this branch" while the cart happily sold it anyway.
 *
 * Deliberately drives the REAL HTTP routes (`cart.add`, `checkout.submit`) —
 * never sets `cart_items.location_id` by hand for the item under test — that
 * is exactly how this bug hid behind CartServiceLocationTest's own
 * lower-level, location-parameter-passed-explicitly coverage.
 */
class CartLocationStockEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    private Service $service;

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

        // Absurd quantity_total (999) — same precedent as
        // CartServiceLocationTest: proves the LOCATION branch, not this
        // column, is what every assertion below actually reads.
        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 999,
            'price_per_day' => 100,
        ]);

        // Created AFTER the service so ServiceLocationStockObserver
        // auto-materializes the (zero-quantity) anchor rows for both.
        $this->locationA = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $this->locationB = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);

        // Stocked ONLY at A — B is a real, active, selling branch that
        // simply has none of this item.
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);
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

    private function cartAt(?int $locationId): Cart
    {
        return Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'location_id' => $locationId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addToCartPayload(): array
    {
        return [
            'service_id' => $this->service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'quantity' => 1,
        ];
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

    // -------------------------------------------------------------------------
    // addItem() via the REAL cart.add route
    // -------------------------------------------------------------------------

    public function test_add_to_cart_is_rejected_when_the_customers_selected_branch_has_no_stock(): void
    {
        $this->cartAt($this->locationB->id);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), $this->addToCartPayload());

        $response->assertSessionHasErrors([], null, 'availability');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_add_to_cart_succeeds_when_the_customers_selected_branch_has_stock(): void
    {
        $this->cartAt($this->locationA->id);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), $this->addToCartPayload());

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('cart_items', [
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Legacy carts — cart has a location, item predates this fix (NULL)
    // -------------------------------------------------------------------------

    public function test_legacy_cart_item_with_null_location_is_validated_against_the_carts_own_branch_not_the_global_pool(): void
    {
        $cart = $this->cartAt($this->locationB->id);

        // Simulates a CartItem row added BEFORE this fix existed — every
        // real one had `location_id === null`, regardless of the cart's own
        // (already-populated, since krok 6.1) location.
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => null,
            'quantity' => 1,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
        });

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionHasErrors([], null, 'availability');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_legacy_cart_item_with_null_location_heals_and_checks_out_successfully_when_the_carts_branch_has_stock(): void
    {
        $cart = $this->cartAt($this->locationA->id);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => null,
            'quantity' => 1,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->once()->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake');
        });

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/fake');
        $this->assertDatabaseHas('orders', ['pickup_location_id' => $this->locationA->id]);
        $this->assertDatabaseHas('order_items', [
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Zero-regression: no pickup location at all → today's global-pool behaviour
    // -------------------------------------------------------------------------

    public function test_zero_regression_a_cart_with_no_pickup_location_still_validates_against_the_global_pool(): void
    {
        // No Location adoption at all for this tenant — mirrors a real
        // single-branch/no-branch tenant. Uses its own service (not
        // $this->service, which has an artificially absurd quantity_total
        // that only means anything once the location branch is engaged).
        $plainOrg = Organization::factory()->equipmentRental()->create();
        $plainService = Service::factory()->itemRental()->create([
            'organization_id' => $plainOrg->id,
            'quantity_total' => 1,
        ]);
        $cart = Cart::factory()->active()->create([
            'organization_id' => $plainOrg->id,
            'user_id' => $this->user->id,
            'location_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($plainOrg)
            ->post(route('cart.add'), [
                'service_id' => $plainService->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('cart_items', [
            'service_id' => $plainService->id,
            'location_id' => null,
        ]);
    }
}
