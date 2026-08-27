<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LocationResource extends BaseResource without its own can*() would inherit
 * deny-by-default canViewAny() — same trap ServiceAreaResource hit before its
 * fix (see ServiceAreaResourceAuthorizationTest). Confirms the explicit
 * admin/super-admin override keeps the resource reachable, and that staff
 * (who has no business managing branches) stays out.
 */
class LocationResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Organization::factory()->equipmentRental()->create();
        session(['tenant_id' => $this->tenant->id]);
    }

    public function test_admin_can_view_and_edit_locations(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);

        $location = Location::factory()->for($this->tenant, 'organization')->create();

        $this->actingAs($admin);

        $this->assertTrue(LocationResource::canViewAny());
        $this->assertTrue(LocationResource::canEdit($location));

        // Location's route key is `slug`, not `id` (see Location::getRouteKeyName()).
        Livewire::test(EditLocation::class, ['record' => $location->slug])
            ->assertOk();
    }

    public function test_staff_cannot_view_locations(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $staff->organizations()->attach($this->tenant->id, ['role' => 'staff']);

        $location = Location::factory()->for($this->tenant, 'organization')->create();

        $this->actingAs($staff);

        $this->assertFalse(LocationResource::canViewAny());
        $this->assertFalse(LocationResource::canEdit($location));
    }

    public function test_super_admin_can_view_and_edit_locations(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $location = Location::factory()->for($this->tenant, 'organization')->create();

        $this->actingAs($superAdmin);

        $this->assertTrue(LocationResource::canViewAny());
        $this->assertTrue(LocationResource::canEdit($location));
    }

    public function test_guest_cannot_view_locations(): void
    {
        $this->assertFalse(LocationResource::canViewAny());
    }
}
