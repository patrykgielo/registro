<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\RoleAssignmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression guard for the escalation path fixed by
 * feature/user-role-escalation-guard: RoleAssignmentGuard is the single
 * source of truth consumed by UserResource's role picker, App\Rules\AssignableRole
 * and App\Rules\ProtectedRoleName. If this class's logic regresses, all three
 * call sites regress together — which is the point.
 */
class RoleAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    public function test_non_super_admin_cannot_grant_super_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertFalse(RoleAssignmentGuard::canGrant('super-admin', $admin));
    }

    public function test_super_admin_can_grant_super_admin(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->assertTrue(RoleAssignmentGuard::canGrant('super-admin', $superAdmin));
    }

    public function test_anyone_can_grant_non_protected_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue(RoleAssignmentGuard::canGrant('staff', $admin));
        $this->assertTrue(RoleAssignmentGuard::canGrant('customer', $admin));
    }

    public function test_guest_cannot_grant_super_admin(): void
    {
        $this->assertFalse(RoleAssignmentGuard::canGrant('super-admin', null));
    }

    public function test_assignable_roles_query_excludes_super_admin_for_non_super_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $names = RoleAssignmentGuard::assignableRolesQuery($admin)->pluck('name')->all();

        $this->assertNotContains('super-admin', $names);
        $this->assertContains('admin', $names);
        $this->assertContains('staff', $names);
    }

    public function test_assignable_roles_query_includes_super_admin_for_super_admin(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $names = RoleAssignmentGuard::assignableRolesQuery($superAdmin)->pluck('name')->all();

        $this->assertContains('super-admin', $names);
    }

    /**
     * These variants are rejected on MySQL by the collation on `roles.name`, not
     * by any decision of ours — and that collation does not exist under SQLite,
     * which is what this suite runs on. Without normalisation in the guard the
     * suite would pass while production leaned on a schema accident.
     */
    public function test_protected_name_matching_survives_case_and_whitespace(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach (['Super-Admin', 'SUPER-ADMIN', ' super-admin', 'super-admin ', '  Super-Admin  '] as $variant) {
            $this->assertTrue(RoleAssignmentGuard::isProtectedName($variant), $variant);
            $this->assertFalse(RoleAssignmentGuard::canGrant($variant, $admin), $variant);
        }
    }

    public function test_unrelated_names_stay_grantable(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach (['superadmin', 'super_admin', 'super-admins', 'admin'] as $name) {
            $this->assertFalse(RoleAssignmentGuard::isProtectedName($name), $name);
            $this->assertTrue(RoleAssignmentGuard::canGrant($name, $admin), $name);
        }
    }
}
