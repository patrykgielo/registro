<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Models\User;
use App\Rules\ProtectedRoleName;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Neighboring vector to the UserResource escalation guard: RoleResource is
 * super-admin-only today, but is expected to open to 'admin' in the same
 * follow-up PR as UserResource. Without this, a tenant admin could bypass
 * AssignableRole entirely — rename any role they already hold to
 * "super-admin" (Spatie resolves roles purely by name, so the rename alone
 * grants it to every user already holding that role), or create a fresh
 * role with that name. Fixed by App\Rules\ProtectedRoleName on RoleResource's
 * 'name' field, reusing the same RoleAssignmentGuard used by UserResource.
 *
 * Same constraint as UserRoleEscalationGuardTest: RoleResource::canViewAny()
 * gates ALL of its pages (including Create/Edit) via Filament's
 * mountCanAuthorizeResourceAccess() hook, which fires during component mount
 * itself — so a non-super-admin cannot drive CreateRole/EditRole through
 * Livewire::test() (or any other request) until canViewAny() opens up. The
 * negative cases call ProtectedRoleName::validate() directly — the exact
 * object attached to the 'name' field — so they need no rewrite once that
 * gate opens.
 */
class RoleResourceEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_non_super_admin_cannot_name_a_role_super_admin_via_the_name_field_validation_rule(): void
    {
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $this->actingAs($tenantAdmin);

        $failed = null;
        (new ProtectedRoleName)->validate('name', 'super-admin', function (string $message) use (&$failed) {
            $failed = $message;
        });

        $this->assertNotNull($failed, 'Expected ProtectedRoleName to reject a non-super-admin naming a role "super-admin".');
    }

    public function test_non_super_admin_can_name_a_role_anything_else_via_the_name_field_validation_rule(): void
    {
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $this->actingAs($tenantAdmin);

        $failed = null;
        (new ProtectedRoleName)->validate('name', 'regional-manager', function (string $message) use (&$failed) {
            $failed = $message;
        });

        $this->assertNull($failed);
    }

    public function test_super_admin_can_still_manage_the_super_admin_role_name(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $this->actingAs($superAdmin);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'super-admin-2', 'guard_name' => 'web'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('roles', ['name' => 'super-admin-2']);
    }
}
