<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceAreas\Pages\EditServiceArea;
use App\Filament\Resources\ServiceAreas\ServiceAreaResource;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ServiceAreaResource had no can*() overrides at all before this fix. Under
 * BaseResource's new deny-by-default canViewAny(), that would have locked
 * everyone — including super-admin — out of a page real tenants use to
 * configure their delivery/mobile-service radius. Confirms the explicit
 * admin/super-admin override added alongside BaseResource keeps it working,
 * and that staff (who has no business here) stays out.
 */
class ServiceAreaResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Organization::factory()->create();
        session(['tenant_id' => $this->tenant->id]);
    }

    public function test_admin_can_view_and_edit_service_areas(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);

        $area = ServiceArea::factory()->create(['organization_id' => $this->tenant->id]);

        $this->actingAs($admin);

        $this->assertTrue(ServiceAreaResource::canViewAny());
        $this->assertTrue(ServiceAreaResource::canEdit($area));

        Livewire::test(EditServiceArea::class, ['record' => $area->getKey()])
            ->assertOk();
    }

    public function test_staff_cannot_view_service_areas(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $staff->organizations()->attach($this->tenant->id, ['role' => 'staff']);

        $area = ServiceArea::factory()->create(['organization_id' => $this->tenant->id]);

        $this->actingAs($staff);

        $this->assertFalse(ServiceAreaResource::canViewAny());
        $this->assertFalse(ServiceAreaResource::canEdit($area));
    }

    public function test_super_admin_can_view_and_edit_service_areas(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $area = ServiceArea::factory()->create(['organization_id' => $this->tenant->id]);

        $this->actingAs($superAdmin);

        $this->assertTrue(ServiceAreaResource::canViewAny());
        $this->assertTrue(ServiceAreaResource::canEdit($area));
    }

    public function test_guest_cannot_view_service_areas(): void
    {
        $this->assertFalse(ServiceAreaResource::canViewAny());
    }
}
