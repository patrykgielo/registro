<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderItemTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // scopeOverlappingDates — mirrors CartItem behaviour exactly
    // -------------------------------------------------------------------------

    public function test_scope_overlapping_dates_detects_overlap_when_search_period_contains_item(): void
    {
        // Item: Apr 10 – Apr 20; Query: Apr 08 – Apr 25
        OrderItem::factory()->create([
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-20',
        ]);

        $results = OrderItem::overlappingDates(
            Carbon::parse('2026-04-08'),
            Carbon::parse('2026-04-25')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_overlapping_dates_detects_overlap_when_item_contains_search_period(): void
    {
        // Item: Apr 05 – Apr 25; Query: Apr 10 – Apr 20
        OrderItem::factory()->create([
            'start_date' => '2026-04-05',
            'end_date' => '2026-04-25',
        ]);

        $results = OrderItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_overlapping_dates_does_not_return_period_ending_before_search_starts(): void
    {
        // Item: Apr 01 – Apr 09; Query: Apr 10 – Apr 20
        OrderItem::factory()->create([
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-09',
        ]);

        $results = OrderItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(0, $results);
    }

    public function test_scope_overlapping_dates_does_not_return_period_starting_after_search_ends(): void
    {
        // Item: Apr 21 – Apr 30; Query: Apr 10 – Apr 20
        OrderItem::factory()->create([
            'start_date' => '2026-04-21',
            'end_date' => '2026-04-30',
        ]);

        $results = OrderItem::overlappingDates(
            Carbon::parse('2026-04-10'),
            Carbon::parse('2026-04-20')
        )->get();

        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // scopeBlockingAvailability
    // -------------------------------------------------------------------------

    public function test_scope_blocking_availability_returns_items_from_paid_orders(): void
    {
        $order = Order::factory()->paid()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(1, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_returns_items_from_confirmed_orders(): void
    {
        $order = Order::factory()->confirmed()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(1, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_returns_items_from_in_progress_orders(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(1, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_does_not_return_items_from_cancelled_orders(): void
    {
        $order = Order::factory()->cancelled()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(0, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_does_not_return_items_from_completed_orders(): void
    {
        $order = Order::factory()->completed()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(0, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_does_not_return_items_from_expired_pending_payment_orders(): void
    {
        // An expired pending_payment order (TTL elapsed) should NOT block.
        $order = Order::factory()->expired()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(0, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_returns_items_from_pending_payment_orders_with_active_ttl(): void
    {
        // A pending_payment order with an active hold TTL DOES block availability.
        $order = Order::factory()->pendingPayment()->create(); // expires_at = now()+30min
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(1, OrderItem::blockingAvailability()->get());
    }

    public function test_scope_blocking_availability_only_returns_blocking_statuses_in_mixed_set(): void
    {
        $paid = Order::factory()->paid()->create();
        $confirmed = Order::factory()->confirmed()->create();
        $inProgress = Order::factory()->inProgress()->create();
        $cancelled = Order::factory()->cancelled()->create();
        $completed = Order::factory()->completed()->create();
        // expired() → pending_payment with expires_at in the past → NOT blocking
        $expiredPending = Order::factory()->expired()->create();
        // pendingPayment() → pending_payment with active TTL → IS blocking
        $activePending = Order::factory()->pendingPayment()->create();

        OrderItem::factory()->create(['order_id' => $paid->id]);
        OrderItem::factory()->create(['order_id' => $confirmed->id]);
        OrderItem::factory()->create(['order_id' => $inProgress->id]);
        OrderItem::factory()->create(['order_id' => $cancelled->id]);
        OrderItem::factory()->create(['order_id' => $completed->id]);
        OrderItem::factory()->create(['order_id' => $expiredPending->id]);
        OrderItem::factory()->create(['order_id' => $activePending->id]);

        $blocking = OrderItem::blockingAvailability()->get();

        $this->assertCount(4, $blocking);

        $orderIds = $blocking->pluck('order_id')->all();
        $this->assertContains($paid->id, $orderIds);
        $this->assertContains($confirmed->id, $orderIds);
        $this->assertContains($inProgress->id, $orderIds);
        $this->assertContains($activePending->id, $orderIds);
    }

    public function test_order_item_belongs_to_order(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertInstanceOf(Order::class, $item->order);
        $this->assertEquals($order->id, $item->order->id);
    }

    public function test_order_item_belongs_to_service(): void
    {
        $service = Service::factory()->itemRental()->create();
        $item = OrderItem::factory()->create(['service_id' => $service->id]);

        $this->assertInstanceOf(Service::class, $item->service);
        $this->assertEquals($service->id, $item->service->id);
    }
}
