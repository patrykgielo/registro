<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ServiceUnitStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ServiceUnit gets tenant isolation for free from BelongsToOrganization —
 * same guarantee ServiceLocationStockTenantIsolationTest pins for its Faza 2
 * sibling.
 */
class ServiceUnitTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_a_cannot_see_tenant_bs_units(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($orgA, 'organization')->create();
        $serviceA = Service::factory()->itemRental()->create(['organization_id' => $orgA->id]);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $orgA->id, 'service_id' => $serviceA->id, 'location_id' => $locationA->id,
        ]);

        $orgB = Organization::factory()->equipmentRental()->create();
        $locationB = Location::factory()->for($orgB, 'organization')->create();
        $serviceB = Service::factory()->itemRental()->create(['organization_id' => $orgB->id]);
        ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $orgB->id, 'service_id' => $serviceB->id, 'location_id' => $locationB->id,
            'identifier' => 'KOP-04',
        ]);

        $this->app['request']->attributes->set('tenant', $orgA);

        $visible = ServiceUnit::all();

        $this->assertCount(1, $visible);
        $this->assertNull($visible->first()->identifier);
    }

    public function test_organization_id_is_auto_assigned_from_resolved_tenant_when_omitted(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        $this->app['request']->attributes->set('tenant', $org);

        $unit = ServiceUnit::create([
            'service_id' => $service->id,
            'location_id' => $location->id,
        ]);

        $this->assertSame($org->id, $unit->organization_id);
    }

    public function test_status_casts_to_the_backed_enum(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $location->id,
            'status' => 'maintenance',
        ]);

        $this->assertSame(ServiceUnitStatus::Maintenance, $unit->fresh()->status);
    }

    public function test_location_id_status_and_identifier_changes_are_audited_but_identity_columns_are_not(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create();
        $locationB = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $locationA->id,
        ]);

        $unit->update(['location_id' => $locationB->id, 'identifier' => 'KOP-04']);

        $log = \App\Models\AuditLog::query()
            ->where('auditable_type', ServiceUnit::class)
            ->where('auditable_id', $unit->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('location_id', $log->new_values);
        $this->assertArrayHasKey('identifier', $log->new_values);
        $this->assertArrayNotHasKey('organization_id', $log->new_values);
        $this->assertArrayNotHasKey('service_id', $log->new_values);
    }

    /**
     * Mirrors Order's immutable-field guard (models.md, "Order — Auditable +
     * Immutable Fields"). Without this, re-pointing a unit at a different
     * service would silently overstate the OLD service's quantity_total —
     * ServiceUnitObserver's COUNT-based recalculation only ever runs for the
     * unit's CURRENT service_id, so the old anchor never learns it lost a
     * unit. location_id is deliberately excluded — see ServiceUnit's own
     * docblock on $auditInclude for why that one is a legal transfer.
     */
    public function test_changing_service_id_after_creation_throws(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $serviceA = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $serviceB = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $serviceA->id, 'location_id' => $location->id,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Field 'service_id' is immutable on ServiceUnit");

        $unit->update(['service_id' => $serviceB->id]);
    }

    public function test_changing_organization_id_after_creation_throws(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($orgA, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $orgA->id]);
        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $orgA->id, 'service_id' => $service->id, 'location_id' => $location->id,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Field 'organization_id' is immutable on ServiceUnit");

        $unit->update(['organization_id' => $orgB->id]);
    }

    /**
     * location_id is a legal transfer, NOT guarded by the immutable-field
     * check above — proven here so a future edit to the guard's field list
     * fails loudly if it accidentally starts blocking transfers too.
     */
    public function test_changing_location_id_after_creation_is_still_allowed(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $locationA = Location::factory()->for($org, 'organization')->create();
        $locationB = Location::factory()->for($org, 'organization')->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $unit = ServiceUnit::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id, 'service_id' => $service->id, 'location_id' => $locationA->id,
        ]);

        $unit->update(['location_id' => $locationB->id]);

        $this->assertSame($locationB->id, $unit->fresh()->location_id);
    }
}
