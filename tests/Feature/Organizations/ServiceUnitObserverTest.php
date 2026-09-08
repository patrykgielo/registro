<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves App\Observers\ServiceUnitObserver is actually wired up in
 * AppServiceProvider (a config-file claim is not the same as a running
 * effect — claude-code-config.md's own rule for this repo) by creating REAL
 * ServiceUnit rows through Eloquent and observing the anchor
 * (service_location_stocks.quantity) and Service::quantity_total mirror.
 */
class ServiceUnitObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_available_unit_increments_the_anchor_and_quantity_total(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        $stock = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $location->id)->first();

        $this->assertNotNull($stock);
        $this->assertSame(1, $stock->quantity);
        $this->assertSame(1, $service->fresh()->quantity_total);
    }

    public function test_creating_a_unit_materializes_a_missing_anchor_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);
        $this->assertSame(0, ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->count());

        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);
    }

    /**
     * The count is exactly `COUNT(units WHERE status = 'available')` —
     * model-danych.md krok 3.2. A unit created in `maintenance` never
     * touches the anchor.
     */
    public function test_creating_a_maintenance_unit_does_not_increment_the_anchor(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        ServiceUnit::factory()->maintenance()->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 0,
        ]);
        $this->assertSame(0, $service->fresh()->quantity_total);
    }

    /**
     * Sending a unit to maintenance decrements the anchor by exactly 1 —
     * "serwis pojedynczej sztuki zdejmuje 1 z dostępności" (krok 3.5's own
     * criterion, already true as a side effect of 3.2's COUNT recompute).
     */
    public function test_flipping_a_unit_to_maintenance_decrements_the_anchor_by_one(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        $unitA = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        $this->assertSame(2, $service->fresh()->quantity_total);

        $unitA->update(['status' => 'maintenance']);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);
        $this->assertSame(1, $service->fresh()->quantity_total);
    }

    /**
     * Moving a unit to a different location recalculates BOTH anchors — the
     * old location loses it, the new one gains it. Missing this (recomputing
     * only the destination) would leave the source location's anchor
     * permanently overstated by one unit it no longer has.
     */
    public function test_moving_a_unit_between_locations_recalculates_both_anchors(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create();
        $locationB = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $locationA->id,
        ]);

        $unit->update(['location_id' => $locationB->id]);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $locationA->id, 'quantity' => 0,
        ]);
        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $locationB->id, 'quantity' => 1,
        ]);
        $this->assertSame(1, $service->fresh()->quantity_total);
    }

    public function test_deleting_a_unit_decrements_the_anchor(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);
        $this->assertSame(1, $service->fresh()->quantity_total);

        $unit->delete();

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 0,
        ]);
        $this->assertSame(0, $service->fresh()->quantity_total);
    }

    /**
     * Invariant A (rental-availability.md §5, model-danych.md): a unit that
     * is physically out on rental stays `available` — occupancy in a date
     * window lives EXCLUSIVELY in rentals/order_items. Nothing in this
     * observer reads either table; the anchor tracks only `service_units.status`.
     * Proven by creating an overlapping Rental for the same service and
     * asserting the anchor is completely unaffected.
     */
    public function test_the_anchor_is_unaffected_by_an_overlapping_rental_because_units_never_change_status_for_it(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);
        $this->assertSame(1, $service->fresh()->quantity_total);

        Rental::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
        ]);

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);
        $this->assertSame(1, $service->fresh()->quantity_total, 'a rental record must never move a unit out of the available count');
    }

    /**
     * Decision documented in the observer's own docblock: is_active on the
     * anchor row is an unrelated operator toggle, not a derived "is anything
     * physically here right now" flag — driving all of a service's units
     * into maintenance must not flip it.
     */
    public function test_sending_the_only_unit_to_maintenance_does_not_touch_the_anchors_is_active_flag(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        $unit->update(['status' => 'maintenance']);

        $stock = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $location->id)->first();

        $this->assertSame(0, $stock->quantity);
        $this->assertTrue($stock->is_active);
    }

    /**
     * kontrakt-dostepnosci.md Zasada 4, "Po dodaniu kotwicy": insertOrIgnore()
     * must never fire inside a transaction holding Service::lockForUpdate() —
     * a deadlock generator. The observer's guard (updated() only recalculates
     * when `location_id` or `status` changed) is what keeps issue/return
     * (which never touch either field — Invariant A) off that path entirely.
     *
     * A before/after value comparison would NOT prove this: insertOrIgnore()
     * on an already-existing row is a no-op on its VALUES too, but it still
     * takes the S-lock that causes the deadlock. The only observable proof is
     * that no SQL touching service_location_stocks runs at all.
     */
    public function test_updating_a_field_unrelated_to_status_or_location_never_touches_the_anchor_table(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 0]);

        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        DB::enableQueryLog();
        $unit->update(['notes' => 'Serwisowane 2026-09-08', 'inventory_number' => 'INV-001']);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->isNotEmpty(),
            'sanity check: the update itself must have run at least one query'
        );
        $this->assertTrue(
            $queries->every(fn (string $sql) => ! str_contains($sql, 'service_location_stocks')),
            "updating notes/inventory_number must not run ANY query against service_location_stocks — got:\n"
                .$queries->implode("\n")
        );
    }
}
