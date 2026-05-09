<?php

namespace Tests\Unit\Models;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CartItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_item_can_be_created_with_required_fields(): void
    {
        $cart = Cart::factory()->create();
        $service = Service::factory()->itemRental()->create();

        $item = CartItem::create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-05',
            'rental_days' => 5,
            'unit_price' => 100.00,
            'total_price' => 1000.00,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'rental_days' => 5,
        ]);
    }

    public function test_scope_overlapping_dates_detects_overlap_when_search_period_contains_item(): void
    {
        // Item: Apr 10 – Apr 20
        // Query: Apr 08 – Apr 25 — fully contains the item period
        CartItem::factory()->create([
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-20',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-08'),
            Carbon::parse('2026-04-25')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_overlapping_dates_detects_overlap_when_item_contains_search_period(): void
    {
        // Item: Apr 05 – Apr 25
        // Query: Apr 10 – Apr 20 — item contains the search period
        CartItem::factory()->create([
            'start_date' => '2026-04-05',
            'end_date' => '2026-04-25',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_overlapping_dates_detects_overlap_when_item_starts_inside_search_period(): void
    {
        // Item: Apr 15 – Apr 30
        // Query: Apr 10 – Apr 20 — item starts inside search period
        CartItem::factory()->create([
            'start_date' => '2026-04-15',
            'end_date' => '2026-04-30',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_overlapping_dates_detects_overlap_when_item_ends_inside_search_period(): void
    {
        // Item: Apr 01 – Apr 15
        // Query: Apr 10 – Apr 20 — item ends inside search period
        CartItem::factory()->create([
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-15',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_overlapping_dates_does_not_return_period_ending_before_search_starts(): void
    {
        // Item: Apr 01 – Apr 09
        // Query: Apr 10 – Apr 20 — item ends the day before search starts
        CartItem::factory()->create([
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-09',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(0, $results);
    }

    public function test_scope_overlapping_dates_does_not_return_period_starting_after_search_ends(): void
    {
        // Item: Apr 21 – Apr 30
        // Query: Apr 10 – Apr 20 — item starts the day after search ends
        CartItem::factory()->create([
            'start_date' => '2026-04-21',
            'end_date' => '2026-04-30',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(0, $results);
    }

    public function test_scope_overlapping_dates_returns_adjacent_dates_as_overlapping(): void
    {
        // Item: Apr 20 – Apr 30
        // Query: Apr 10 – Apr 20 — shares end/start date (boundary overlap)
        CartItem::factory()->create([
            'start_date' => '2026-04-20',
            'end_date' => '2026-04-30',
        ]);

        $results = CartItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_cart_item_belongs_to_cart(): void
    {
        $cart = Cart::factory()->create();
        $item = CartItem::factory()->create(['cart_id' => $cart->id]);

        $this->assertInstanceOf(Cart::class, $item->cart);
        $this->assertEquals($cart->id, $item->cart->id);
    }

    public function test_cart_item_belongs_to_service(): void
    {
        $service = Service::factory()->itemRental()->create();
        $item = CartItem::factory()->create(['service_id' => $service->id]);

        $this->assertInstanceOf(Service::class, $item->service);
        $this->assertEquals($service->id, $item->service->id);
    }
}
