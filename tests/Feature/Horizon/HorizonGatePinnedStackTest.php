<?php

declare(strict_types=1);

namespace Tests\Feature\Horizon;

use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Horizon on a DEDICATED tenant stack (TENANT_SLUG set).
 *
 * The provider cannot be dropped there (the same image runs the queue worker), so /horizon exists,
 * and a super-admin-only gate made it a 403 for everyone: there is no super-admin on such a
 * stack. The tenant's own admin gets in; staff and customers still do not.
 *
 * TENANT_SLUG has to be in the process environment BEFORE parent::setUp() — same mechanism as
 * TenantSlugGatingTest / SuperAdminPasswordResetPinnedStackTest.
 */
class HorizonGatePinnedStackTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://acme.pl';

    protected function setUp(): void
    {
        putenv('TENANT_SLUG=acme');
        $_ENV['TENANT_SLUG'] = 'acme';
        $_SERVER['TENANT_SLUG'] = 'acme';

        parent::setUp();

        // A dedicated stack resolves its one tenant from TENANT_HOSTS; without it every route 404s.
        config(['app.tenant_hosts' => ['acme.pl']]);
        Cache::flush();

        Organization::create([
            'name' => 'Acme Rentals',
            'slug' => 'acme',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('TENANT_SLUG');
        unset($_ENV['TENANT_SLUG'], $_SERVER['TENANT_SLUG']);

        parent::tearDown();
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_the_fixture_is_really_a_dedicated_stack(): void
    {
        // If this fails the tests below prove nothing: they would be running the shared-stack path.
        $this->assertSame('acme', config('app.tenant_slug'));
        $this->assertArrayNotHasKey('platform', Filament::getPanels());
    }

    public function test_the_tenant_admin_may_view_horizon(): void
    {
        $this->assertTrue(Gate::forUser($this->userWith('admin'))->allows('viewHorizon'));
    }

    public function test_super_admin_may_still_view_horizon(): void
    {
        $this->assertTrue(Gate::forUser($this->userWith('super-admin'))->allows('viewHorizon'));
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

    public function test_the_horizon_route_exists_and_lets_the_admin_in(): void
    {
        $this->actingAs($this->userWith('admin'))
            ->get(self::HOST.'/horizon')
            ->assertSuccessful();
    }

    public function test_the_horizon_route_still_refuses_staff(): void
    {
        $this->actingAs($this->userWith('staff'))->get(self::HOST.'/horizon')->assertForbidden();
    }
}
