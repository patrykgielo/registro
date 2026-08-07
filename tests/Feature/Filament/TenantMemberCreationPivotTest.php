<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * canAccessTenant() reads organization_user and nothing else, so an account
 * created without that pivot row cannot reach /admin — ResolveTenant bounces it
 * — and is invisible to UserResource's own tenant-scoped query.
 *
 * CreateEmployee shipped with exactly that gap while already being open to
 * tenant admins: assignRole('staff') ran, the pivot never did, and the resulting
 * employee looked created but could not log in. Both create pages now write it.
 */
class TenantMemberCreationPivotTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Organization::factory()->create();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->organizations()->attach($this->tenant->id, ['role' => 'owner']);

        // Branch 3 of TenantFeature::currentTenant(): ResolveTenant writes this on
        // every full page load, and it is what Livewire update requests read.
        session(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin);
    }

    public function test_creating_an_employee_attaches_them_to_the_current_tenant(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'email' => 'anna@example.test',
                'password' => 'sekretne-haslo-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $employee = User::where('email', 'anna@example.test')->firstOrFail();

        $this->assertTrue($employee->hasRole('staff'));
        $this->assertTrue(
            $employee->canAccessTenant($this->tenant),
            'Employee was created without the organization_user pivot and cannot reach the panel.'
        );
    }

    public function test_creating_a_user_attaches_them_to_the_current_tenant(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Piotr',
                'last_name' => 'Kowalski',
                'email' => 'piotr@example.test',
                'password' => 'sekretne-haslo-123',
                'password_confirmation' => 'sekretne-haslo-123',
                'send_setup_email' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'piotr@example.test')->firstOrFail();

        $this->assertTrue($created->canAccessTenant($this->tenant));
    }

    public function test_a_tenant_admin_may_create_users(): void
    {
        $this->assertTrue(\App\Filament\Resources\UserResource::canCreate());
    }
}
