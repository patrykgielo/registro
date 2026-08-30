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
use Illuminate\Support\Facades\DB;
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
    // Faza 0.1 — scenarios moved from the now-deleted Service::availableQuantity()
    // / Service::isAvailable() (RentalItemAvailabilityTest, removed) that were
    // NOT already covered above. That method skipped RentalStatus::Held — this
    // pins the exact gap it got wrong.
    // -------------------------------------------------------------------------

    public function test_deducts_held_rental_from_available_quantity(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Held,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(8, $available);
    }

    public function test_does_not_deduct_returned_rental(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 3,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Returned,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    public function test_multiple_overlapping_rentals_cumulatively_reduce_availability(): void
    {
        $item = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Active,
        ]);

        $available = $this->svc->getAvailableQuantity($item, $this->start(), $this->end());

        $this->assertEquals(2, $available);
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

    // -------------------------------------------------------------------------
    // Offline settlement mode — hold TTL is just a longer expires_at, no
    // p24_token involved. Pins that OrderItem::scopeBlockingAvailability()
    // and Order::scopeExpired() agree on which offline orders are "alive"
    // (this is the overbooking regression the two scopes must never diverge
    // on — see both scopes' docblocks).
    // -------------------------------------------------------------------------

    public function test_deducts_active_offline_pending_payment_order_items(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create([
            'organization_id' => $this->org->id,
            'expires_at' => now()->addHours(40), // well within a 48h hold, no p24_token
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(8, $available, 'A still-held offline order must block inventory.');
        $this->assertCount(0, Order::expired()->get(), 'The same order must NOT be eligible for TTL cleanup while still held.');
    }

    public function test_does_not_deduct_offline_order_after_its_hold_expires(): void
    {
        $order = Order::factory()->offline()->create([
            'organization_id' => $this->org->id,
            'status' => 'pending_payment',
            // Past the hold window. No p24_token → no grace-period extension applies
            // (that extension is exclusively for orders mid-P24-payment).
            'expires_at' => now()->subHours(1),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available, 'A lapsed offline hold must free the inventory it was blocking.');
        $this->assertCount(1, Order::expired()->get(), 'The same order MUST be eligible for TTL cleanup once its hold has lapsed.');
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

    // -------------------------------------------------------------------------
    // Faza 0.2 — gaps measured against the docblock's promises before the
    // location dimension lands in Faza 4. These pin today's exact numbers so
    // the $locationId === null branch can be proven bit-for-bit identical
    // afterwards.
    // -------------------------------------------------------------------------

    public function test_deducts_pending_rental_from_available_quantity(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 4,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Pending,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(6, $available);
    }

    public function test_does_not_deduct_expired_rental(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 4,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Expired,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(10, $available);
    }

    public function test_exclude_rental_id_omits_its_own_reservation_from_the_sum(): void
    {
        $rental = Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 4,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        $withoutExclude = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());
        $withExclude = $this->svc->getAvailableQuantity(
            $this->item,
            $this->start(),
            $this->end(),
            excludeRentalId: $rental->id
        );

        $this->assertEquals(6, $withoutExclude, 'The rental blocks itself when not excluded.');
        $this->assertEquals(10, $withExclude, 'excludeRentalId must omit the row\'s own reservation from the sum.');
    }

    public function test_for_update_parity_returns_same_quantity_as_plain_read(): void
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

        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $plain = DB::transaction(fn () => $this->svc->getAvailableQuantity(
            $this->item, $this->start(), $this->end(), forUpdate: false
        ));
        $locking = DB::transaction(fn () => $this->svc->getAvailableQuantity(
            $this->item, $this->start(), $this->end(), forUpdate: true
        ));

        $this->assertEquals(5, $plain);
        $this->assertEquals($plain, $locking, 'forUpdate must not change the arithmetic, only lock semantics.');
    }

    public function test_touching_boundary_day_counts_as_overlap_for_rental(): void
    {
        // Blocking rental ends exactly on the day the query window starts.
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 4,
            'start_date' => Carbon::parse('2026-04-27'),
            'end_date' => $this->start(), // 2026-05-01, same as query's start
            'status' => RentalStatus::Confirmed,
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(6, $available, 'A rental ending on the query window\'s start day must count as a collision (inclusive both sides).');
    }

    public function test_touching_boundary_day_counts_as_overlap_for_order_item(): void
    {
        // Blocking order item ends exactly on the day the query window starts —
        // must mirror the Rental path above (scopeOverlappingDates uses whereDate
        // instead of plain where, but both columns are DATE-typed, so semantics
        // must be identical).
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'quantity' => 4,
            'start_date' => Carbon::parse('2026-04-27'),
            'end_date' => $this->start(), // 2026-05-01, same as query's start
        ]);

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());

        $this->assertEquals(6, $available, 'An order item ending on the query window\'s start day must count as a collision, same as the Rental path.');
    }
}
