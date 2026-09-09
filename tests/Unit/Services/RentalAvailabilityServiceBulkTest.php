<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RentalStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faza 4 krok 4.7 (plan-wdrozenia.md, kontrakt-dostepnosci.md) —
 * RentalAvailabilityService::availabilityForServices(). Two things this
 * class exists to prove, per the team lead's explicit brief:
 *
 *   1. Constant query count — the whole point of this method is killing an
 *      N+1 on a category listing. A test that only checks the RESULT and
 *      never counts queries would let a regression back to N+1 ship green.
 *   2. Parity with getAvailableQuantity() — this is a SECOND place doing the
 *      same math (Zasada 1 makes that dangerous by construction: the
 *      project's own history is two earlier "shortcut" copies that silently
 *      diverged from the truth). Every scenario below is checked against an
 *      equivalent set of direct getAvailableQuantity() calls for the same
 *      data, not against a hand-computed expected number.
 */
class RentalAvailabilityServiceBulkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Location $locationA;

    private Location $locationB;

    private RentalCategory $category;

    private RentalAvailabilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->svc = app(RentalAvailabilityService::class);

        $this->locationA = Location::factory()->for($this->org, 'organization')->create();
        $this->locationB = Location::factory()->for($this->org, 'organization')->create();

        // ONE shared category, reused by every makeItem() call below — the
        // default itemRental() state creates a NEW RentalCategory::factory()
        // per service, and that factory draws its `name` from a fixed
        // 8-value fake()->unique() pool (RentalCategoryFactory.php:16) that
        // this file's 50-service query-count test blows straight through
        // (OverflowException), unrelated to anything under test here.
        $this->category = RentalCategory::factory()->for($this->org, 'organization')->create();
    }

    private function start(): Carbon
    {
        return Carbon::parse('2026-05-01');
    }

    private function end(): Carbon
    {
        return Carbon::parse('2026-05-05');
    }

    /**
     * Explicit updateOrCreate(), NOT a `where(...)->update(...)` against a
     * row ServiceLocationStockObserver is assumed to have already
     * materialized — that observer only fires on Location::created() for
     * services that ALREADY exist at that moment (see
     * RentalAvailabilityServiceLocationTest's setUp() docblock). Locations
     * here are created once in setUp(), BEFORE any service this method
     * creates — so no anchor row would exist yet, and a plain `update()`
     * against zero matching rows would silently no-op instead of failing
     * loudly. updateOrCreate() works regardless of creation order.
     */
    private function makeItem(int $quantityA, int $quantityB): Service
    {
        $item = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'rental_category_id' => $this->category->id,
            'quantity_total' => $quantityA + $quantityB + 100, // deliberately not the anchor sum
            'price_per_day' => 50,
        ]);

        ServiceLocationStock::updateOrCreate(
            ['service_id' => $item->id, 'location_id' => $this->locationA->id],
            ['organization_id' => $this->org->id, 'quantity' => $quantityA]
        );

        ServiceLocationStock::updateOrCreate(
            ['service_id' => $item->id, 'location_id' => $this->locationB->id],
            ['organization_id' => $this->org->id, 'quantity' => $quantityB]
        );

        return $item;
    }

    // -------------------------------------------------------------------------
    // 1. Constant query count — the entire reason this method exists
    // -------------------------------------------------------------------------

    public function test_query_count_is_constant_regardless_of_how_many_services_are_passed(): void
    {
        $few = collect(range(1, 3))->map(fn () => $this->makeItem(2, 3));
        $many = collect(range(1, 50))->map(fn () => $this->makeItem(2, 3));

        DB::enableQueryLog();
        $this->svc->availabilityForServices($few, $this->start(), $this->end());
        $fewCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->svc->availabilityForServices($many, $this->start(), $this->end());
        $manyCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(
            $fewCount,
            $manyCount,
            "Query count must be constant: 3 services issued {$fewCount} queries, 50 services issued {$manyCount}."
        );
        $this->assertSame(3, $fewCount, 'Expected exactly 3 bulk queries: Rental aggregate, OrderItem aggregate, anchor read.');
    }

    public function test_empty_collection_issues_zero_queries_and_returns_an_empty_array(): void
    {
        DB::enableQueryLog();
        $result = $this->svc->availabilityForServices(collect(), $this->start(), $this->end());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $result);
        $this->assertSame(0, $count);
    }

    // -------------------------------------------------------------------------
    // 2. Parity with getAvailableQuantity() for the SAME data
    // -------------------------------------------------------------------------

    public function test_bulk_result_matches_individual_get_available_quantity_calls_with_mixed_reservations(): void
    {
        $itemOne = $this->makeItem(3, 2);
        $itemTwo = $this->makeItem(5, 1);

        // itemOne: a paid order in location A, a legacy rental in location B.
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $itemOne->id,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $itemOne->id,
            'location_id' => $this->locationB->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
            'status' => RentalStatus::Confirmed,
        ]);

        // itemTwo: an unassigned (location_id = null) reservation — must
        // block BOTH locations AND the global total.
        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $itemTwo->id,
            'location_id' => null,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $services = collect([$itemOne, $itemTwo]);
        $bulk = $this->svc->availabilityForServices($services, $this->start(), $this->end());

        foreach ($services as $service) {
            $expectedTotal = $this->svc->getAvailableQuantity($service, $this->start(), $this->end(), locationId: null);
            $expectedA = $this->svc->getAvailableQuantity($service, $this->start(), $this->end(), locationId: $this->locationA->id);
            $expectedB = $this->svc->getAvailableQuantity($service, $this->start(), $this->end(), locationId: $this->locationB->id);

            $this->assertSame($expectedTotal, $bulk[$service->id]['total'], "total mismatch for service {$service->id}");
            $this->assertSame($expectedA, $bulk[$service->id]['locations'][$this->locationA->id], "location A mismatch for service {$service->id}");
            $this->assertSame($expectedB, $bulk[$service->id]['locations'][$this->locationB->id], "location B mismatch for service {$service->id}");
        }

        // Concrete numbers, not just "matches itself" — pins the actual
        // resolution of the null-location-blocks-everywhere rule in bulk form.
        $this->assertSame(102, $bulk[$itemOne->id]['total']); // quantity_total(=3+2+100=105) - 2(A) - 1(B) = 102
        $this->assertSame(1, $bulk[$itemOne->id]['locations'][$this->locationA->id]); // 3 - 2
        $this->assertSame(1, $bulk[$itemOne->id]['locations'][$this->locationB->id]); // 2 - 1

        $this->assertSame(105, $bulk[$itemTwo->id]['total']); // quantity_total(=5+1+100=106) - 1(null bucket) = 105
        $this->assertSame(4, $bulk[$itemTwo->id]['locations'][$this->locationA->id]); // 5 - 1(null block)
        $this->assertSame(0, $bulk[$itemTwo->id]['locations'][$this->locationB->id]); // 1 - 1(null block)
    }

    public function test_bulk_result_matches_individual_calls_when_a_service_has_no_reservations_at_all(): void
    {
        $item = $this->makeItem(4, 6);

        $bulk = $this->svc->availabilityForServices(collect([$item]), $this->start(), $this->end());

        $this->assertSame(
            $this->svc->getAvailableQuantity($item, $this->start(), $this->end(), locationId: null),
            $bulk[$item->id]['total']
        );
        $this->assertSame(4, $bulk[$item->id]['locations'][$this->locationA->id]);
        $this->assertSame(6, $bulk[$item->id]['locations'][$this->locationB->id]);
    }

    public function test_bulk_result_respects_the_pending_payment_ttl_expiry_mirror(): void
    {
        $item = $this->makeItem(3, 3);

        $expired = Order::factory()->expired()->create(['organization_id' => $this->org->id]);
        OrderItem::factory()->create([
            'order_id' => $expired->id,
            'service_id' => $item->id,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $bulk = $this->svc->availabilityForServices(collect([$item]), $this->start(), $this->end());

        $this->assertSame(3, $bulk[$item->id]['locations'][$this->locationA->id], 'An expired pending_payment order must not block a location in bulk either.');
        $this->assertSame(
            $this->svc->getAvailableQuantity($item, $this->start(), $this->end(), locationId: $this->locationA->id),
            $bulk[$item->id]['locations'][$this->locationA->id]
        );
    }

    /**
     * Falsifiability check for the merge logic itself (not just the SQL):
     * a reservation OUTSIDE the queried date window must not appear in
     * either bucket. Proves the bulk query's own date filter, independent
     * of the location-merge math the other tests focus on.
     */
    public function test_bulk_result_ignores_reservations_outside_the_queried_window(): void
    {
        $item = $this->makeItem(2, 2);

        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $item->id,
            'location_id' => $this->locationA->id,
            'quantity' => 1,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-05'),
        ]);

        $bulk = $this->svc->availabilityForServices(collect([$item]), $this->start(), $this->end());

        $this->assertSame(2, $bulk[$item->id]['locations'][$this->locationA->id]);
    }

    // -------------------------------------------------------------------------
    // 3. Interface contract: missing key means ZERO, not "unlimited" (code
    //    review 2026-09-09) — the one scenario makeItem() can never produce,
    //    since it always upserts an anchor row for BOTH locations.
    // -------------------------------------------------------------------------

    /**
     * Deliberately NOT using makeItem() — that helper always creates an
     * anchor row for both locations, so it cannot reproduce the case under
     * test. This service gets an anchor row for location A only; location B
     * gets a real reservation but NO anchor row at all (the anchor table
     * and the reservation tables have no FK between them — nothing stops
     * this state from existing, e.g. an anchor row deleted after the fact).
     */
    public function test_a_location_with_reservations_but_no_anchor_row_is_absent_from_the_result_and_that_means_zero_not_unlimited(): void
    {
        $item = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'rental_category_id' => $this->category->id,
            'quantity_total' => 50,
            'price_per_day' => 50,
        ]);

        ServiceLocationStock::updateOrCreate(
            ['service_id' => $item->id, 'location_id' => $this->locationA->id],
            ['organization_id' => $this->org->id, 'quantity' => 5]
        );
        // No anchor row created for locationB at all.

        OrderItem::factory()->create([
            'order_id' => Order::factory()->paid()->create(['organization_id' => $this->org->id])->id,
            'service_id' => $item->id,
            'location_id' => $this->locationB->id,
            'quantity' => 1,
            'start_date' => $this->start(),
            'end_date' => $this->end(),
        ]);

        $bulk = $this->svc->availabilityForServices(collect([$item]), $this->start(), $this->end());

        $this->assertArrayHasKey($this->locationA->id, $bulk[$item->id]['locations']);
        $this->assertArrayNotHasKey(
            $this->locationB->id,
            $bulk[$item->id]['locations'],
            'A location with no anchor row must be ABSENT from the result, not present with any numeric value — a caller doing isset() as "do we have data" would be correct here, but ?? 0 is the only correct way to read a lookup by id.'
        );

        // The absent key and getAvailableQuantity()'s explicit 0 describe
        // the SAME reality in two different shapes — this is what makes
        // "?? 0" the correct reading, not a coincidence.
        $this->assertSame(
            0,
            $this->svc->getAvailableQuantity($item, $this->start(), $this->end(), locationId: $this->locationB->id)
        );
    }
}
