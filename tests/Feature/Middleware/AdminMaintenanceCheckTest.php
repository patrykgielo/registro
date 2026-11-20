<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\MaintenanceType;
use App\Http\Middleware\AdminMaintenanceCheck;
use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * AdminMaintenanceCheck on the SHARED stack (no TENANT_SLUG): `super-admin` only, as it always was.
 *
 * The maintenance flag is one global cache key, so on a shared stack an operator-wide maintenance
 * window must not be overridable by any tenant's admin. The dedicated-stack counterpart, where the
 * tenant admin must be let back in, is AdminMaintenanceCheckPinnedStackTest.
 */
class AdminMaintenanceCheckTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->create();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->organizations()->attach($this->org->id, ['role' => $role]);

        return $user;
    }

    private function maintenance(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    private function runMiddleware(?User $user): Response
    {
        if ($user !== null) {
            $this->actingAs($user);
        }

        return app(AdminMaintenanceCheck::class)->handle(
            Request::create('/admin'),
            fn () => new Response('panel')
        );
    }

    private function bindTenant(Organization $org): void
    {
        $this->app->bind(ResolveTenant::class, fn () => new class($org)
        {
            public function __construct(private Organization $org) {}

            public function handle($request, $next)
            {
                $request->attributes->set('tenant', $this->org);

                return $next($request);
            }
        });
    }

    public function test_the_fixture_is_the_shared_stack(): void
    {
        $this->assertEmpty(config('app.tenant_slug'));
    }

    public function test_it_is_a_no_op_while_maintenance_is_off(): void
    {
        $this->assertSame('panel', $this->runMiddleware($this->user('staff'))->getContent());
        $this->assertSame('panel', $this->runMiddleware($this->user('admin'))->getContent());
    }

    public function test_super_admin_keeps_panel_access_in_every_maintenance_type(): void
    {
        foreach (MaintenanceType::cases() as $type) {
            $this->maintenance()->enable($type);

            $this->assertSame(
                'panel',
                $this->runMiddleware($this->user('super-admin'))->getContent(),
                "super-admin was locked out during {$type->value}"
            );
        }
    }

    public function test_a_tenant_admin_is_blocked_on_the_shared_stack(): void
    {
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $response = $this->runMiddleware($this->user('admin'));

        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_staff_get_the_maintenance_page(): void
    {
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $response = $this->runMiddleware($this->user('staff'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('Panel w trybie konserwacji', (string) $response->getContent());
    }

    public function test_guests_pass_through_to_the_login_page(): void
    {
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $this->assertSame('panel', $this->runMiddleware(null)->getContent());
    }

    /**
     * Through the real panel stack: what a blocked user actually receives is the 503 page, not an
     * exception (the page used to throw "Using $this when not in object context").
     */
    public function test_a_blocked_staff_member_gets_a_real_503_page_from_the_panel(): void
    {
        $staff = $this->user('staff');
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $this->bindTenant($this->org);

        $this->actingAs($staff)
            ->get('http://registro.local/admin')
            ->assertStatus(503)
            ->assertSee('Panel w trybie konserwacji')
            ->assertSee($staff->email);
    }

    public function test_a_tenant_admin_gets_the_same_503_page_on_the_shared_stack(): void
    {
        $admin = $this->user('admin');
        $this->maintenance()->enable(MaintenanceType::EMERGENCY);

        $this->bindTenant($this->org);

        $this->actingAs($admin)
            ->get('http://registro.local/admin')
            ->assertStatus(503)
            ->assertSee('Panel w trybie konserwacji');
    }

    public function test_super_admin_still_reaches_the_real_panel_during_maintenance(): void
    {
        $super = $this->user('super-admin');
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $this->bindTenant($this->org);

        $this->actingAs($super)
            ->get('http://registro.local/admin/maintenance-settings')
            ->assertOk();
    }
}
