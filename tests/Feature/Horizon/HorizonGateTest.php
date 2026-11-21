<?php

declare(strict_types=1);

namespace Tests\Feature\Horizon;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Shared stack (no TENANT_SLUG): `viewHorizon` is super-admin only, as it always was.
 * The pinned counterpart is HorizonGatePinnedStackTest.
 */
class HorizonGateTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_the_fixture_is_the_shared_stack(): void
    {
        $this->assertEmpty(config('app.tenant_slug'));
    }

    public function test_super_admin_may_view_horizon(): void
    {
        $this->assertTrue(Gate::forUser($this->userWith('super-admin'))->allows('viewHorizon'));
    }

    public function test_tenant_admin_may_not_view_horizon_on_the_shared_stack(): void
    {
        $this->assertFalse(Gate::forUser($this->userWith('admin'))->allows('viewHorizon'));
    }

    public function test_staff_and_customers_may_not_view_horizon(): void
    {
        $this->assertFalse(Gate::forUser($this->userWith('staff'))->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($this->userWith('customer'))->allows('viewHorizon'));
    }

    public function test_a_guest_may_not_view_horizon(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    public function test_the_horizon_route_denies_a_tenant_admin_on_the_shared_stack(): void
    {
        $this->actingAs($this->userWith('admin'))->get('/horizon')->assertForbidden();
    }
}
