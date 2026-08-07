<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * canDelete()/canDeleteAny() enforce nothing on their own.
 *
 * Filament asks getDeleteAuthorizationResponse()/getDeleteAnyAuthorizationResponse()
 * when a DeleteAction runs, and with no UserPolicy and strict authorization off
 * those return allow() for everyone. Review caught this by actually deleting a
 * co-admin as a tenant admin while canDelete() was returning false — the guard
 * read correctly and did nothing.
 */
class UserResourceDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private User $admin;

    private User $coAdmin;

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

        $this->coAdmin = User::factory()->create();
        $this->coAdmin->assignRole('admin');
        $this->coAdmin->organizations()->attach($this->tenant->id, ['role' => 'admin']);

        session(['tenant_id' => $this->tenant->id]);
    }

    public function test_the_authorization_response_denies_a_tenant_admin(): void
    {
        $this->actingAs($this->admin);

        $this->assertFalse(UserResource::getDeleteAuthorizationResponse($this->coAdmin)->allowed());
        $this->assertFalse(UserResource::getDeleteAnyAuthorizationResponse()->allowed());
    }

    public function test_a_tenant_admin_cannot_actually_delete_a_co_admin(): void
    {
        $this->actingAs($this->admin);

        // Drive the action itself, the way review did when it proved the record
        // really was removed. Whether Filament refuses by hiding it, by denying
        // authorization, or by throwing does not matter here — what matters is
        // that the row is still there afterwards.
        try {
            Livewire::test(EditUser::class, ['record' => $this->coAdmin->getKey()])
                ->callAction('delete');
        } catch (\Throwable) {
            // Refusal is the expected outcome; the assertion below is the contract.
        }

        $this->assertDatabaseHas('users', ['id' => $this->coAdmin->id]);
    }

    public function test_a_super_admin_is_still_allowed_to_delete(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('super-admin');

        $this->actingAs($operator);

        $this->assertTrue(UserResource::getDeleteAuthorizationResponse($this->coAdmin)->allowed());
        $this->assertTrue(UserResource::getDeleteAnyAuthorizationResponse()->allowed());
    }
}
