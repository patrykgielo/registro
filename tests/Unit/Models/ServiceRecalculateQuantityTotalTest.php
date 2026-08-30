<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service::recalculateQuantityTotal() — kontrakt-dostepnosci.md Zasada 2's
 * mirror. quantity_total is what getAvailableQuantity() still reads
 * literally today (Faza 4 hasn't wired the location dimension in yet), so
 * this is the ONLY thing that makes a stock-row edit visible in this phase.
 */
class ServiceRecalculateQuantityTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_quantity_total_to_the_sum_of_all_stock_rows(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create();
        $locationB = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 1]);

        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $locationA->id, 'quantity' => 3,
        ]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $locationB->id, 'quantity' => 4,
        ]);

        $service->recalculateQuantityTotal();

        $this->assertSame(7, $service->quantity_total);
        $this->assertSame(7, $service->fresh()->quantity_total);
    }

    public function test_zero_stock_rows_recalculates_to_zero(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 5]);

        $service->recalculateQuantityTotal();

        $this->assertSame(0, $service->quantity_total);
        $this->assertSame(0, $service->fresh()->quantity_total);
    }

    /**
     * A raw query update, not save() — pinned by observing that
     * `updated_at` is left untouched, which save() would always bump.
     * Deliberate (see the method's own docblock): Service has no Auditable
     * trait, and the raw UPDATE also means booted()'s updating() hook
     * (immutable service_type guard, cross-tenant rental_category check)
     * never runs for this write, which is fine since neither field is
     * involved.
     */
    public function test_uses_a_raw_update_that_does_not_touch_updated_at(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'quantity_total' => 1]);
        $originalUpdatedAt = $service->fresh()->updated_at;

        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 2,
        ]);

        $this->travel(1)->hour();
        $service->recalculateQuantityTotal();

        $this->assertSame(2, $service->quantity_total);
        $this->assertTrue($originalUpdatedAt->equalTo($service->fresh()->updated_at));
    }
}
