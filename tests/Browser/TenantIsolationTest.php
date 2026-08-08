<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Service;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| E2E: Tenant isolation — the most important test in this suite
|--------------------------------------------------------------------------
|
| Guards the exact vulnerability class this project has patched six times
| (VULN-003, Layers 1-6 — app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md):
| a tenant kept in the session/cookie meant it was enough to switch subdomain
| to see another tenant's data. Two scenarios:
|
| 1. Global-scope isolation: BelongsToOrganization (app/Traits/BelongsToOrganization.php)
|    must filter list queries by the resolved tenant — grent's admin must never
|    see qatest's rows, or vice versa.
| 2. Session/cookie isolation: an admin authenticated on grent's subdomain who
|    then visits qatest's subdomain WITH THE SAME COOKIES must be refused —
|    ResolveTenant.php's admin/staff branch (`$user->hasAnyRole([...]) &&
|    !$user->canAccessTenant($tenant)`) redirects to the root domain rather than
|    rendering qatest's panel. This is the actual VULN-003 attack shape.
|
| Both scenarios use Service (ServiceResource, `admin/services`) as the tenant-
| scoped resource: it uses BelongsToOrganization, has a factory, is a core
| resource (no per-tenant module gating blocks it — a plain Organization::factory()
| defaults to booking_type=time_slot, whose module defaults include 'services'),
| and is already reachable/authorized for the 'admin' role with no extra Policy
| wiring (see app/Filament/Resources/ServiceResource.php, App\Filament\Resources\BaseResource).
|
| The "grent" admin + its login is the one standard fixture shared across this
| whole suite — loginAsTenantAdmin() in tests/Pest.php. The second tenant
| ("qatest") this file needs to prove isolation is its own explicit deviation
| from that fixture and is built here, locally, on purpose — see tests/Pest.php's
| docblock for why that isn't generalized into the shared helper.
|
*/

beforeEach(function () {
    // Real deployments (staging: SESSION_DOMAIN=.srv1203357.hstgr.cloud, see
    // .env.staging.example) issue the session cookie for the WHOLE base domain,
    // not just the subdomain that set it — that is the actual precondition behind
    // VULN-003 ("z tymi samymi ciasteczkami"). .env.testing leaves SESSION_DOMAIN
    // unset, which makes Laravel issue a host-only cookie — Playwright's real
    // cookie jar would then simply never send it to qatest.registro.local at all,
    // and scenario 2 would trivially "pass" without ever reaching ResolveTenant's
    // admin/staff branch (Auth::check() would be false, ambiguous with the thing
    // actually being tested). Configuring the wildcard domain here reproduces the
    // real attack precondition instead of a weaker one that happens not to trigger it.
    config(['session.domain' => '.registro.local']);

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $this->qatest = Organization::factory()->create(['slug' => 'qatest']);
    $this->qatestService = Service::factory()->create([
        'organization_id' => $this->qatest->id,
        'name' => 'Serwis QATEST E2E',
    ]);
});

afterEach(function () {
    // Scoped to this file only (Pest hooks are per-file) — restore the default
    // so later Browser test files in the same process aren't affected by a
    // wildcard session-cookie domain they never asked for.
    config(['session.domain' => null]);
});

it('does not show another tenant\'s records on the admin resource list', function () {
    ['organization' => $grent, 'page' => $page, 'baseUrl' => $baseUrl] = loginAsTenantAdmin('grent');

    // Explicit organization_id (not relying on BelongsToOrganization's `creating`
    // hook / TenantFeature::currentTenant()) — this row is built outside any
    // HTTP request, so there is no tenant context for the hook to auto-assign from.
    Service::factory()->create([
        'organization_id' => $grent->id,
        'name' => 'Serwis GRENT E2E',
    ]);

    // No explicit wait before these assertions: navigate() already blocks
    // until the document's 'load' event fires, which for a server-rendered
    // Filament table is enough for the row markup to be present.
    $page->navigate("{$baseUrl}/admin/services")
        ->assertSee('Serwis GRENT E2E')
        ->assertDontSee('Serwis QATEST E2E');
});

it('refuses an admin session from one tenant on a different tenant\'s subdomain', function () {
    // --- Same context, log in on grent's own subdomain first ---
    ['organization' => $grent, 'page' => $page, 'port' => $port] = loginAsTenantAdmin('grent');

    Service::factory()->create([
        'organization_id' => $grent->id,
        'name' => 'Serwis GRENT E2E',
    ]);

    // --- Same browser context/cookies, now cross to qatest's subdomain ---
    // This admin has no organization_user row for qatest (loginAsTenantAdmin()
    // only attaches it to the tenant it just logged into). ResolveTenant.php's
    // admin/staff branch must catch this: Auth::check() is true (cookies carried
    // the session over — the precondition this whole scenario exists to test),
    // the user hasAnyRole(['admin','staff']), and canAccessTenant($qatest) is
    // false, so the middleware redirects to the ROOT domain rather than letting
    // Filament render qatest's panel.
    //
    // No explicit wait here either — navigate() follows the redirect chain
    // itself and only returns once the FINAL document ('load' on the root
    // domain) has loaded.
    $page->navigate("http://qatest.registro.local:{$port}/admin/services")
        ->assertHostIsNot('qatest.registro.local')
        ->assertHostIs('registro.local')
        ->assertDontSee('Serwis QATEST E2E')
        ->assertDontSee('Serwis GRENT E2E');
});
