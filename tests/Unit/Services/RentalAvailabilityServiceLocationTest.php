<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RentalStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faza 4 kroki 4.1/4.2/4.3 (plan-wdrozenia.md, kontrakt-dostepnosci.md) — the
 * `$locationId` branch of getAvailableQuantity(). Deliberately a SEPARATE
 * file from RentalAvailabilityServiceTest.php: that file is the Faza 0 krok
 * 0.2 characterization suite pinning today's `$locationId === null` values —
 * it must NOT change as part of this work (the team lead's own zero-
 * regression invariant), so every new location-aware assertion lives here
 * instead of being interleaved into it.
 *
 * No 9 call sites pass $locationId yet (that is Faza 4 krok 4.4+, out of
 * scope for this stage) — every test below calls
 * RentalAvailabilityService::getAvailableQuantity() directly with an
 * explicit $locationId, the only way to exercise this branch today.
 */
class RentalAvailabilityServiceLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Service $item;

    private Location $locationA;

    private Location $locationB;

    private RentalAvailabilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->item = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 999, // deliberately absurd — proves the location branch never reads this
            'price_per_day' => 50,
        ]);

        // Creating a Location auto-materializes a zero-quantity anchor row
        // for every existing item_rental service of the org
        // (App\Observers\ServiceLocationStockObserver::created() ->
        // SyncServiceLocationStock::forLocation()) — $this->item already
        // exists by this point, so both locations get a row for it for
        // free. Setting the actual quantities below is therefore an
        // UPDATE of an already-materialized row, not an insert; using
        // ServiceLocationStock::factory()->create() here would collide
        // with UNIQUE(service_id, location_id) against that same row.
        $this->locationA = Location::factory()->for($this->org, 'organization')->create();
        $this->locationB = Location::factory()->for($this->org, 'organization')->create();

        ServiceLocationStock::where('service_id', $this->item->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 3]);

        ServiceLocationStock::where('service_id', $this->item->id)
            ->where('location_id', $this->locationB->id)
            ->update(['quantity' => 2]);

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
    // Zasada 2 — the null branch is untouched by the anchor table existing
    // -------------------------------------------------------------------------

    public function test_null_location_id_reads_quantity_total_literally_ignoring_the_anchor_rows(): void
    {
        // Anchor rows sum to 3 + 2 = 5, quantity_total is 999 — if the null
        // branch read the anchor table even by accident, this would return
        // something close to 5, not 999.
        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: null);

        $this->assertEquals(999, $available);
    }

    public function test_omitting_location_id_entirely_behaves_identically_to_passing_null_explicitly(): void
    {
        $withoutArgument = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end());
        $withExplicitNull = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: null);

        $this->assertEquals($withoutArgument, $withExplicitNull);
        $this->assertEquals(999, $withoutArgument);
    }

    // -------------------------------------------------------------------------
    // Capacity comes from the anchor row, not quantity_total
    // -------------------------------------------------------------------------

    public function test_location_branch_reads_capacity_from_the_anchor_row(): void
    {
        $availableA = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationA->id);
        $availableB = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationB->id);

        $this->assertEquals(3, $availableA);
        $this->assertEquals(2, $availableB);
    }

    /**
     * ServiceLocationStockObserver auto-materializes a ZERO-quantity row the
     * instant a location is created (see setUp()'s own docblock) — this is
     * the common real-world shape of "nothing stocked here yet".
     */
    public function test_location_with_a_zero_quantity_anchor_row_has_zero_capacity(): void
    {
        $locationC = Location::factory()->for($this->org, 'organization')->create();

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $locationC->id);

        $this->assertEquals(0, $available);
    }

    /**
     * A genuinely MISSING row (never materialized at all — deleted here to
     * simulate it, since ServiceLocationStockObserver always creates one on
     * Location::created()) must behave identically: zero, not an error.
     * locationCapacity()'s own docblock is explicit that this path must
     * never insert one; this test proves absence really does read as 0.
     */
    public function test_location_with_no_anchor_row_at_all_has_zero_capacity_not_an_error(): void
    {
        $locationC = Location::factory()->for($this->org, 'organization')->create();
        ServiceLocationStock::where('service_id', $this->item->id)->where('location_id', $locationC->id)->delete();

        $available = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $locationC->id);

        $this->assertEquals(0, $available);
        $this->assertDatabaseMissing('service_location_stocks', [
            'service_id' => $this->item->id,
            'location_id' => $locationC->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Sedno całej fazy — a reservation in location A does not touch location B
    // -------------------------------------------------------------------------

    public function test_a_reservation_in_one_location_does_not_reduce_availability_in_another(): void
    {
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $this->item->id,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $availableA = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationA->id);
        $availableB = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationB->id);

        $this->assertEquals(1, $availableA, 'Location A must be reduced by its own reservation.');
        $this->assertEquals(2, $availableB, 'Location B must be untouched by a reservation booked against location A.');
    }

    public function test_a_legacy_rental_in_one_location_does_not_reduce_availability_in_another(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'location_id' => $this->locationB->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        $availableA = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationA->id);
        $availableB = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationB->id);

        $this->assertEquals(3, $availableA, 'Location A must be untouched by a rental booked against location B.');
        $this->assertEquals(1, $availableB, 'Location B must be reduced by its own rental.');
    }

    // -------------------------------------------------------------------------
    // location_id === NULL on the reservation — resolved: blocks EVERYWHERE
    // -------------------------------------------------------------------------

    /**
     * The decision this test pins: a reservation that pre-dates the backfill
     * (or was somehow created before a future write path started setting
     * location_id) must block EVERY location, not none — the conservative
     * direction kontrakt-dostepnosci.md's Zasada 7 already establishes for
     * this whole method. See getAvailableQuantity()'s own docblock for the
     * full reasoning.
     */
    public function test_an_order_item_with_no_location_assigned_blocks_every_location(): void
    {
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $this->item->id,
            'location_id' => null,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $availableA = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationA->id);
        $availableB = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationB->id);

        $this->assertEquals(1, $availableA, 'An unassigned reservation must still block location A.');
        $this->assertEquals(0, $availableB, 'An unassigned reservation must still block location B (capacity 2 - 2).');
    }

    public function test_a_legacy_rental_with_no_location_assigned_blocks_every_location(): void
    {
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->item->id,
            'location_id' => null,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Held,
        ]);

        $availableA = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationA->id);
        $availableB = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationB->id);

        $this->assertEquals(2, $availableA);
        $this->assertEquals(1, $availableB);
    }

    // -------------------------------------------------------------------------
    // Zasada 5 — the location filter must not disturb the existing
    // blockingAvailability()/scopeExpired() mirror (pending_payment/offline)
    // -------------------------------------------------------------------------

    public function test_location_filter_still_respects_pending_payment_ttl_expiry(): void
    {
        $expired = Order::factory()->expired()->create(['organization_id' => $this->org->id]);

        OrderItem::factory()->create([
            'order_id' => $expired->id,
            'service_id' => $this->item->id,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $availableA = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationA->id);

        $this->assertEquals(3, $availableA, 'An expired pending_payment order must not block a location either.');
    }

    public function test_location_filter_still_respects_the_p24_grace_period(): void
    {
        $order = Order::factory()->pendingPayment()->create([
            'organization_id' => $this->org->id,
            'p24_token' => 'tok_123',
            'expires_at' => now()->subMinutes(10), // within default 120-minute grace
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->item->id,
            'location_id' => $this->locationB->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $availableB = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), locationId: $this->locationB->id);

        $this->assertEquals(1, $availableB, 'A P24-registered order within grace must still block its location.');
    }

    // -------------------------------------------------------------------------
    // forUpdate parity — the location branch must not throw / must return
    // the same value under a locking read (mirrors the existing null-branch
    // parity test in the 0.2 characterization file).
    // -------------------------------------------------------------------------

    public function test_for_update_parity_returns_same_quantity_as_plain_read_for_the_location_branch(): void
    {
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $this->item->id,
            'location_id' => $this->locationA->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $plain = $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), forUpdate: false, locationId: $this->locationA->id);
        $locking = \Illuminate\Support\Facades\DB::transaction(
            fn () => $this->svc->getAvailableQuantity($this->item, $this->start(), $this->end(), forUpdate: true, locationId: $this->locationA->id)
        );

        $this->assertEquals(2, $plain);
        $this->assertEquals($plain, $locking);
    }

    // -------------------------------------------------------------------------
    // Faza 4 krok 4.6 — getMonthlyAvailability()'s $locationId branch. Mirrors
    // the getAvailableQuantity() tests above exactly, one method down.
    // -------------------------------------------------------------------------

    public function test_monthly_null_location_id_reads_quantity_total_literally_ignoring_the_anchor_rows(): void
    {
        // Anchor rows sum to 3 + 2 = 5, quantity_total is 999 — same proof
        // as the point-check equivalent above, one level down.
        $result = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: null);

        $this->assertEquals(999, $result['2026-05-01']['available_quantity']);
        $this->assertEquals('available', $result['2026-05-01']['status']);
    }

    public function test_omitting_monthly_location_id_entirely_behaves_identically_to_passing_null_explicitly(): void
    {
        $withoutArgument = $this->svc->getMonthlyAvailability($this->item, 2026, 5);
        $withExplicitNull = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: null);

        $this->assertEquals($withoutArgument, $withExplicitNull);
    }

    public function test_monthly_location_branch_reads_capacity_from_the_anchor_row(): void
    {
        $resultA = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationA->id);
        $resultB = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationB->id);

        $this->assertEquals(3, $resultA['2026-05-01']['available_quantity']);
        $this->assertEquals(2, $resultB['2026-05-01']['available_quantity']);
    }

    public function test_a_monthly_reservation_in_one_location_does_not_reduce_the_calendar_in_another(): void
    {
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $this->item->id,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $resultA = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationA->id);
        $resultB = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationB->id);

        $this->assertEquals(1, $resultA['2026-05-01']['available_quantity'], 'Location A calendar must reflect its own reservation.');
        $this->assertEquals('partial', $resultA['2026-05-01']['status']);
        $this->assertEquals(2, $resultB['2026-05-01']['available_quantity'], 'Location B calendar must be untouched.');
        $this->assertEquals('available', $resultB['2026-05-01']['status']);

        // A day outside the reservation window must stay fully available in A too.
        $this->assertEquals(3, $resultA['2026-05-10']['available_quantity']);
    }

    public function test_a_monthly_reservation_with_no_location_assigned_blocks_the_calendar_for_every_location(): void
    {
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $this->item->id,
            'location_id' => null,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $resultA = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationA->id);
        $resultB = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationB->id);

        $this->assertEquals(1, $resultA['2026-05-01']['available_quantity'], 'An unassigned reservation must still block location A on the calendar.');
        $this->assertEquals(0, $resultB['2026-05-01']['available_quantity'], 'An unassigned reservation must still block location B (capacity 2 - 2).');
        $this->assertEquals('unavailable', $resultB['2026-05-01']['status']);
    }

    /**
     * getMonthlyAvailability() must never lock, with or without a location —
     * a bare call (no transaction, no lockForUpdate anywhere in the method)
     * must simply work. This is a smoke test, not a lock-detection test
     * (SQLite has no real row locks to observe) — its only job is proving
     * the method signature/branch didn't introduce an accidental
     * lockForUpdate() call that would deadlock a real concurrent reader
     * against a writer holding the Service row.
     */
    public function test_monthly_availability_with_a_location_does_not_require_or_use_a_transaction(): void
    {
        $result = $this->svc->getMonthlyAvailability($this->item, 2026, 5, locationId: $this->locationA->id);

        $this->assertEquals(3, $result['2026-05-01']['available_quantity']);
    }
}
