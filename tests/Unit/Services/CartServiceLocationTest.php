<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\RentalUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faza 4 krok 4.4 (plan-wdrozenia.md, kontrakt-dostepnosci.md) — the three
 * CartService write paths (addItem/updateQuantity/convertToOrder) now pass
 * $locationId. Deliberately a separate file from CartServiceTest.php, same
 * precedent as RentalAvailabilityServiceLocationTest vs
 * RentalAvailabilityServiceTest — the existing suite's ~30 tests must keep
 * pinning today's location-less ($locationId === null) behaviour unchanged.
 *
 * The central risk this file exists to catch (team lead's own framing): the
 * sibling-demand aggregation added in Faza 0 krok 0.4 (kontrakt-dostepnosci.md
 * Zasada 7) must move from "per service" to "per (service, location)" —
 * getting this wrong in EITHER direction is a real bug:
 *   - summing ACROSS locations → false reject (two non-competing locations
 *     serialise against each other)
 *   - NOT summing WITHIN one location → oversell (the original 86cb93tfw bug,
 *     reintroduced one dimension over)
 * Every "both directions" pair below is written so reverting the location
 * scoping (mechanically: dropping the `location_id` qualifier from the
 * relevant WHERE) flips its outcome — not just adding a new, independently
 * passing assertion.
 */
class CartServiceLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    private Service $service;

    private CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();

        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 999, // deliberately absurd, same precedent as RentalAvailabilityServiceLocationTest — proves the location branch never reads this
            'price_per_day' => 100,
        ]);

        // Created AFTER the service so ServiceLocationStockObserver
        // auto-materializes the anchor rows.
        $this->locationA = Location::factory()->for($this->org, 'organization')->create();
        $this->locationB = Location::factory()->for($this->org, 'organization')->create();

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationB->id)
            ->update(['quantity' => 1]);

        $this->cartService = app(CartService::class);
    }

    private function start(): Carbon
    {
        return Carbon::parse('2026-05-01');
    }

    private function end(): Carbon
    {
        return Carbon::parse('2026-05-05');
    }

    private function activeCart(?User $user = null): Cart
    {
        return Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => ($user ?? $this->user)->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // addItem() — forwards $locationId, persists it, scopes both capacity
    // AND sibling demand to it
    // -------------------------------------------------------------------------

    public function test_add_item_forwards_location_id_and_persists_it_on_the_created_row(): void
    {
        $cart = $this->activeCart();

        $item = $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);

        $this->assertSame($this->locationA->id, $item->location_id);
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'location_id' => $this->locationA->id,
        ]);
    }

    public function test_add_item_checks_capacity_at_the_given_location_not_the_absurd_quantity_total(): void
    {
        $cart = $this->activeCart();

        // Location A only has 1 unit — quantity_total is 999.
        $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);

        $this->expectException(RentalUnavailableException::class);
        $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);
    }

    public function test_add_item_without_a_location_id_behaves_exactly_like_before_this_change(): void
    {
        // A plain, non-location service — quantity_total is the real capacity here.
        $plain = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 1,
            'price_per_day' => 50,
        ]);

        $cart = $this->activeCart();

        $item = $this->cartService->addItem($cart, $plain, $this->start(), $this->end(), 1);

        $this->assertNull($item->location_id);

        $this->expectException(RentalUnavailableException::class);
        $this->cartService->addItem($cart, $plain, $this->start(), $this->end(), 1);
    }

    /**
     * Both directions, addItem(): a sibling in a DIFFERENT location must not
     * count against this add (would be a false reject) — the mirror
     * "same location DOES count" case is
     * test_add_item_checks_capacity_at_the_given_location_not_the_absurd_quantity_total()
     * above (two 1-unit adds to the SAME location, second one throws).
     */
    public function test_add_item_does_not_aggregate_sibling_demand_across_different_locations(): void
    {
        $cart = $this->activeCart();

        $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);

        // Location B is a SEPARATE 1-unit pool — must succeed even though
        // the cart already holds a same-service, same-dates item in A.
        $itemB = $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationB->id);

        $this->assertSame($this->locationB->id, $itemB->location_id);
        $this->assertSame(2, CartItem::where('cart_id', $cart->id)->count());
    }

    // -------------------------------------------------------------------------
    // updateQuantity() — reads $item->location_id off the row, scopes both
    // capacity and sibling demand to it
    // -------------------------------------------------------------------------

    public function test_update_quantity_uses_the_items_own_location_for_the_availability_check(): void
    {
        $cart = $this->activeCart();
        $item = $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);

        // Location A only has 1 unit — increasing to 2 must fail.
        $this->expectException(RentalUnavailableException::class);
        $this->cartService->updateQuantity($cart, $item, 2);
    }

    public function test_update_quantity_sibling_demand_ignores_items_in_a_different_location(): void
    {
        $cart = $this->activeCart();
        // Occupies the only unit in location B — must not affect an update on the item in A.
        $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationB->id);
        $itemA = $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);

        // Location A's own 1-unit capacity is fully consumed by $itemA already,
        // so re-asserting the SAME quantity (1, not an increase) must still
        // succeed — it must not be blocked by the sibling sitting in B.
        $updated = $this->cartService->updateQuantity($cart, $itemA, 1);

        $this->assertSame(1, $updated->quantity);
    }

    public function test_update_quantity_sibling_demand_includes_items_in_the_same_location(): void
    {
        $cart = $this->activeCart();
        $itemA1 = $this->cartService->addItem($cart, $this->service, $this->start(), $this->end(), 1, $this->locationA->id);

        // A second, NON-overlapping item in A so it doesn't collide with $itemA1 on creation...
        $laterStart = $this->end()->copy()->addDays(10);
        $laterEnd = $laterStart->copy()->addDays(2);
        $itemA2 = $this->cartService->addItem($cart, $this->service, $laterStart, $laterEnd, 1, $this->locationA->id);

        // ...then widen $itemA2's dates via direct update to overlap $itemA1's window,
        // to exercise the sibling-demand overlap check itself rather than addItem()'s.
        $itemA2->forceFill(['start_date' => $this->start()->toDateString(), 'end_date' => $this->end()->toDateString()])->save();

        // Now both items in location A claim the window — location A has only 1
        // unit, itemA1 already holds it, so updateQuantity() on $itemA2 (still
        // quantity 1, unchanged) must be rejected: the sibling in the SAME
        // location must count against it.
        $this->expectException(RentalUnavailableException::class);
        $this->cartService->updateQuantity($cart, $itemA2, 1);
    }

    // -------------------------------------------------------------------------
    // convertToOrder() — per (service, location) aggregation, both directions
    // (the pitfall the team lead's assignment named explicitly)
    // -------------------------------------------------------------------------

    public function test_convert_to_order_does_not_falsely_reject_the_same_service_in_two_different_locations(): void
    {
        $cart = $this->activeCart();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'rental_days' => 5,
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationB->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'rental_days' => 5,
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        $order = $this->cartService->convertToOrder($cart, [
            'customer_email' => 'x@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Testowy',
        ]);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'location_id' => $this->locationA->id]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'location_id' => $this->locationB->id]);
    }

    public function test_convert_to_order_still_prevents_oversell_when_both_items_are_in_the_same_location(): void
    {
        $cart = $this->activeCart();

        foreach ([1, 2] as $n) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'service_id' => $this->service->id,
                'location_id' => $this->locationA->id,
                'quantity' => 1,
                'start_date' => $this->start(),
                'end_date' => $this->end(),
                'rental_days' => 5,
                'unit_price' => 100,
                'total_price' => 500,
            ]);
        }

        $this->expectException(RentalUnavailableException::class);

        try {
            $this->cartService->convertToOrder($cart, [
                'customer_email' => 'x@example.com',
                'customer_first_name' => 'Jan',
                'customer_last_name' => 'Testowy',
            ]);
        } catch (RentalUnavailableException $e) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
            throw $e;
        }
    }

    public function test_convert_to_order_expansion_carries_location_id_to_every_split_order_item(): void
    {
        // Location A has 2 free units for this test only.
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 2]);

        $cart = $this->activeCart();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'rental_days' => 5,
            'unit_price' => 100,
            'total_price' => 1000,
        ]);

        $order = $this->cartService->convertToOrder($cart, [
            'customer_email' => 'x@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Testowy',
        ]);

        $items = OrderItem::where('order_id', $order->id)->get();
        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn (OrderItem $i) => $i->location_id === $this->locationA->id));
    }
}
