<?php

namespace Tests\Unit\Services;

use App\Enums\RentalStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Safety-net tests for the Sprint 2 dual-source availability logic.
 *
 * getAvailableQuantity() must account for BOTH legacy Rental rows (Sprint 1
 * flow) AND OrderItem rows (Sprint 2 Cart→Order flow) when computing stock.
 */
class RentalAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $item;

    private RentalAvailabilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->item = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 10,
            'price_per_day' => 50,
        ]);

        $this->svc = app(RentalAvailabilityService::class);
    }

    private function start(): Carbon
    {
        return Carbon::parse('2026-05-01');
    }

    private function end(): Carbon
    {
        return Carbon::parse('2026-05-05');
    }

    // -------------------------------------------------------------------------
    // Baseline
    // -------------------------------------------------------------------------

    public function test_returns_full_quantity_when_no_rentals_and_no_orders(): void
    {
        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    // -------------------------------------------------------------------------
    // Legacy Rental flow deductions
    // -------------------------------------------------------------------------

    public function test_deducts_confirmed_rental_from_available_quantity(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 3,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(7, $available);
    }

    public function test_does_not_deduct_cancelled_rental(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 3,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Cancelled,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    // -------------------------------------------------------------------------
    // Sprint 2: OrderItem flow deductions — the critical new logic
    // -------------------------------------------------------------------------

    public function test_deducts_paid_order_items_from_available_quantity(): void
    {
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 4,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(6, $available);
    }

    public function test_deducts_both_rental_and_order_item_from_available_quantity(): void
    {
        // 3 units consumed via legacy Rental flow
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 3,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        // 4 units consumed via new Cart→Order flow (paid order)
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 4,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        // 10 - 3 - 4 = 3
        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(3, $available);
    }

    public function test_does_not_deduct_cancelled_order_items(): void
    {
        $order = Order::factory()->cancelled()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 5,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    public function test_does_not_deduct_expired_pending_payment_order_items(): void
    {
        // An expired pending_payment order has elapsed TTL — stock should be free
        $order = Order::factory()->expired()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 5,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    public function test_deducts_active_pending_payment_order_items(): void
    {
        // A pending_payment order with a live TTL DOES block inventory (optimistic hold)
        $order = Order::factory()->pendingPayment()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(8, $available);
    }

    public function test_does_not_deduct_completed_order_items(): void
    {
        // completed = rental already happened and returned — should not block future bookings
        $order = Order::factory()->completed()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 5,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    // -------------------------------------------------------------------------
    // Non-overlapping dates — must not affect availability
    // -------------------------------------------------------------------------

    public function test_non_overlapping_order_item_does_not_reduce_availability(): void
    {
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        // Item dates entirely in the future — no overlap with our query window
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 5,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-10'),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    // -------------------------------------------------------------------------
    // Cannot go below zero
    // -------------------------------------------------------------------------

    public function test_available_quantity_never_goes_below_zero(): void
    {
        // Over-book by putting more reservations than stock (data integrity edge case)
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 15, // more than quantity_total=10
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(0, $available);
    }

    // -------------------------------------------------------------------------
    // getMonthlyAvailability() — bulk calendar query, must mirror
    // getAvailableQuantity()'s blockingAvailability() logic exactly (Bug fix
    // 2026-07-07: hand-rolled whereHas() was missing the P24 grace period).
    // -------------------------------------------------------------------------

    public function test_monthly_availability_day_with_no_reservations_is_fully_available(): void
    {
        $result = $this->svc->getMonthlyAvailability($this->item, 2026, 5);

        $this->assertSame(10, $result['2026-05-15']['available_quantity']);
        $this->assertSame('available', $result['2026-05-15']['status']);
    }

    public function test_monthly_availability_day_blocked_by_paid_order(): void
    {
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 4,
            'start_date' => Carbon::parse('2026-05-10'),
            'end_date' => Carbon::parse('2026-05-12'),
        ]);

        $result = $this->svc->getMonthlyAvailability($this->item, 2026, 5);

        $this->assertSame(6, $result['2026-05-11']['available_quantity']);
        $this->assertSame('partial', $result['2026-05-11']['status']);
        // Outside the reserved range — untouched
        $this->assertSame(10, $result['2026-05-20']['available_quantity']);
    }

    public function test_monthly_availability_day_blocked_by_pending_payment_order_within_p24_grace(): void
    {
        // expires_at already elapsed, but p24_token is set → still within
        // Order::ttlGraceMinutes() → must still block (this is the fix for Bug 1).
        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'pending_payment',
            'p24_token' => 'test-p24-token-123',
            'expires_at' => now()->subMinutes(5),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 3,
            'start_date' => Carbon::parse('2026-05-10'),
            'end_date' => Carbon::parse('2026-05-12'),
        ]);

        $result = $this->svc->getMonthlyAvailability($this->item, 2026, 5);

        $this->assertSame(7, $result['2026-05-11']['available_quantity']);
        $this->assertSame('partial', $result['2026-05-11']['status']);
    }

    public function test_monthly_availability_day_not_blocked_by_genuinely_expired_pending_order(): void
    {
        // p24_token set, but expires_at is past even the grace period → free.
        $graceMinutes = Order::ttlGraceMinutes();

        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'pending_payment',
            'p24_token' => 'test-p24-token-456',
            'expires_at' => now()->subMinutes($graceMinutes + 10),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 3,
            'start_date' => Carbon::parse('2026-05-10'),
            'end_date' => Carbon::parse('2026-05-12'),
        ]);

        $result = $this->svc->getMonthlyAvailability($this->item, 2026, 5);

        $this->assertSame(10, $result['2026-05-11']['available_quantity']);
        $this->assertSame('available', $result['2026-05-11']['status']);
    }
}
