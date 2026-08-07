<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\RoleAssignmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The mirror of the escalation guard: stripping, not granting.
 *
 * AssignableRole stops a tenant admin adding super-admin to somebody. It does
 * nothing about the opposite direction, and that one needs no attacker at all:
 * the role picker's ->options() omits super-admin, so opening the operator's
 * account as a tenant admin leaves the role out of the submitted form state and
 * saving any unrelated field silently drops it. Recovery is registro:create-owner
 * on the CLI, because /platform requires the role that just disappeared.
 *
 * UserResource::getEloquentQuery() removes those accounts from the query
 * entirely, which closes granting, stripping and accidental edits together.
 */
class UserResourceProtectedAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    public function test_a_tenant_admin_does_not_see_accounts_holding_the_protected_role(): void
    {
        $this->role(RoleAssignmentGuard::PROTECTED_ROLE);

        $operator = User::factory()->create();
        $operator->assignRole(RoleAssignmentGuard::PROTECTED_ROLE);

        $admin = User::factory()->create();
        $admin->assignRole($this->role('admin'));

        $this->actingAs($admin);

        $visible = UserResource::getEloquentQuery()->pluck('id');

        $this->assertContains($admin->id, $visible->all());
        $this->assertNotContains($operator->id, $visible->all());
    }

    public function test_a_super_admin_still_sees_every_account(): void
    {
        $this->role(RoleAssignmentGuard::PROTECTED_ROLE);

        $operator = User::factory()->create();
        $operator->assignRole(RoleAssignmentGuard::PROTECTED_ROLE);

        $other = User::factory()->create();
        $other->assignRole($this->role('admin'));

        $this->actingAs($operator);

        $visible = UserResource::getEloquentQuery()->pluck('id');

        $this->assertContains($operator->id, $visible->all());
        $this->assertContains($other->id, $visible->all());
    }

    public function test_the_scope_fails_closed_without_an_authenticated_actor(): void
    {
        $this->role(RoleAssignmentGuard::PROTECTED_ROLE);

        $operator = User::factory()->create();
        $operator->assignRole(RoleAssignmentGuard::PROTECTED_ROLE);

        $plain = User::factory()->create();

        $visible = UserResource::getEloquentQuery()->pluck('id');

        $this->assertNotContains($operator->id, $visible->all());
        $this->assertContains($plain->id, $visible->all());
    }
}
