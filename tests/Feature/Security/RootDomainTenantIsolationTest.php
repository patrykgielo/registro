<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression test for the root-domain tenant isolation hotfix.
 *
 * Prior to RequireTenant, ResolveTenant let the bare root domain (no subdomain)
 * pass through with no `tenant` request attribute. Every route below queries a
 * BelongsToOrganization model — without a resolved tenant, the global scope was
 * a silent no-op and these routes served completely unscoped, cross-tenant data
 * to anonymous visitors. RequireTenant now hard-404s any of these routes when
 * no tenant is resolved.
 */
class RootDomainTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * The home route is a deliberate exception (hotfix 2026-07-03): it does NOT
     * carry RequireTenant, because it already has graceful no-tenant fallback
     * behavior (home-fallback view) and Layer 2 (BelongsToOrganization fail-closed
     * scope) makes Page::find() safe without it. See
     * tests/Feature/HomeRouteRootDomainTest.php for full coverage.
     */
    public function test_home_returns_ok_with_fallback_view_on_root_domain(): void
    {
        $this->get('http://registro.local/')
            ->assertOk()
            ->assertSee('Strona w przygotowaniu');
    }

    public function test_post_show_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/aktualnosci/jakis-post')
            ->assertNotFound();
    }

    public function test_service_index_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/uslugi')
            ->assertNotFound();
    }

    public function test_rental_index_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/wypozyczalnia')
            ->assertNotFound();
    }

    public function test_cms_page_catch_all_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/o-nas')
            ->assertNotFound();
    }

    public function test_admin_login_returns_404_on_root_domain(): void
    {
        // Admin panel shares ResolveTenant for tenant context (no native Filament
        // tenancy configured). On the root domain there is no tenant to resolve,
        // so RequireTenant must reject this before Filament's own auth check runs.
        $this->get('http://registro.local/admin/login')
            ->assertNotFound();
    }

    /**
     * VULN-003 gap #1 regression: RequireTenant MUST gate on the `tenant` request
     * attribute (set fresh, per-request, by ResolveTenant based on the CURRENT
     * request's Host header) — NOT on TenantFeature::currentTenant(), which has
     * a 3rd fallback branch reading session('tenant_id'). ResolveTenant writes
     * that session key on EVERY successful subdomain resolution — including for
     * anonymous, unauthenticated visitors — and BEFORE the canAccessTenant()
     * staff-authorization check (which only runs on the subdomain branch, never
     * on the root-domain branch). A stale session tenant_id from ordinary public
     * browsing must NOT be able to smuggle a tenant into a root-domain request.
     *
     * Unauthenticated case: Laravel's global $middlewarePriority list forces
     * Filament's Authenticate (AuthenticatesRequests) to run before our custom,
     * unprioritized ResolveTenant/RequireTenant — so a guest hits the login
     * redirect first. That's not a data leak (no tenant data is rendered to a
     * guest); the important thing is that the redirect target itself is
     * ALSO root-domain and ALSO gated by RequireTenant (proven by
     * test_admin_login_returns_404_on_root_domain), so the round trip still
     * terminates safely. This test asserts both halves explicitly.
     */
    public function test_admin_route_on_root_domain_ignores_stale_session_tenant_id_when_unauthenticated(): void
    {
        $orgB = Organization::factory()->create();

        // Simulate the session state left behind by ResolveTenant after a
        // completely unauthenticated visit to orgB's subdomain (no login,
        // no canAccessTenant() check involved at all).
        $response = $this->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/admin/analityka');

        $response->assertRedirect(route('filament.admin.auth.login'));

        // Following the redirect (still root domain, still stale session) must
        // NOT resolve orgB either — the login page itself 404s.
        $this->get(route('filament.admin.auth.login'))->assertNotFound();
    }

    /**
     * The actual attack scenario from the report: an AUTHENTICATED staff user
     * (valid credentials for orgA only) with a stale session tenant_id for
     * orgB must be rejected outright on the root domain — must NOT fall
     * through to render orgB's unfiltered data. Authenticate passes (they
     * ARE logged in) so this exercises RequireTenant for real, after auth.
     */
    public function test_admin_route_on_root_domain_ignores_stale_session_tenant_id_for_authenticated_staff(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $staff = User::factory()->create();
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->assignRole($staffRole);
        $staff->organizations()->attach($orgA->id);

        // Staff is authorized for orgA only, but their session carries a stale
        // tenant_id for orgB (e.g. from browsing orgB's public site earlier).
        // Root-domain admin access must still be rejected outright — it must
        // NOT fall through and render orgB's unfiltered data.
        $response = $this->actingAs($staff)
            ->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/admin/analityka');

        $response->assertNotFound();
    }

    /**
     * VULN-003 doc correction (2026-07-05): the doc's Follow-ups section previously
     * claimed there is no in-app navigation path from the root domain to /login —
     * that premise was wrong (see resources/views/components/nav/header.blade.php's
     * "Zaloguj" link, rendered on the root-domain home-fallback view). This test
     * asserts the actual load-bearing safety property: a customer authenticating
     * via a root-domain /login still cannot reach cross-tenant data — the redirect
     * target (appointments.index) is protected by RequireTenant (Layer 3) and 404s.
     */
    public function test_customer_login_from_root_domain_redirects_to_404_not_cross_tenant_leak(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $response = $this->post('http://registro.local/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('appointments.index'));
        $this->assertAuthenticatedAs($user);

        // Following the redirect on the root domain must 404, not leak any
        // tenant's appointment data.
        $this->get(route('appointments.index'))->assertNotFound();
    }
}
