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
 * Proves App\Observers\ServiceLocationStockObserver is actually wired up in
 * AppServiceProvider (a config-file claim is not the same as a running
 * effect — claude-code-config.md's own rule for this repo) by creating a
 * REAL Location through Eloquent (not SyncServiceLocationStock::forLocation()
 * directly, which tests/Unit/Actions/SyncServiceLocationStockTest.php
 * already covers as a pure unit) and observing the side effect.
 */
class ServiceLocationStockObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_new_location_materializes_a_zero_row_for_every_existing_item_rental_service(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $existingLocation = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 4]);

        $newLocation = Location::factory()->for($org, 'organization')->create();

        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $newLocation->id,
            'quantity' => 0,
        ]);
    }

    public function test_creating_a_location_for_an_organization_with_no_item_rental_services_creates_no_rows(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        Location::factory()->for($org, 'organization')->create();

        $this->assertSame(0, ServiceLocationStock::withoutGlobalScope('organization')->count());
    }
}
