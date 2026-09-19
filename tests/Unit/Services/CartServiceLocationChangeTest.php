<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faza 6 krok 6.2 (86cbahqgv, plan-wdrozenia.md) —
 * CartService::previewLocationChange()/setLocation(), the "pytanie, nie
 * błąd" revalidation described in that method's own docblocks. Deliberately
 * separate from CartServiceLocationTest.php (Faza 4's addItem/updateQuantity/
 * convertToOrder aggregation) and CartServicePickupLocationTest.php (Faza
 * 6.1/6.3/6.4's carts.location_id stamping/propagation) — this file is about
 * the third write path: changing an EXISTING cart's pickup location.
 *
 * Every "clamp"/"remove" assertion here is falsifiable the same way as
 * CartServiceLocationTest's own pairs: reverting `evaluateLocationChange()`'s
 * `min($item->quantity, max(0, $available - $siblingDemand))` to always
 * return `$item->quantity` (i.e. never clamping) flips every "kept <
 * requested" assertion below to fail, and reverting the sibling-demand
 * subtraction entirely flips the two-items-same-service tests specifically.
 */
class CartServiceLocationChangeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Location $oldLocation;

    private Location $newLocation;

    private Service $service;

    private CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();

        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 999, // deliberately absurd — proves the location branch, not this column, is what's read (same precedent as CartServiceLocationTest)
            'price_per_day' => 100,
        ]);

        // Created AFTER the service so ServiceLocationStockObserver
        // auto-materializes the anchor rows.
        $this->oldLocation = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);
        $this->newLocation = Location::factory()->for($this->org, 'organization')->create(['is_active' => true]);

        $this->cartService = app(CartService::class);
    }

    private function cartWithItem(int $quantity, ?int $locationId = null): Cart
    {
        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'location_id' => $this->oldLocation->id,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00 * $quantity,
        ]);

        return $cart;
    }

    private function setStock(Location $location, int $quantity): void
    {
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $location->id)
            ->update(['quantity' => $quantity]);
    }

    // -------------------------------------------------------------------------
    // Decision 1 — trimming quantities down, never a hard rejection
    // -------------------------------------------------------------------------

    public function test_preview_reports_full_quantity_kept_when_new_location_has_enough_stock(): void
    {
        $this->setStock($this->newLocation, 5);
        $cart = $this->cartWithItem(quantity: 2);

        $preview = $this->cartService->previewLocationChange($cart, $this->newLocation);

        $this->assertCount(1, $preview);
        $this->assertSame(2, $preview[0]['requested']);
        $this->assertSame(5, $preview[0]['available']);
        $this->assertSame(2, $preview[0]['kept']);
    }

    public function test_preview_clamps_quantity_down_to_what_the_new_location_actually_has(): void
    {
        $this->setStock($this->newLocation, 1);
        $cart = $this->cartWithItem(quantity: 3);

        $preview = $this->cartService->previewLocationChange($cart, $this->newLocation);

        $this->assertSame(3, $preview[0]['requested']);
        $this->assertSame(1, $preview[0]['available']);
        $this->assertSame(1, $preview[0]['kept']);
    }

    public function test_preview_reports_zero_kept_when_new_location_has_no_stock_at_all(): void
    {
        $this->setStock($this->newLocation, 0);
        $cart = $this->cartWithItem(quantity: 2);

        $preview = $this->cartService->previewLocationChange($cart, $this->newLocation);

        $this->assertSame(0, $preview[0]['kept']);
    }

    public function test_preview_never_throws_for_an_unavailable_item_it_only_reports_it(): void
    {
        $this->setStock($this->newLocation, 0);
        $cart = $this->cartWithItem(quantity: 2);

        // The whole point of "pytanie, nie błąd" — no exception class exists
        // for "item doesn't fit at the new branch" on the preview path.
        $preview = $this->cartService->previewLocationChange($cart, $this->newLocation);

        $this->assertSame(0, $preview[0]['kept']);
    }

    public function test_set_location_reduces_quantity_and_reports_the_reduction(): void
    {
        $this->setStock($this->newLocation, 1);
        $cart = $this->cartWithItem(quantity: 3);
        $item = $cart->items()->first();

        $report = $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertCount(1, $report['reduced']);
        $this->assertSame([], $report['removed']);
        $this->assertSame(3, $report['reduced'][0]['from']);
        $this->assertSame(1, $report['reduced'][0]['to']);
        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_set_location_removes_the_item_entirely_when_nothing_is_available(): void
    {
        $this->setStock($this->newLocation, 0);
        $cart = $this->cartWithItem(quantity: 2);
        $item = $cart->items()->first();

        $report = $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertSame([], $report['reduced']);
        $this->assertCount(1, $report['removed']);
        $this->assertSame($item->id, $report['removed'][0]->id);
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_set_location_leaves_a_fully_available_item_completely_untouched(): void
    {
        $this->setStock($this->newLocation, 5);
        $cart = $this->cartWithItem(quantity: 2);
        $item = $cart->items()->first();

        $report = $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertSame([], $report['reduced']);
        $this->assertSame([], $report['removed']);
        $this->assertSame(2, $item->fresh()->quantity);
    }

    // -------------------------------------------------------------------------
    // Decision 3 — carts.location_id (pickup) vs cart_items.location_id
    // (availability) stay independent
    // -------------------------------------------------------------------------

    public function test_set_location_updates_the_carts_own_pickup_location(): void
    {
        $this->setStock($this->newLocation, 5);
        $cart = $this->cartWithItem(quantity: 1);

        $this->assertSame($this->oldLocation->id, $cart->fresh()->location_id);

        $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertSame($this->newLocation->id, $cart->fresh()->location_id);
    }

    /**
     * Pins the wiring fix that closed the exact gap this test used to
     * expose: addItem() derives its own $locationId from $cart->location_id
     * (see CartService::addItem()'s own docblock), so an EXISTING item left
     * pointing at the OLD branch after a switch would validate/sell against
     * the wrong pool — the same pickup-location-vs-stock-location mismatch
     * rental-availability.md names as the invariant to close. setLocation()
     * must therefore re-stamp every surviving item to the NEW location,
     * whether or not its quantity/pricing changed.
     */
    public function test_set_location_stamps_every_surviving_item_with_the_new_location_id(): void
    {
        $this->setStock($this->newLocation, 5);
        // A legacy row (location_id === null, as every real CartItem was
        // before the wiring fix) AND a stale row (location_id still pointing
        // at the OLD branch) must both end up on the NEW branch.
        $cart = $this->cartWithItem(quantity: 1, locationId: null);
        $legacyItem = $cart->items()->first();

        $staleItem = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => $this->oldLocation->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDays(20)->toDateString(),
            'end_date' => Carbon::today()->addDays(22)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);

        $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertSame($this->newLocation->id, $legacyItem->fresh()->location_id);
        $this->assertSame($this->newLocation->id, $staleItem->fresh()->location_id);
    }

    // -------------------------------------------------------------------------
    // Sibling aggregation — mirrors CartServiceLocationTest's own "both
    // directions" precedent, one write path over
    // -------------------------------------------------------------------------

    public function test_set_location_aggregates_overlapping_siblings_of_the_same_service_against_the_new_locations_own_stock(): void
    {
        $this->setStock($this->newLocation, 1);
        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'location_id' => $this->oldLocation->id,
        ]);

        // Two 1-unit items, same service, overlapping dates — only ONE can
        // be kept against a new-location stock of 1.
        $itemA = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);
        $itemB = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);

        $report = $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertCount(1, $report['removed']);
        $this->assertSame([], $report['reduced']);

        // Deterministic ordering (orderBy('service_id')->orderBy('id')) —
        // the earlier-id item (itemA) wins, itemB is the one dropped.
        $this->assertNotNull($itemA->fresh());
        $this->assertNull($itemB->fresh());
    }

    public function test_set_location_does_not_falsely_reject_non_overlapping_siblings_of_the_same_service(): void
    {
        $this->setStock($this->newLocation, 1);
        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'location_id' => $this->oldLocation->id,
        ]);

        $itemA = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);
        // Non-overlapping window, same service, same 1-unit stock — both
        // must be kept; naive "sum ALL same-service items" would wrongly
        // reject this one too.
        $itemB = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDays(30)->toDateString(),
            'end_date' => Carbon::today()->addDays(32)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);

        $report = $this->cartService->setLocation($cart, $this->newLocation);

        $this->assertSame([], $report['reduced']);
        $this->assertSame([], $report['removed']);
        $this->assertSame(1, $itemA->fresh()->quantity);
        $this->assertSame(1, $itemB->fresh()->quantity);
    }

    // -------------------------------------------------------------------------
    // Guard: same two constraints LocationSelectionController itself
    // validates before ever reaching this method
    // -------------------------------------------------------------------------

    public function test_set_location_rejects_a_location_belonging_to_another_tenant(): void
    {
        $foreignOrg = Organization::factory()->create();
        $foreignLocation = Location::factory()->for($foreignOrg, 'organization')->create(['is_active' => true]);
        $cart = $this->cartWithItem(quantity: 1);

        $this->expectException(\InvalidArgumentException::class);

        $this->cartService->setLocation($cart, $foreignLocation);
    }

    public function test_set_location_rejects_an_inactive_location(): void
    {
        $inactive = Location::factory()->for($this->org, 'organization')->create(['is_active' => false]);
        $cart = $this->cartWithItem(quantity: 1);

        $this->expectException(\InvalidArgumentException::class);

        $this->cartService->setLocation($cart, $inactive);
    }

    // -------------------------------------------------------------------------
    // End to end: what setLocation() decides survives all the way to the
    // Order (closes the "sedno kroku" rozjazd the team lead's brief names —
    // proven together with CartServicePickupLocationTest's own
    // convertToOrder() coverage, not duplicating it)
    // -------------------------------------------------------------------------

    public function test_after_confirming_a_location_change_checkout_creates_the_order_at_the_new_location(): void
    {
        $this->setStock($this->newLocation, 5);
        $cart = $this->cartWithItem(quantity: 1);

        $this->cartService->setLocation($cart, $this->newLocation);

        $order = $this->cartService->convertToOrder($cart->fresh(), [
            'customer_type' => 'natural_person',
            'customer_email' => 'jan@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
        ]);

        $this->assertSame($this->newLocation->id, $order->pickup_location_id);
        $this->assertSame($this->newLocation->name, $order->pickup_location_name);
    }

    public function test_after_confirming_a_location_change_the_trimmed_quantity_is_what_gets_ordered(): void
    {
        $this->setStock($this->newLocation, 1);
        $cart = $this->cartWithItem(quantity: 3);

        $this->cartService->setLocation($cart, $this->newLocation);

        $order = $this->cartService->convertToOrder($cart->fresh(), [
            'customer_type' => 'natural_person',
            'customer_email' => 'jan@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
        ]);

        // One CartItem of quantity=1 expands into exactly one OrderItem
        // (Faza 3 krok 2's own "quantity N → N OrderItems" contract) — proves
        // the clamp from 3 → 1 survived into the actual reservation, not
        // just the preview.
        $this->assertSame(1, $order->items()->count());
    }
}
