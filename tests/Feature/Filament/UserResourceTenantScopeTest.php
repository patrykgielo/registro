<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UserResource::canViewAny() opened to 'admin' in feature/tenant-admin-access
 * (2026-08-07). User carries no BelongsToOrganization (no organization_id
 * column — only the organization_user pivot), so nothing scopes it
 * automatically the way the global scope does for other models;
 * getEloquentQuery() adds a manual `whereHas('organizations', ...)` filter,
 * mirroring EmployeeResource/CustomerResource. This is the "admin tenanta A
 * nie widzi użytkowników tenanta B" regression guard for that fix.
 *
 * Real HTTP requests against the tenant subdomain (not Livewire::test()) are
 * used deliberately: TenantFeature::currentTenant() resolves from
 * ResolveTenant's request attribute / session, neither of which exists in a
 * bare Livewire::test() call — see LivewireAdminTenantIsolationTest for the
 * same pattern applied to a different resource.
 */
class UserResourceTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    public function test_tenant_admin_only_sees_users_belonging_to_their_own_organization(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'org-a-userscope']);
        $orgB = Organization::factory()->create(['slug' => 'org-b-userscope']);

        $adminA = User::factory()->create(['first_name' => 'AdminOrgA', 'last_name' => 'Visible']);
        $adminA->assignRole('admin');
        $adminA->organizations()->attach($orgA->id);

        $staffB = User::factory()->create(['first_name' => 'StaffOrgB', 'last_name' => 'Secret']);
        $staffB->assignRole('staff');
        $staffB->organizations()->attach($orgB->id);

        $response = $this->actingAs($adminA)
            ->get("http://{$orgA->slug}.registro.local/admin/users");

        $response->assertOk();
        $response->assertSee('AdminOrgA');
        $response->assertDontSee('StaffOrgB');
    }

    public function test_super_admin_sees_users_across_every_organization(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'org-a-userscope-sa']);
        $orgB = Organization::factory()->create(['slug' => 'org-b-userscope-sa']);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $adminA = User::factory()->create(['first_name' => 'PlatformSeesA', 'last_name' => 'One']);
        $adminA->assignRole('admin');
        $adminA->organizations()->attach($orgA->id);

        $staffB = User::factory()->create(['first_name' => 'PlatformSeesB', 'last_name' => 'Two']);
        $staffB->assignRole('staff');
        $staffB->organizations()->attach($orgB->id);

        $response = $this->actingAs($superAdmin)
            ->get("http://{$orgA->slug}.registro.local/admin/users");

        $response->assertOk();
        $response->assertSee('PlatformSeesA');
        $response->assertSee('PlatformSeesB');
    }
}
