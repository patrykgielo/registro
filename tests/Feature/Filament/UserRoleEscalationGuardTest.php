<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Rules\AssignableRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression guard for the "two clicks, zero exploit" escalation path: a
 * tenant admin adding themselves (or anyone) to super-admin through
 * UserResource's role picker, then reaching the /platform panel. Fixed by
 * App\Support\RoleAssignmentGuard + App\Rules\AssignableRole, attached
 * directly to the 'roles' Select field.
 *
 * UserResource::canViewAny() and canCreate() opened to 'admin' in
 * feature/tenant-admin-access (2026-08-07) — see
 * app/docs/security/patterns/role-escalation-guard.md. Creating a second admin
 * is the point of unlocking this resource, since CreateEmployee only ever mints
 * `staff`; the missing organization_user attach that briefly argued for keeping
 * creation closed was fixed instead, in both create pages.
 * The ValidationRule-direct cases below predate the canViewAny() change
 * and were kept: AssignableRole is the exact object Filament attaches to the
 * 'roles' field and that $this->form->getState() invokes on every
 * create()/save() call (Filament's relationship Select is dehydrated(false)
 * when multiple(), so mutateFormDataBeforeSave() never even sees the
 * submitted role IDs — this rule is the only thing that actually runs), so
 * they remain a valid, fast unit-level check of the rule itself. The
 * full-Livewire-path test below additionally exercises the real EditUser
 * component end to end now that the page is actually reachable by a tenant
 * admin — this was previously impossible: Filament gates EVERY resource page
 * mount behind canViewAny() via Concerns\CanAuthorizeResourceAccess's
 * mountCanAuthorizeResourceAccess() hook, which fires during component mount
 * itself, so Livewire::test() 403'd before canViewAny() opened.
 */
class UserRoleEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function newUserFormData(string $email, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => $email,
            'send_setup_email' => false,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_tenant_admin_cannot_self_escalate_via_the_roles_field_validation_rule(): void
    {
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $this->actingAs($tenantAdmin);

        $superAdminRoleId = Role::where('name', 'super-admin')->value('id');

        $failed = null;
        (new AssignableRole)->validate('roles', [$superAdminRoleId], function (string $message) use (&$failed) {
            $failed = $message;
        });

        $this->assertNotNull($failed, 'Expected AssignableRole to reject a super-admin grant attempted by a non-super-admin.');
    }

    public function test_tenant_admin_can_grant_non_protected_roles_via_the_roles_field_validation_rule(): void
    {
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $this->actingAs($tenantAdmin);

        $staffRoleId = Role::where('name', 'staff')->value('id');

        $failed = null;
        (new AssignableRole)->validate('roles', [$staffRoleId], function (string $message) use (&$failed) {
            $failed = $message;
        });

        $this->assertNull($failed);
    }

    public function test_super_admin_can_still_grant_super_admin_via_create(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $this->actingAs($superAdmin);

        $superAdminRoleId = Role::where('name', 'super-admin')->value('id');
        $email = 'new-operator@example.com';

        Livewire::test(CreateUser::class)
            ->fillForm($this->newUserFormData($email, ['roles' => [$superAdminRoleId]]))
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', $email)->firstOrFail();
        $this->assertTrue($created->hasRole('super-admin'));
    }

    public function test_super_admin_can_still_grant_super_admin_via_edit(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        $this->actingAs($superAdmin);

        $target = User::factory()->create();
        $target->assignRole('staff');

        $superAdminRoleId = Role::where('name', 'super-admin')->value('id');

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'first_name' => $target->first_name,
                'last_name' => $target->last_name,
                'email' => $target->email,
                'send_setup_email' => true,
                'roles' => [$superAdminRoleId],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($target->fresh()->hasRole('super-admin'));
    }

    /**
     * Creating a user IS open to tenant admins — that is the point of unlocking
     * this resource, since EmployeeResource only ever mints `staff`.
     *
     * An earlier pass closed it because the generic create form never attached
     * organization_user. That reasoning was right about the defect and wrong
     * about the remedy: CreateUser::afterCreate() now writes the pivot (and so
     * does CreateEmployee, which had shipped the same gap while already open to
     * tenant admins, quietly producing employees who could not log in).
     *
     * Reaching the page is not the same as being able to escalate on it — the
     * roles field still carries AssignableRole, pinned by the tests above.
     */
    public function test_tenant_admin_can_reach_the_create_user_page(): void
    {
        config(['app.domain' => 'registro.local']);

        $org = \App\Models\Organization::factory()->create(['slug' => 'org-can-create-user']);
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $tenantAdmin->organizations()->attach($org->id);

        $response = $this->actingAs($tenantAdmin)
            ->get("http://{$org->slug}.registro.local/admin/users/create");

        $response->assertOk();
    }

    /**
     * Mirror of the create-flow test on EditUser: a tenant admin editing a
     * colleague within their own scoped query must not be able to grant
     * super-admin through the real component either.
     */
    public function test_tenant_admin_cannot_self_escalate_via_the_full_livewire_edit_flow(): void
    {
        $tenantAdmin = User::factory()->create();
        $tenantAdmin->assignRole('admin');
        $this->actingAs($tenantAdmin);

        $target = User::factory()->create();
        $target->assignRole('staff');

        $superAdminRoleId = Role::where('name', 'super-admin')->value('id');

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'first_name' => $target->first_name,
                'last_name' => $target->last_name,
                'email' => $target->email,
                'send_setup_email' => true,
                'roles' => [$superAdminRoleId],
            ])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertFalse($target->fresh()->hasRole('super-admin'));
    }
}
