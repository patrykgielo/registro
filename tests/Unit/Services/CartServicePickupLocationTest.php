<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\PickupLocationRequiredException;
use App\Exceptions\RentalUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
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
 * AVAILABILITY dimension (cart_items.location_id); this one is about the
 * Faza 6 PICKUP dimension (carts.location_id / orders.pickup_location_id).
 * The two are independent columns (see carts.location_id's own migration
 * docblock for why) but, since the krok 6.2 wiring fix
 * (CartService::addItem()), the PICKUP dimension now drives the AVAILABILITY
 * one whenever a cart has one — see CartService::syncItemLocationsToCart()
 * and rental-availability.md's own "punkt odbioru = lokalizacja magazynowa"
 * rule.
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

    /**
     * Same anchor-row provisioning CartServiceLocationTest's own setUp()
     * does — see the call sites' comment for why a factory-built Service
     * needs this explicitly (ServiceLocationStockObserver only backfills
     * EXISTING services when a NEW Location is created, and a NEW Service
     * only gets its primary-location row when saved through the real
     * ServiceResource form, not a bare factory create()).
     */
    private function stockAt(Service $service, Location $location, int $quantity): void
    {
        ServiceLocationStock::withoutGlobalScope('organization')->updateOrCreate(
            ['service_id' => $service->id, 'location_id' => $location->id],
            ['organization_id' => $service->organization_id, 'quantity' => $quantity, 'is_active' => true]
        );
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
        // Faza 6 krok 6.2 fix — addItem()/convertToOrder() now validate
        // against the CART's own location, not the tenant-wide pool (see
        // CartService::addItem()'s docblock). A real service, saved through
        // ServiceResource, gets this anchor row for free
        // (RouteQuantityFieldToPrimaryLocationStock); this factory-built one
        // needs it stamped explicitly, same as CartServiceLocationTest's own
        // setUp().
        $this->stockAt($service, $location, 5);
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
        // See stockAt() call in the "propagates" test above for why this is needed.
        $this->stockAt($service, $locationA, 5);
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

    // -------------------------------------------------------------------------
    // addItem()/updateQuantity() — must derive location from the CURRENT,
    // locked cart row, never a caller's stale in-memory Cart instance (Faza 6
    // krok 6.2 follow-up, 2026-09-19). Deterministic SEQUENTIAL pin of the
    // same bug tests/Concurrency/CartLocationChangeRaceTest.php proves under
    // real MySQL concurrency — no two OS processes needed here: a single
    // process holding an already-loaded Cart object across a REAL, committed
    // setLocation() call reproduces the exact staleness a controller's
    // separately-loaded $cart would have, without any timing at all.
    // -------------------------------------------------------------------------

    public function test_add_item_with_a_stale_in_memory_cart_checks_availability_against_the_carts_current_location_not_the_stale_one(): void
    {
        [$locationA, $locationB] = Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 999, // unused — capacity comes from the per-location anchor rows below
        ]);
        $this->stockAt($service, $locationA, 5);
        $this->stockAt($service, $locationB, 0);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($locationA);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);

        // Loaded BEFORE the switch below — same shape as
        // CartController::add(): getOrCreateCart() runs in its OWN,
        // already-committed transaction before this exact object is ever
        // handed to addItem(). $stale keeps reading location_id = A in PHP
        // regardless of what happens to the row afterwards.
        $stale = Cart::find($cart->id);
        $this->assertSame($locationA->id, $stale->location_id);

        // Real switch A -> B, committed (empty cart — nothing to
        // revalidate, straight through to the cart update).
        $this->cartService->setLocation($cart->fresh(), $locationB);
        $this->assertSame($locationB->id, $cart->fresh()->location_id);

        // $locationB has ZERO stock, $locationA (the stale value) has 5.
        // addItem() must check against the CART'S CURRENT location (B) and
        // reject — using the stale A would wrongly accept.
        $this->expectException(RentalUnavailableException::class);

        $this->cartService->addItem(
            $stale,
            $service,
            now()->addDay(),
            now()->addDays(3),
            1
        );
    }

    public function test_update_quantity_with_a_stale_in_memory_cart_checks_availability_against_the_carts_current_location_not_the_stale_one(): void
    {
        [$locationA, $locationB] = Location::factory()->for($this->org, 'organization')->count(2)->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 999, // unused — capacity comes from the per-location anchor rows below
        ]);
        // B has just enough stock (1) to let the item SURVIVE the switch
        // below at its original quantity, but not enough for the increase
        // this test asks for afterwards. A has plenty of both.
        $this->stockAt($service, $locationA, 5);
        $this->stockAt($service, $locationB, 1);
        $this->actingAsTenant($this->org);
        app(LocationContext::class)->set($locationA);

        $cart = $this->cartService->getOrCreateCart($this->org, $this->user);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'location_id' => $locationA->id,
            'quantity' => 1,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);

        // Loaded BEFORE the switch below — same shape as
        // CartController::updateQuantity().
        $stale = Cart::find($cart->id);
        $this->assertSame($locationA->id, $stale->location_id);

        // Real switch A -> B, committed — the item survives at its original
        // quantity (B has exactly enough) and is re-stamped to B by
        // setLocation() itself.
        $this->cartService->setLocation($cart->fresh(), $locationB);
        $this->assertSame($locationB->id, $cart->fresh()->location_id);
        $this->assertSame($locationB->id, $item->fresh()->location_id, 'setLocation() must have re-stamped the surviving item to B first.');

        // $stale (location A) is now handed to updateQuantity(), asking for
        // MORE than $locationB's stock (1) allows but well within $locationA's
        // (5). If the check ran against the stale A, this would wrongly
        // succeed — and (via syncItemLocationsToCart()) drag the item's
        // location_id back to A even though carts.location_id already
        // committed to B. The CURRENT B must reject it instead.
        try {
            $this->cartService->updateQuantity($stale, $item, 2);
            $this->fail('updateQuantity() must reject a quantity the CURRENT location cannot cover, even from a stale $cart pointing at a location that could cover it.');
        } catch (RentalUnavailableException) {
            // expected
        }

        // The rejection must not have corrupted anything: the item stays
        // exactly where setLocation() left it.
        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame($locationB->id, $item->fresh()->location_id);
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
