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
 * Why the negative cases below call AssignableRole::validate() directly
 * instead of driving CreateUser/EditUser through Livewire::test(): Filament
 * gates EVERY resource page mount — not just canCreate()/canEdit() — behind
 * the resource's canViewAny(), via Concerns\CanAuthorizeResourceAccess's
 * mountCanAuthorizeResourceAccess() hook (abort_unless(Resource::canAccess(),
 * 403), where canAccess() === canViewAny()). This runs during component
 * mount itself (not HTTP middleware), so it fires under Livewire::test() too.
 * UserResource::canViewAny() is super-admin-only today, so there is no live
 * request path — Livewire or otherwise — a non-super-admin can drive against
 * these pages until the follow-up PR opens canViewAny() to 'admin'.
 *
 * AssignableRole is the exact object Filament attaches to the 'roles' field
 * and that $this->form->getState() invokes on every create()/save() call
 * (Filament's relationship Select is dehydrated(false) when multiple(), so
 * mutateFormDataBeforeSave() never even sees the submitted role IDs — this
 * rule is the only thing that actually runs). Calling it directly, with the
 * real authenticated non-super-admin session, exercises the identical
 * server-side check a raw Livewire payload would hit once canViewAny() opens
 * — so these tests need no rewrite when that happens.
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
}
