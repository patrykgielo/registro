<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LocationResource adds no manual tenant scoping of its own — unlike
 * UserResource/EmployeeResource (which need one because `users` has no
 * organization_id column), Location gets isolation for free from
 * BelongsToOrganization's global scope. This is the "list table" companion
 * to Unit\Models\LocationTenantIsolationTest, which already pins the model
 * layer directly — this test proves the SAME thing holds through the full
 * Filament resource wiring (getEloquentQuery() is never overridden here).
 */
class LocationResourceTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_admin_only_sees_their_own_organizations_locations_on_the_list(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $visible = Location::factory()->for($orgA, 'organization')->create(['name' => 'Warszawa Centrala']);
        $hidden = Location::factory()->for($orgB, 'organization')->create(['name' => 'Gdańsk Oddział']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($orgA->id, ['role' => 'admin']);

        session(['tenant_id' => $orgA->id]);
        $this->actingAs($admin);

        Livewire::test(ListLocations::class)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden]);
    }
}
