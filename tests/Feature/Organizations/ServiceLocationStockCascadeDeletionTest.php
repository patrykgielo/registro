<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exact scenario 2026_08_28_090000_create_service_location_stocks_table's
 * own docblock argues its onDelete choices avoid: hard-deleting an
 * organization must succeed even while service_location_stocks rows still
 * reference its locations, because `location_id` is cascadeOnDelete (a
 * genuine multi-level cascade organizations -> locations ->
 * service_location_stocks) rather than restrictOnDelete (which would race
 * two sibling cascades hanging off the same organizations row with no
 * guaranteed ordering between them).
 *
 * Same forceDelete()/bypassDeleteGuard() pattern as
 * tests/Feature/Organizations/LocationCascadeDeletionTest.php — see that
 * test's own docblock for why a real DB-level DELETE (not the ordinary
 * SoftDeletes delete()) is required to exercise cascadeOnDelete at all.
 */
class ServiceLocationStockCascadeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_deleting_an_organization_with_existing_stock_rows_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $stock = ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => 5,
        ]);

        $org->bypassDeleteGuard = true;
        $org->forceDelete();

        // No QueryException was thrown above (the test would have failed
        // with an uncaught exception if location_id had been
        // restrictOnDelete instead), AND the row is actually gone.
        $this->assertDatabaseMissing('service_location_stocks', ['id' => $stock->id]);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_hard_deleting_an_organization_with_multiple_locations_and_stock_rows_succeeds(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $secondary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $primary->id, 'quantity' => 3,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $secondary->id, 'quantity' => 2,
        ]);

        $org->bypassDeleteGuard = true;
        $org->forceDelete();

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->count()
        );
    }

    /**
     * The service itself is NOT removed by the organization cascade
     * (services.organization_id is nullOnDelete, not cascadeOnDelete —
     * 2026_03_08_000003_add_organization_id_to_existing_tables.php) — only
     * orphaned. Its stock row is cascaded away purely via the location_id
     * path (the location itself gets cascade-deleted by the organization),
     * independent of service_id's own onDelete choice.
     */
    public function test_the_service_survives_orphaned_while_its_stock_row_is_cascaded_away(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);

        $org->bypassDeleteGuard = true;
        $org->forceDelete();

        $this->assertDatabaseHas('services', ['id' => $service->id, 'organization_id' => null]);
    }
}
