<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Inventory\SyncServiceLocationStock;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncServiceLocationStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_service_seeds_the_primary_location_with_the_current_quantity_total_when_no_stock_row_exists_yet(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 6]);

        SyncServiceLocationStock::forService($service);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $primary->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(6, $row->quantity);
    }

    public function test_for_service_seeds_every_other_active_location_with_zero(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $secondary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 6]);

        SyncServiceLocationStock::forService($service);

        $secondaryRow = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $secondary->id)->first();

        $this->assertNotNull($secondaryRow);
        $this->assertSame(0, $secondaryRow->quantity);
    }

    public function test_for_service_skips_inactive_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        $inactive = Location::factory()->inactive()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 6]);

        SyncServiceLocationStock::forService($service);

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')
                ->where('service_id', $service->id)->where('location_id', $inactive->id)->count()
        );
    }

    /**
     * The core "materialize, don't overwrite" guarantee: once a stock row
     * has been split by hand (e.g. via the panel), a later call — the
     * relation manager mounting again — must never re-seed the primary
     * location back to quantity_total and clobber the split.
     */
    public function test_for_service_never_overwrites_an_already_existing_stock_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $primary = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 6]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $primary->id, 'quantity' => 2,
        ]);

        SyncServiceLocationStock::forService($service);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $primary->id)->first();

        $this->assertSame(2, $row->quantity);
    }

    public function test_for_service_is_a_no_op_for_a_time_slot_service(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->for($org, 'organization')->create();

        SyncServiceLocationStock::forService($service);

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->count()
        );
    }

    public function test_for_service_is_a_no_op_when_the_organization_has_no_active_locations(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 6]);

        SyncServiceLocationStock::forService($service);

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->count()
        );
    }

    public function test_for_location_seeds_every_item_rental_service_of_the_organization_with_zero(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $existingLocation = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 6]);

        $newLocation = Location::factory()->for($org, 'organization')->create();

        SyncServiceLocationStock::forLocation($newLocation);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $newLocation->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(0, $row->quantity, 'a new location never inherits an opening quantity, unlike forService()');
    }

    public function test_for_location_skips_time_slot_services(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $timeSlotService = Service::factory()->for($org, 'organization')->create();
        $location = Location::factory()->for($org, 'organization')->create();

        SyncServiceLocationStock::forLocation($location);

        $this->assertSame(
            0,
            ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $timeSlotService->id)->count()
        );
    }

    public function test_for_location_never_overwrites_an_already_existing_stock_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 9,
        ]);

        SyncServiceLocationStock::forLocation($location);

        $row = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)->where('location_id', $location->id)->first();

        $this->assertSame(9, $row->quantity);
    }
}
