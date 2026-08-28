<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteQuantityFieldToPrimaryLocationStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_has_exactly_one_active_location_is_false_with_zero_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $this->assertFalse(RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation($org->id));
    }

    public function test_tenant_has_exactly_one_active_location_is_true_with_exactly_one(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();

        $this->assertTrue(RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation($org->id));
    }

    public function test_tenant_has_exactly_one_active_location_is_false_with_two_active_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        Location::factory()->for($org, 'organization')->create();

        $this->assertFalse(RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation($org->id));
    }

    public function test_tenant_has_exactly_one_active_location_ignores_an_inactive_second_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        Location::factory()->inactive()->for($org, 'organization')->create();

        $this->assertTrue(RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation($org->id));
    }

    public function test_handle_routes_the_quantity_into_the_primary_locations_stock_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 8]);

        RouteQuantityFieldToPrimaryLocationStock::handle($service);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $primary->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(8, $row->quantity);
    }

    public function test_handle_recalculates_quantity_total_from_the_stock_row_it_just_wrote(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 8]);

        RouteQuantityFieldToPrimaryLocationStock::handle($service);

        $this->assertSame(8, $service->fresh()->quantity_total);
    }

    public function test_handle_updates_an_already_existing_stock_row_rather_than_creating_a_second_one(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 3]);
        RouteQuantityFieldToPrimaryLocationStock::handle($service);

        $service->quantity_total = 10;
        $service->save();
        RouteQuantityFieldToPrimaryLocationStock::handle($service);

        $this->assertSame(
            1,
            ServiceLocationStock::withoutGlobalScope('organization')
                ->where('service_id', $service->id)->where('location_id', $primary->id)->count()
        );
        $this->assertSame(10, $service->fresh()->quantity_total);
    }

    /**
     * The critical "never clobber a split" guarantee this action's own
     * docblock argues for: a multi-location tenant's field is disabled and
     * un-dehydrated in ServiceResource's form, but handle() must ALSO refuse
     * on its own — defence in depth, not trust in the form layer alone —
     * so calling it directly (as a test, or any future caller) can never
     * silently overwrite a deliberately per-location split with the
     * aggregate quantity_total.
     */
    public function test_handle_does_nothing_for_a_multi_location_tenant(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 5]);

        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $primary->id, 'quantity' => 2,
        ]);

        RouteQuantityFieldToPrimaryLocationStock::handle($service);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $primary->id)->first();

        $this->assertSame(2, $row->quantity, 'must not have been overwritten with quantity_total (5)');
    }

    public function test_handle_is_a_no_op_for_a_time_slot_service(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->for($org, 'organization')->create();

        RouteQuantityFieldToPrimaryLocationStock::handle($service);

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->count()
        );
    }

    /**
     * code-reviewer BLOKER 1 (Faza 2): tenantHasExactlyOneActiveLocation()
     * only counts Location.is_active — it says nothing about whether
     * service_location_stocks still holds a row at a location that used to
     * be active. A tenant that deactivates its SECOND location leaves that
     * row orphaned (still `quantity=3`, still `is_active=true` on the STOCK
     * row — Location.is_active and ServiceLocationStock.is_active are
     * independent columns), while the org "looks" single-location again. If
     * handle() only checked tenant shape, it would absorb the orphan into
     * the primary's row (5 -> 8) and immediately re-sum it back in via
     * recalculateQuantityTotal() (8 + 3 = 11) — and repeat, unbounded, on
     * every subsequent save even with the field's value UNCHANGED, because
     * each absorption makes the primary row's own quantity bigger.
     */
    public function test_handle_does_not_inflate_quantity_total_when_an_orphaned_stock_row_exists_at_a_deactivated_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $secondary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 8]);

        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $primary->id, 'quantity' => 5,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $secondary->id, 'quantity' => 3,
        ]);

        $secondary->update(['is_active' => false]);
        $this->assertTrue(RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation($org->id));

        // Two "Save" clicks in a row with the field's value untouched
        // (still showing 8, the mirror as of before either save).
        RouteQuantityFieldToPrimaryLocationStock::handle($service->fresh());
        RouteQuantityFieldToPrimaryLocationStock::handle($service->fresh());

        $this->assertSame(8, $service->fresh()->quantity_total, 'a no-op save must be idempotent, not inflate the mirror');
        $this->assertSame(
            5,
            ServiceLocationStock::withoutGlobalScope('organization')
                ->where('service_id', $service->id)->where('location_id', $primary->id)->value('quantity'),
            'the orphaned row must not have been absorbed into the primary'
        );
    }
}
