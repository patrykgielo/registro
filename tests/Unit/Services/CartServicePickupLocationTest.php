<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\PickupLocationRequiredException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Support\LocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faza 6 kroki 6.1/6.3/6.4 (plan-wdrozenia.md) — the pickup-location spine:
 * CartService::getOrCreateCart() stamps `carts.location_id` from
 * LocationContext at creation time, and CartService::convertToOrder()
 * propagates it onto the Order (with a name/address snapshot), fail-closed
 * ONLY for the genuinely ambiguous case (LocationContext::mustPrompt()'s own
 * exact conjunction — see that method's docblock). Deliberately a separate
 * file from CartServiceLocationTest.php: that file is about the Faza 4
 * AVAILABILITY dimension (cart_items.location_id, unwired in production
 * today); this one is about the Faza 6 PICKUP dimension (carts.location_id
 * / orders.pickup_location_id) — the two are independent, see
 * carts.location_id's own migration docblock for why.
 */
class CartServicePickupLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->cartService = app(CartService::class);
    }

    private function actingAsTenant(Organization $org): void
    {
        $this->app['request']->attributes->set('tenant', $org);
    }

    private function cartWithItem(Cart $cart, Service $service): CartItem
    {
        return CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'quantity' => 1,
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);
    }

    // -------------------------------------------------------------------------
    // getOrCreateCart() — stamp on creation only
    // -------------------------------------------------------------------------

    public function test_get_or_create_cart_stamps_location_id_for_a_single_location_tenant(): void
    {
        $location = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $this->actingAsTenant($this->org);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);

        $this->assertSame($location->id, $cart->fresh()->location_id);
    }

    public function test_get_or_create_cart_leaves_location_id_null_for_a_zero_location_tenant(): void
    {
        $this->actingAsTenant($this->org);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);

        $this->assertNull($cart->fresh()->location_id);
    }

    public function test_get_or_create_cart_leaves_location_id_null_for_a_multi_location_tenant_with_nothing_selected(): void
    {
        Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $this->actingAsTenant($this->org);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);

        $this->assertNull($cart->fresh()->location_id);
    }

    public function test_get_or_create_cart_stamps_the_explicitly_selected_location_for_a_multi_location_tenant(): void
    {
        [$locationA, $locationB] = Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($locationB);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);

        $this->assertSame($locationB->id, $cart->fresh()->location_id);
    }

    /**
     * The whole point of krok 6.1's docblock: an EXISTING cart's location is
     * never silently overwritten by a later call, even if the session
     * selection changes in between — that is krok 6.2's
     * `CartService::setLocation()` job (not yet built), not
     * getOrCreateCart()'s.
     */
    public function test_get_or_create_cart_does_not_overwrite_an_existing_carts_location_on_a_later_call(): void
    {
        [$locationA, $locationB] = Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $this->actingAsTenant($this->org);
        $locationContext = app(LocationContext::class);

        $locationContext->set($locationA);
        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->assertSame($locationA->id, $cart->fresh()->location_id);

        // Customer switches location mid-session — the ALREADY-CREATED cart
        // must not silently follow.
        $locationContext->set($locationB);
        $sameCart = $this->cartService->getOrCreateCart($this->org, $this->user);

        $this->assertSame($cart->id, $sameCart->id);
        $this->assertSame($locationA->id, $sameCart->fresh()->location_id);
    }

    // -------------------------------------------------------------------------
    // convertToOrder() — propagation + fail-closed guard
    // -------------------------------------------------------------------------

    public function test_convert_to_order_propagates_the_carts_location_and_snapshot_to_the_order(): void
    {
        $location = Location::factory()->for($this->org, 'organization')->create([
            'name' => 'Oddział Poznań',
            'street' => 'Półwiejska',
            'building' => '10',
            'postal_code' => '61-000',
            'city' => 'Poznań',
        ]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);

        $order = $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());

        $this->assertSame($location->id, $order->pickup_location_id);
        $this->assertSame('Oddział Poznań', $order->pickup_location_name);
        $this->assertStringContainsString('Półwiejska', $order->pickup_location_address);
        $this->assertStringContainsString('Poznań', $order->pickup_location_address);
    }

    public function test_convert_to_order_succeeds_with_a_null_pickup_location_for_a_zero_location_tenant(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);

        $order = $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());

        $this->assertNull($order->pickup_location_id);
        $this->assertNull($order->pickup_location_name);
        $this->assertNull($order->pickup_location_address);
    }

    /**
     * Falsifiable core of krok 6.4: a genuinely ambiguous tenant (2+ active
     * locations, nothing resolved) must NOT be able to check out at all.
     */
    public function test_convert_to_order_throws_when_ambiguous_for_a_multi_location_tenant(): void
    {
        Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);

        $this->assertNull($cart->fresh()->location_id);
        $this->expectException(PickupLocationRequiredException::class);

        $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());
    }

    public function test_convert_to_order_does_not_throw_when_a_multi_location_tenants_cart_already_has_a_location(): void
    {
        [$locationA] = Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($locationA);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);

        $order = $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());

        $this->assertSame($locationA->id, $order->pickup_location_id);
    }

    /**
     * TOCTOU race: the Location referenced by the cart is deleted (nullOnDelete
     * clears carts.location_id) between cart creation and checkout, on a
     * genuinely multi-location (ambiguous) tenant — this method's own
     * lockForUpdate()'d re-read must still reject, not silently create an
     * order with no pickup point.
     */
    public function test_convert_to_order_throws_when_the_carts_location_was_deleted_before_checkout_on_a_multi_location_tenant(): void
    {
        // Three locations, not two: deleting the cart's own location must
        // still leave a GENUINELY ambiguous tenant behind (2 remaining active
        // locations) — with only two total, deleting one would silently turn
        // this into the single-location case tested above and prove nothing
        // about the guard under test. The FIRST location created for an org
        // is auto-promoted to primary (LocationObserver) and cannot be
        // deleted without promoting another first — deliberately NOT the one
        // this test deletes.
        Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $location = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($location);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);
        $this->assertSame($location->id, $cart->fresh()->location_id);

        $location->delete();

        $this->expectException(PickupLocationRequiredException::class);

        $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());
    }

    /**
     * Code review 2026-09-10 — the SECOND TOCTOU race, distinct from
     * deletion above: the cart's Location is DEACTIVATED (not deleted) in
     * the window between checkout validation and this method's locked
     * re-read. Deactivation does NOT touch `carts.location_id` (no FK, no
     * observer clears it) — before `->active()` was added to the query in
     * CartService::convertToOrder(), a bare find() would still have
     * resolved the now-closed row and this guard would never have fired.
     * Simulated via a direct DB write (LocationObserver's own guard would
     * otherwise block this exact deactivation on a normal Eloquent path —
     * see LocationDeactivationGuardTest — but a genuine race by definition
     * lands after that check already passed on someone else's request).
     */
    public function test_convert_to_order_throws_when_the_carts_location_was_deactivated_before_checkout_on_a_multi_location_tenant(): void
    {
        Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $location = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($location);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);
        $this->assertSame($location->id, $cart->fresh()->location_id);

        DB::table('locations')->where('id', $location->id)->update(['is_active' => false]);

        $this->expectException(PickupLocationRequiredException::class);

        $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());
    }

    /**
     * Code review 2026-09-10 — the named, correct side effect: deactivating
     * a DIFFERENT location than the one this cart points to can drop the
     * tenant's active count to exactly ONE without ever touching this
     * cart's own (now inactive) location. The order must still succeed,
     * with a null pickup point — identical to a genuine 0-/1-location
     * tenant, not a rejection. Not the same test as the one above: there,
     * 2 OTHER active locations remain (ambiguous); here, 0 remain besides
     * the one just deactivated.
     */
    public function test_convert_to_order_succeeds_with_null_pickup_when_deactivation_drops_active_count_to_one(): void
    {
        $location = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $other = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($location);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $this->cartWithItem($cart, $service);
        $this->assertSame($location->id, $cart->fresh()->location_id);

        // $other stays active — org drops to exactly 1 active location, not 0.
        DB::table('locations')->where('id', $location->id)->update(['is_active' => false]);

        $order = $this->cartService->convertToOrder($cart->fresh(), $this->validCheckoutData());

        $this->assertNull($order->pickup_location_id);
        $this->assertNull($order->pickup_location_name);
    }

    /**
     * @return array<string, mixed>
     */
    private function validCheckoutData(): array
    {
        return [
            'customer_type' => 'natural_person',
            'customer_email' => 'jan@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
        ];
    }
}
