<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stack-per-tenant mode: config('app.tenant_slug') (TENANT_SLUG) set. Read
 * inside ResolveTenant::handle() at request time (unlike the route/panel
 * gating in routes/web.php and bootstrap/providers.php, which needs the real
 * process environment set BEFORE boot -- see TenantSlugGatingTest's docblock),
 * so config() overrides here are enough; no putenv()/tearDown() dance needed.
 */
class ResolveTenantPinnedTest extends TestCase
{
    use RefreshDatabase;

    private ResolveTenant $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ResolveTenant;
        Cache::flush();
        config(['app.domain' => 'registro.local']);
    }

    private function requestFor(string $host): Request
    {
        $request = Request::create("https://{$host}/");
        $request->headers->set('HOST', $host);

        return $request;
    }

    public function test_unset_tenant_slug_ignores_pinning_entirely(): void
    {
        // Baseline / non-negotiable constraint 1: the shared-stack (TENANT_SLUG
        // unset) path must be untouched. config('app.tenant_hosts') set to
        // something that would 404 in pinned mode proves it is never consulted
        // when tenant_slug is blank -- the request falls through to the
        // ordinary Host-derived branch and resolves the subdomain tenant.
        config(['app.tenant_slug' => null, 'app.tenant_hosts' => ['nowhere.example']]);

        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Shared Stack Salon',
            'slug' => 'demo',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $response = $this->middleware->handle(
            $this->requestFor('demo.registro.local'),
            fn ($req) => response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_pinned_slug_resolves_regardless_of_host_when_host_is_allowed(): void
    {
        config(['app.tenant_slug' => 'acme', 'app.tenant_hosts' => ['acme.pl', 'www.acme.pl']]);

        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Acme Rentals',
            'slug' => 'acme',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        // Neither host looks anything like config('app.domain') -- proves
        // resolution came from TENANT_SLUG, not from Host-suffix derivation.
        foreach (['acme.pl', 'www.acme.pl'] as $host) {
            $request = $this->requestFor($host);
            $response = $this->middleware->handle($request, fn ($req) => response('ok'));

            $this->assertSame(200, $response->getStatusCode(), "host {$host} should resolve");
            $this->assertSame($org->id, $request->attributes->get('tenant')->id);
        }
    }

    public function test_host_outside_tenant_hosts_is_404_even_though_slug_resolves(): void
    {
        // The headline requirement: pinning alone would make the stack answer
        // 200 to ANY Host that reaches it. TENANT_HOSTS is the independent
        // check that stops that -- the org exists and is Active, yet an
        // unlisted Host must still fail closed.
        config(['app.tenant_slug' => 'acme', 'app.tenant_hosts' => ['acme.pl']]);

        $owner = User::factory()->create();
        Organization::create([
            'name' => 'Acme Rentals',
            'slug' => 'acme',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->middleware->handle(
            $this->requestFor('not-acme.example'),
            fn ($req) => response('ok')
        );
    }

    public function test_empty_tenant_hosts_denies_every_host(): void
    {
        // Fail-closed default: TENANT_SLUG set, TENANT_HOSTS forgotten/unset.
        config(['app.tenant_slug' => 'acme', 'app.tenant_hosts' => []]);

        $owner = User::factory()->create();
        Organization::create([
            'name' => 'Acme Rentals',
            'slug' => 'acme',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        // Even the organization's own, otherwise-legitimate host is denied.
        $this->middleware->handle(
            $this->requestFor('acme.pl'),
            fn ($req) => response('ok')
        );
    }

    public function test_allowed_host_but_unresolved_slug_is_404(): void
    {
        // Host passes the allowlist, but no Active organization has this slug
        // (never provisioned, or provisioning hasn't run yet) -- fails closed,
        // not a redirect (there is no marketplace root on a pinned stack).
        config(['app.tenant_slug' => 'not-provisioned-yet', 'app.tenant_hosts' => ['acme.pl']]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->middleware->handle(
            $this->requestFor('acme.pl'),
            fn ($req) => response('ok')
        );
    }

    public function test_staff_outside_the_pinned_tenant_is_denied_admin_access(): void
    {
        config(['app.tenant_slug' => 'acme', 'app.tenant_hosts' => ['acme.pl']]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $owner = User::factory()->create();
        Organization::create([
            'name' => 'Acme Rentals',
            'slug' => 'acme',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        // A different organization's admin, with no pivot row on "acme".
        $otherOwner = User::factory()->create();
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other',
            'booking_type' => 'time_slot',
            'owner_id' => $otherOwner->id,
        ]);
        $foreignAdmin = User::factory()->create();
        $foreignAdmin->assignRole('admin');
        $foreignAdmin->organizations()->attach($otherOrg->id, ['role' => 'owner']);

        $this->actingAs($foreignAdmin);

        $request = Request::create('https://acme.pl/admin');
        $request->headers->set('HOST', 'acme.pl');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->middleware->handle($request, fn ($req) => response('ok'));
    }
}
