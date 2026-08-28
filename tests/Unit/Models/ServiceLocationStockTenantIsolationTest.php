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
 * ServiceLocationStock gets tenant isolation for free from
 * BelongsToOrganization, same guarantee LocationTenantIsolationTest pins for
 * Location.
 */
class ServiceLocationStockTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_a_cannot_see_tenant_bs_stock_rows(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $locationA = Location::factory()->for($orgA, 'organization')->create();
        $serviceA = Service::factory()->itemRental()->create(['organization_id' => $orgA->id]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $orgA->id, 'service_id' => $serviceA->id, 'location_id' => $locationA->id, 'quantity' => 3,
        ]);

        $locationB = Location::factory()->for($orgB, 'organization')->create();
        $serviceB = Service::factory()->itemRental()->create(['organization_id' => $orgB->id]);
        ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $orgB->id, 'service_id' => $serviceB->id, 'location_id' => $locationB->id, 'quantity' => 9,
        ]);

        $this->app['request']->attributes->set('tenant', $orgA);

        $visible = ServiceLocationStock::all();

        $this->assertCount(1, $visible);
        $this->assertSame(3, $visible->first()->quantity);
    }

    public function test_organization_id_is_auto_assigned_from_resolved_tenant_when_omitted(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        $this->app['request']->attributes->set('tenant', $org);

        $stock = ServiceLocationStock::create([
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => 4,
        ]);

        $this->assertSame($org->id, $stock->organization_id);
    }

    public function test_only_quantity_changes_are_audited(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $stock = ServiceLocationStock::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id, 'quantity' => 1,
        ]);

        $stock->update(['quantity' => 5, 'is_active' => false]);

        $log = \App\Models\AuditLog::query()
            ->where('auditable_type', ServiceLocationStock::class)
            ->where('auditable_id', $stock->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('quantity', $log->new_values);
        $this->assertArrayNotHasKey('is_active', $log->new_values);
    }
}
