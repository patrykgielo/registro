<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms Location gets tenant isolation for free from BelongsToOrganization
 * (plan-wdrozenia.md's "Legacy do ponownego użycia" table) — a tenant with a
 * resolved context never sees another organization's locations, and
 * `organization_id` is auto-assigned on create when it isn't passed
 * explicitly.
 */
class LocationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_a_cannot_see_tenant_bs_locations(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        Location::factory()->for($orgA, 'organization')->create(['name' => 'Warszawa']);
        Location::factory()->for($orgB, 'organization')->create(['name' => 'Gdańsk']);

        $this->app['request']->attributes->set('tenant', $orgA);

        $visible = Location::all();

        $this->assertCount(1, $visible);
        $this->assertSame('Warszawa', $visible->first()->name);
    }

    public function test_organization_id_is_auto_assigned_from_resolved_tenant_when_omitted(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $location = Location::create([
            'name' => 'Siedziba',
            'street' => 'Testowa 1',
        ]);

        $this->assertSame($org->id, $location->organization_id);
    }

    /**
     * A bare query/create with no HTTP request in flight (this test's own
     * setUp, before any $this->get()) keeps today's permissive no-op —
     * same VULN-003 Layer 2 contract every other BelongsToOrganization model
     * has (see BelongsToOrganizationFailClosedTest's
     * test_bare_query_without_any_http_request_is_unaffected()).
     */
    public function test_bare_query_without_any_resolved_tenant_is_unaffected(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        Location::factory()->for($orgA, 'organization')->create();
        Location::factory()->for($orgB, 'organization')->create();

        $this->assertSame(2, Location::count());
    }
}
