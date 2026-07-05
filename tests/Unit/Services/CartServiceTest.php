<?php

namespace Tests\Unit\Services;

use App\Exceptions\CartNotActiveException;
use App\Exceptions\RentalUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\RentalAvailabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();
    }

    private function makeService(): CartService
    {
        return app(CartService::class);
    }

    // -------------------------------------------------------------------------
    // getOrCreateCart
    // -------------------------------------------------------------------------

    public function test_get_or_create_cart_creates_new_cart_when_none_exists(): void
    {
        $service = $this->makeService();

        $cart = $service->getOrCreateCart($this->org, $this->user);

        $this->assertInstanceOf(Cart::class, $cart);
        $this->assertDatabaseHas('carts', [
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    public function test_get_or_create_cart_returns_existing_active_cart(): void
    {
        $existing = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $service = $this->makeService();

        $cart = $service->getOrCreateCart($this->org, $this->user);

        $this->assertEquals($existing->id, $cart->id);
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_get_or_create_cart_creates_new_when_existing_cart_is_abandoned(): void
    {
        Cart::factory()->abandoned()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $service = $this->makeService();

        $cart = $service->getOrCreateCart($this->org, $this->user);

        // The abandoned cart + a new active one
        $this->assertDatabaseCount('carts', 2);
        $this->assertEquals('active', $cart->status);
    }

    // -------------------------------------------------------------------------
    // getOrCreateCart — duplicate-cart prevention (point 3)
    // -------------------------------------------------------------------------

    public function test_duplicate_active_cart_for_same_org_and_user_is_blocked_by_db_constraint(): void
    {
        Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        // Bypassing CartService entirely — this proves the DB-level unique
        // constraint `carts_org_user_active_unique` (organization_id, user_id,
        // active_slot) is the real backstop, not just app-level locking.
        $this->expectException(QueryException::class);

        Cart::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    public function test_get_or_create_cart_does_not_create_duplicate_on_repeated_calls(): void
    {
        $service = $this->makeService();

        $first = $service->getOrCreateCart($this->org, $this->user);
        $second = $service->getOrCreateCart($this->org, $this->user);

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_get_or_create_cart_eager_loads_items_and_service_without_n_plus_one(): void
    {
        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        CartItem::factory()->count(3)->create([
            'cart_id' => $cart->id,
            'service_id' => fn () => Service::factory()->itemRental()->create([
                'organization_id' => $this->org->id,
            ])->id,
        ]);

        $service = $this->makeService();

        DB::enableQueryLog();
        $result = $service->getOrCreateCart($this->org, $this->user);
        $queriesAfterFetch = count(DB::getQueryLog());

        foreach ($result->items as $item) {
            // Accessing ->service on every item must not trigger additional
            // queries — proves items.service is eager-loaded (point 4).
            $item->service->name;
        }
        $queriesAfterIteration = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queriesAfterFetch,
            $queriesAfterIteration,
            'Iterating cart items and accessing ->service triggered extra queries (N+1 regression).'
        );
    }

    // -------------------------------------------------------------------------
    // addItem
    // -------------------------------------------------------------------------

    public function test_add_item_throws_when_quantity_exceeds_available(): void
    {
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        // Mock availability returning 2 so requesting 3 exceeds it
        $availabilityMock = $this->createMock(RentalAvailabilityService::class);
        $availabilityMock->method('getAvailableQuantity')->willReturn(2);

        $service = new CartService($availabilityMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Dostępnych tylko 2 szt.');

        $service->addItem(
            $cart,
            $rentalService,
            Carbon::tomorrow(),
            Carbon::tomorrow()->addDays(2),
            3
        );
    }

    public function test_add_item_creates_cart_item_with_correct_fields(): void
    {
        // Use the real RentalAvailabilityService so we don't fight PHP 8.3 strict
        // float-vs-int type coercion between Carbon::diffInDays() and the mock.
        // Service has plenty of stock and no existing rentals, so availability is real.
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
            'price_per_day' => 100,
        ]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $start = Carbon::parse('2026-04-10');
        $end = Carbon::parse('2026-04-12');

        $service = $this->makeService();

        $item = $service->addItem($cart, $rentalService, $start, $end, 1);

        $this->assertInstanceOf(CartItem::class, $item);
        $this->assertEquals($cart->id, $item->cart_id);
        $this->assertEquals($rentalService->id, $item->service_id);
        $this->assertEquals(1, $item->quantity);
        $this->assertEquals('2026-04-10', $item->start_date->toDateString());
        $this->assertEquals('2026-04-12', $item->end_date->toDateString());
        // diffInDays(Apr10→Apr12) = 2, +1 = 3 days
        $this->assertEquals(3, $item->rental_days);
        // price_per_day=100, 3 days × 1 qty = 300
        $this->assertEquals(100.0, (float) $item->unit_price);
        $this->assertEquals(300.0, (float) $item->total_price);
    }

    // -------------------------------------------------------------------------
    // removeItem
    // -------------------------------------------------------------------------

    public function test_remove_item_deletes_item_from_cart(): void
    {
        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $item = CartItem::factory()->create(['cart_id' => $cart->id]);

        $service = $this->makeService();
        $service->removeItem($cart, $item);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_remove_item_throws_when_item_belongs_to_different_cart(): void
    {
        $cartA = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $cartB = Cart::factory()->active()->create();

        // Item belongs to cartB, not cartA
        $item = CartItem::factory()->create(['cart_id' => $cartB->id]);

        $service = $this->makeService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Item nie należy do tego koszyka');

        $service->removeItem($cartA, $item);
    }

    // -------------------------------------------------------------------------
    // updateQuantity
    // -------------------------------------------------------------------------

    public function test_update_quantity_recalculates_total_price(): void
    {
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
            'price_per_day' => 100,
        ]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $rentalService->id,
            'quantity' => 1,
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $newPricing = ['unit' => 'daily', 'unit_price' => 100.0, 'total' => 600.0];

        $availabilityMock = $this->createMock(RentalAvailabilityService::class);
        $availabilityMock->method('getAvailableQuantity')->willReturn(5);
        $availabilityMock->method('calculatePricing')->willReturn($newPricing);

        $service = new CartService($availabilityMock);

        $updated = $service->updateQuantity($cart, $item, 2);

        $this->assertEquals(2, $updated->quantity);
        $this->assertEquals(600.0, (float) $updated->total_price);
    }

    public function test_update_quantity_throws_when_new_quantity_exceeds_available(): void
    {
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $rentalService->id,
            'quantity' => 1,
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'rental_days' => 3,
        ]);

        $availabilityMock = $this->createMock(RentalAvailabilityService::class);
        $availabilityMock->method('getAvailableQuantity')->willReturn(2);

        $service = new CartService($availabilityMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Dostępnych tylko 2 szt.');

        $service->updateQuantity($cart, $item, 5);
    }

    // -------------------------------------------------------------------------
    // convertToOrder
    // -------------------------------------------------------------------------

    public function test_convert_to_order_creates_order_and_order_items(): void
    {
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
            'price_per_day' => 100,
        ]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $rentalService->id,
            'quantity' => 2,
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 600.00,
        ]);

        $service = $this->makeService();

        $order = $service->convertToOrder($cart, [
            'customer_email' => 'test@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
        ]);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals('pending_payment', $order->status);
        $this->assertEquals('test@example.com', $order->customer_email);
        $this->assertEquals(600.0, (float) $order->total_amount);

        // Cart must be marked converted
        $cart->refresh();
        $this->assertEquals('converted', $cart->status);

        // OrderItem must be created
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'service_id' => $rentalService->id,
            'quantity' => 2,
        ]);
    }

    public function test_convert_to_order_generates_correct_order_number(): void
    {
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
        ]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $rentalService->id,
            'quantity' => 1,
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-12',
            'rental_days' => 3,
            'unit_price' => 50.00,
            'total_price' => 150.00,
        ]);

        $service = $this->makeService();
        $order = $service->convertToOrder($cart, [
            'customer_email' => 'x@example.com',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Testowy',
        ]);

        // Format: ORG{id}-{year}-{padded_seq}
        $this->assertMatchesRegularExpression(
            '/^ORG\d+-\d{4}-\d{5}$/',
            $order->order_number
        );
    }

    public function test_convert_to_order_throws_when_cart_is_not_active(): void
    {
        $cart = Cart::factory()->converted()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $service = $this->makeService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Koszyk nie jest aktywny');

        $service->convertToOrder($cart, ['customer_email' => 'x@example.com']);
    }

    public function test_convert_to_order_is_atomic_and_rolls_back_on_failure(): void
    {
        // Use an abandoned cart — convertToOrder will throw "Koszyk nie jest aktywny"
        // before any INSERT happens, so orders table stays empty.
        $cart = Cart::factory()->abandoned()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $service = $this->makeService();

        $this->expectException(\Exception::class);

        try {
            $service->convertToOrder($cart, [
                'customer_email' => 'x@example.com',
                'customer_first_name' => 'Jan',
                'customer_last_name' => 'Testowy',
            ]);
        } catch (\Exception $e) {
            // No orders should have been persisted
            $this->assertDatabaseCount('orders', 0);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // reactivate()
    // -------------------------------------------------------------------------

    public function test_reactivate_flips_converted_cart_back_to_active(): void
    {
        $cart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'converted',
            'expires_at' => now()->subMinutes(5),
        ]);

        $service = $this->makeService();
        $service->reactivate($cart);

        $cart->refresh();
        $this->assertSame('active', $cart->status);
        $this->assertTrue($cart->expires_at->isFuture());
    }

    public function test_reactivate_does_not_create_second_active_cart_when_one_already_exists(): void
    {
        // Simulates the two-tab race: while this (converted) cart's checkout
        // was mid-compensation, the user opened a second tab and started a
        // fresh active cart for the same user/org.
        $otherActiveCart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $convertedCart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'converted',
        ]);

        $service = $this->makeService();
        $service->reactivate($convertedCart);

        $convertedCart->refresh();
        $otherActiveCart->refresh();

        // The converted cart is left untouched — NOT flipped back to active.
        $this->assertSame('converted', $convertedCart->status);
        // Only one active cart exists for this user/org.
        $this->assertSame('active', $otherActiveCart->status);
        $this->assertSame(
            1,
            Cart::query()->active()
                ->where('organization_id', $this->org->id)
                ->where('user_id', $this->user->id)
                ->count()
        );
    }

    public function test_reactivate_ignores_active_carts_belonging_to_other_users(): void
    {
        $otherUser = User::factory()->create();
        Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $otherUser->id,
        ]);

        $cart = Cart::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'converted',
        ]);

        $service = $this->makeService();
        $service->reactivate($cart);

        $cart->refresh();
        $this->assertSame('active', $cart->status);
    }

    // -------------------------------------------------------------------------
    // convertToOrder — empty cart guard (point 2)
    // -------------------------------------------------------------------------

    public function test_convert_to_order_throws_when_cart_has_no_items(): void
    {
        $cart = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $service = $this->makeService();

        // NOTE: assertDatabaseCount() must live INSIDE the catch — expectException()
        // intercepts the thrown exception before any code after the triggering call
        // ever runs, so an assertion placed after it (outside try/catch) is dead code
        // that always silently "passes". Same pattern as
        // test_convert_to_order_is_atomic_and_rolls_back_on_failure() above.
        $this->expectException(CartNotActiveException::class);
        $this->expectExceptionMessage('Koszyk jest pusty.');

        try {
            $service->convertToOrder($cart, ['customer_email' => 'x@example.com']);
        } catch (CartNotActiveException $e) {
            $this->assertDatabaseCount('orders', 0);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // convertToOrder — overselling regression (point 1, CRITICAL)
    // -------------------------------------------------------------------------

    public function test_convert_to_order_prevents_overselling_across_two_separate_carts(): void
    {
        $rentalService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 1,
            'price_per_day' => 100,
        ]);

        $userB = User::factory()->create();

        $cartA = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);
        $cartB = Cart::factory()->active()->create([
            'organization_id' => $this->org->id,
            'user_id' => $userB->id,
        ]);

        foreach ([$cartA, $cartB] as $cart) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'service_id' => $rentalService->id,
                'quantity' => 1,
                'start_date' => '2026-04-10',
                'end_date' => '2026-04-12',
                'rental_days' => 3,
                'unit_price' => 100.00,
                'total_price' => 300.00,
            ]);
        }

        $service = $this->makeService();

        // First checkout claims the only unit in stock.
        $orderA = $service->convertToOrder($cartA, [
            'customer_email' => 'a@example.com',
            'customer_first_name' => 'Anna',
            'customer_last_name' => 'A',
        ]);
        $this->assertInstanceOf(Order::class, $orderA);

        // Second checkout for the same item/dates must fail cleanly — no
        // Order/OrderItem created, and cartB is left untouched (still active).
        try {
            $service->convertToOrder($cartB, [
                'customer_email' => 'b@example.com',
                'customer_first_name' => 'Bogdan',
                'customer_last_name' => 'B',
            ]);
            $this->fail('Expected RentalUnavailableException was not thrown.');
        } catch (RentalUnavailableException $e) {
            $this->assertDatabaseMissing('orders', ['user_id' => $userB->id]);
            $this->assertDatabaseCount('orders', 1);
            $this->assertSame(0, OrderItem::whereHas('order', fn ($q) => $q->where('user_id', $userB->id))->count());

            $cartB->refresh();
            $this->assertEquals('active', $cartB->status);
        }
    }
}
