# VULN-003: Root-Domain Tenant Isolation Bypass (Cross-Tenant Data Exposure)

**Status**: FIXED (Layer 1, including 2 gaps found in adversarial re-review — see Follow-ups for Layer 2)
**Severity**: CRITICAL
**Priority**: P0
**Detected**: 2026-07-02 (code-reviewer + agent-security-audit-specialist)
**Fixed**: 2026-07-03 (initial), 2026-07-03 (gap fixes, same day, same branch)
**Branch**: `hotfix/require-tenant-middleware`

---

## Problem

On the bare root domain (no subdomain, e.g. `https://registro.local/`), every public content
route (`/`, `/aktualnosci/*`, `/promocje/*`, `/portfolio/*`, `/uslugi/*`, `/wypozyczalnia/*`,
the rental availability AJAX endpoints, and the CMS catch-all `/{slug}`) ran completely
**unscoped** database queries, returning ANY tenant's data to anonymous visitors.

Worse: the `/admin/*` Filament panel shares `ResolveTenant` for tenant context (no native
Filament multi-tenancy configured). A staff/admin user with valid credentials for ANY ONE
tenant could log in via the root domain and browse `/admin/*` with no tenant resolved — most
Filament Resources rendered unfiltered, all-tenant data (PII, orders, appointments — everything).

## Przyczyna (Root Cause)

`ResolveTenant::handle()` (`app/Http/Middleware/ResolveTenant.php:29-31`) intentionally lets the
root domain pass through **without** setting the `tenant` request attribute — this is correct
behaviour, the root domain is the public marketplace / registration entry point, not a tenant.

The bug is downstream: `BelongsToOrganization` (`app/Traits/BelongsToOrganization.php:33-46`) —
the global scope every tenant-owned model uses — **silently no-ops** (applies zero
`organization_id` filtering) whenever `TenantFeature::currentTenant()` returns null. No route
ever verified that a tenant *was* resolved before querying a tenant-scoped model, so "no tenant"
silently became "all tenants."

## Rozwiązanie (Fix — Layer 1, surgical/middleware-based)

New middleware `app/Http/Middleware/RequireTenant.php`:

```php
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(TenantFeature::currentTenant() !== null, 404);

        return $next($request);
    }
}
```

Registered as alias `require.tenant` in `bootstrap/app.php`. Applied **immediately after**
`ResolveTenant::class` (it depends on `ResolveTenant` having already attempted resolution) to
every public route group that queries a `BelongsToOrganization` model:

- `routes/web.php` — home (`/`), CMS content group (`/aktualnosci/*`, `/promocje/*`,
  `/portfolio/*`), service pages (`/uslugi`, `/uslugi/{service:slug}`), service inquiry
  (`POST /uslugi/{service:slug}/zapytaj`), rental catalogue (`/wypozyczalnia`,
  `/wypozyczalnia/{category:slug}`), rental availability AJAX endpoints
  (`/api/rental/{service:slug}/dostepnosc`, `/kalendarz`), and the catch-all `/{slug}`
  (`page.show` — order preserved, still registered last).
- `app/Providers/Filament/AdminPanelProvider.php` — added to the panel's base `->middleware()`
  array right after `ResolveTenant::class`, so it runs **before** `->authMiddleware()`. A
  tenant-less admin request (including `GET /admin/login` itself) now 404s before Filament's
  own auth/authorization logic is ever reached.

**Explicitly NOT touched** (separate scope, see task boundaries): `BelongsToOrganization.php`
itself (Layer 2 — a much higher-blast-radius change), `CartController`/`OrderController` routes
(already had explicit `TenantFeature::currentTenant()` guards, confirmed safe by prior audit),
`BookingController`/`AppointmentController` (missing org checks — separate follow-up), the
`exists:services,id` IDOR in `AppointmentController::store` (separate follow-up), auth/login/
register routes.

## Zapobieganie (Prevention)

- **Any new public route that queries a `BelongsToOrganization` model MUST include
  `RequireTenant::class` right after `ResolveTenant::class`** in its middleware array. This is
  not automatic — `ResolveTenant` alone only *attempts* resolution, it does not enforce it.
- Regression test: `tests/Feature/Security/RootDomainTenantIsolationTest.php` — hits `/`,
  `/aktualnosci/{slug}`, `/uslugi`, `/wypozyczalnia`, the CMS catch-all, and `/admin/login` on
  the bare root domain and asserts 404 on all of them.
- Two pre-existing tests (`tests/Feature/Analytics/AnalyticsOverviewPageTest.php` —
  `test_analytics_page_requires_auth`, `test_analytics_page_requires_admin_role`) were relying
  on the vulnerable behaviour: they hit `/admin/analityka` on the root domain and asserted
  auth/authorization responses that were only reachable because no tenant was ever required.
  Updated to hit a real tenant subdomain (`http://{$org->slug}.registro.local/admin/analityka`)
  so the auth/authorization checks they actually test are still exercised.

## Gap #1 (adversarial re-review, same day): admin privilege-escalation via stale session `tenant_id`

### Problem

`RequireTenant` gated on `TenantFeature::currentTenant() !== null` — but that helper has a 3rd
fallback branch reading `session('tenant_id')` (meant for Livewire update requests, which bypass
`ResolveTenant`). `ResolveTenant` writes `session()->put('tenant_id', $tenant->id)` on **every**
successful subdomain resolution (`ResolveTenant.php:79-83`), including for anonymous/
unauthenticated visitors, and **before** the `canAccessTenant()` staff-authorization check
(`:118-127`) — which only runs inside the subdomain-resolution branch, never on the root-domain
branch.

**Attack:** a staff user authorized only for Org A does ordinary, unauthenticated browsing —
visits `orgB.<domain>/` (any public page) → their session now has `tenant_id = orgB.id`. They
then visit the root domain `<domain>/admin/...` → `ResolveTenant`'s root-domain fast path runs
(no `canAccessTenant` check, no request-attribute `tenant` set) → but the OLD `RequireTenant`
resolved `TenantFeature::currentTenant()` via the session fallback = Org B → passed the guard →
Filament could render Org B's data to a user never authorized for Org B in this request.
`SESSION_DOMAIN` is a wildcard in this project's own staging config, so this isn't local-only.

### Przyczyna

`RequireTenant` used the wrong signal. `TenantFeature::currentTenant()` is intentionally
*permissive* (3 fallback sources, designed for business-logic scoping across Filament/request/
Livewire contexts) — appropriate for `BelongsToOrganization`'s scope, wrong for a security gate
that needs to answer "was a tenant resolved for **this specific request**, on **this host**?"

### Rozwiązanie

`app/Http/Middleware/RequireTenant.php` now checks the request attribute directly:

```php
abort_unless($request->attributes->get('tenant') !== null, 404);
```

This is set fresh, per-request, by `ResolveTenant` based on the current request's Host header —
no session/Livewire-fallback ambiguity. `AdminPanelProvider` never calls Filament's native
`->tenant()`, so `filament()->getTenant()` was never a real source there either; switching to
the request attribute is a strict correctness fix with no legitimate-case regression.

**Middleware-ordering caveat discovered while testing this fix:** Laravel's global
`$middlewarePriority` list (`Illuminate\Foundation\Http\Kernel::$middlewarePriority`) forces
`AuthenticatesRequests` (Filament's `Authenticate`) to run **before** any non-prioritized
middleware regardless of declared array position — so on protected `/admin/*` pages, an
**unauthenticated** guest hits the login redirect before `ResolveTenant`/`RequireTenant` ever
run (confirmed via `php artisan route:list -vvv`). This is *not* a data leak (no tenant data is
rendered to a guest — they're bounced to `/admin/login`, which is itself root-domain and itself
404s per the base fix). We deliberately did **not** "fix" this via a global
`prependToPriorityList()` middleware-priority change — an early attempt at that surfaced a much
higher-blast-radius regression: it also reordered `ResolveTenant` ahead of `SubstituteBindings`
on `web` routes declared as `['auth', ResolveTenant::class]` (e.g. `OrderController`'s
`orders.show`), which turned its intentional cross-tenant IDOR check (route-model-binding
succeeds regardless of tenant, controller manually returns 403) into a 404 — silently changing
`OrderController`'s explicitly-audited, explicitly-out-of-scope behavior. Global middleware
priority in Laravel is **not route-scoped** — there is no way to fix this only for `/admin/*`.
The authenticated-attacker path (the actual vulnerability) is unaffected by this ordering
quirk, since `Authenticate` passing through for a logged-in user doesn't change what
`RequireTenant` does afterward.

### Zapobieganie

- `RequireTenant` MUST check `$request->attributes->get('tenant')`, never
  `TenantFeature::currentTenant()` or any session-backed helper.
- Regression tests in `tests/Feature/Security/RootDomainTenantIsolationTest.php`:
  - `test_admin_route_on_root_domain_ignores_stale_session_tenant_id_when_unauthenticated` —
    asserts the guest redirect-to-login round-trip is still safe end-to-end.
  - `test_admin_route_on_root_domain_ignores_stale_session_tenant_id_for_authenticated_staff` —
    the actual attack scenario: authenticated staff (authorized for Org A only) with a stale
    `tenant_id` session for Org B must get 404 on the root domain, not Org B's data.
- If a future change ever needs `RequireTenant` to run strictly before Laravel's `Authenticate`
  on `/admin/*`, do it via a Filament-panel-scoped mechanism (e.g. a dedicated auth guard check
  inside `RequireTenant` itself, or a Filament `Panel::authGuard()`-level hook) — **not** a
  global `$middlewarePriority` change, which silently touches every `['auth', ResolveTenant]`
  route in `routes/web.php` too.

## Gap #2 (adversarial re-review, same day): `/api/service-area/*` — always-on, unconditional leak

### Problem

`routes/api.php` — `/api/service-area/validate` and `/api/service-area/areas`
(`ServiceAreaController`) carried no `ResolveTenant` middleware at all (only `throttle`), and the
`api` middleware group has no session middleware either. So `TenantFeature::currentTenant()` was
`null` for these routes on **every** request, from **every** tenant subdomain, always — not just
the root domain. `ServiceAreaValidator::getPublicServiceAreas()` →
`ServiceArea::active()->ordered()->get()` returned **every** organization's service areas (city
names, GPS coordinates, radii) to any anonymous visitor. Called live from the standard booking
flow (`resources/views/booking-wizard/steps/vehicle-location.blade.php`,
`resources/js/serviceAreaMap.js`). Compounding bug: the result was cached under a single global
key `Cache::remember('service_areas:active', 3600, ...)` — not tenant-scoped, so the first
tenant to hit it polluted the cache for every other tenant for up to an hour.

### Przyczyna

These two routes were simply never wired to `ResolveTenant` in the first place (unlike almost
every other public route in `routes/web.php`) — an oversight predating this incident, not
introduced by it. The cache key compounded the blast radius by sharing state across tenants.

### Rozwiązanie

1. `routes/api.php` — added `ResolveTenant::class` + `RequireTenant::class` (in that order) to
   both `/service-area/validate` and `/service-area/areas` route groups. Verified safe: both
   `serviceAreaMap.js` fetch calls use **relative** URLs (`/api/service-area/validate`,
   `/api/service-area/areas`), resolved by the browser against the page's own origin — i.e. the
   tenant subdomain the page was already loaded from — so Host-header-based tenant resolution
   works transparently for the legitimate call path. No session middleware needed —
   `ResolveTenant`'s `$request->hasSession()` guard already no-ops the session write when absent
   (the `api` group has no session middleware).
2. `app/Services/ServiceAreaValidator.php` — cache key changed from the flat `'service_areas:active'`
   constant to a tenant-scoped `cacheKey()`: `"service_areas:active:{$tenantId}"`, falling back
   to a shared `'none'` bucket when no tenant is resolved (console context only now — HTTP access
   without a resolved tenant is blocked upstream by `RequireTenant`).
3. `/api/service-area/waitlist` (`ServiceAreaWaitlistController`) — **intentionally left alone**.
   `ServiceAreaWaitlist` has no `organization_id` / `BelongsToOrganization` at all — a separate,
   pre-existing design question (does a waitlist entry even belong to one tenant, given a
   location might be "outside area" for several nearby tenants?), not this vulnerability class.
   Flagged as a follow-up, not fixed here (avoiding scope creep into a model redesign).

### Zapobieganie

- Any new `routes/api.php` endpoint that queries a `BelongsToOrganization` model MUST include
  `ResolveTenant::class` + `RequireTenant::class` — the `api` middleware group provides **no**
  tenant context by default, unlike `routes/web.php`'s per-group conventions which at least had
  `ResolveTenant` (if not always `RequireTenant`) almost everywhere already.
- Any `Cache::remember()` key for tenant-owned data MUST include the tenant ID (or an explicit
  `'none'`/`'global'` sentinel when intentionally cross-tenant) — a flat string key is a
  cross-tenant cache-poisoning bug waiting to happen the moment two tenants share a cache store
  (which they always do here — `Cache::store()` is process-wide, not tenant-namespaced).
- Regression test: `tests/Feature/ServiceAreaValidationTest.php::test_cache_is_isolated_per_tenant`
  — two orgs, two different service areas, asserts each tenant's `/api/service-area/areas`
  response contains only its own city and that both tenant-scoped cache keys exist independently.
- `test_api_endpoint_validates_location`/`test_api_endpoint_rejects_invalid_coordinates` updated
  to hit a real tenant subdomain instead of the (now-blocked) root domain.

### Discovered while fixing (unrelated pre-existing bug, documented for awareness)

`tests/Feature/ServiceAreaValidationTest.php` (and 4 other files — see below) used PHPUnit's
`/** @test */` doc-comment annotation instead of a `test_` method-name prefix. **PHPUnit 12
dropped support for `@test` annotations** (attributes-only now) — every test in this file was
silently **never executing** (`No tests found in class`), which is how the `RequireTenant`
regression on this file's routes went unnoticed until this review. Fixed in this file (renamed
all methods to `test_*`, required for the new regression test to actually run). **Not fixed**
(out of scope, same latent bug, same fix needed): `tests/Unit/ServiceAreaHaversineTest.php`,
`tests/Feature/AppointmentStaffValidationTest.php`, `tests/Feature/ServiceAreaWaitlistTest.php`,
`tests/Feature/ProfileSynchronizationTest.php`. All 4 need the same `@test` → `test_` rename;
until then their coverage is a false green (they simply don't run).

## Follow-ups (out of scope for this fix)

- **Layer 2**: `BelongsToOrganization` global scope should itself refuse to no-op when no
  tenant is resolved (defense in depth) — requires careful test-suite analysis given how many
  contexts call `TenantFeature::currentTenant()` (Filament tenant context, request attribute,
  session fallback for Livewire).
- `BookingController`/`AppointmentController` — missing org checks (separate ticket).
- `AppointmentController::store` — `exists:services,id` validation rule allows cross-tenant
  service IDs (IDOR follow-up ticket).
- `ServiceAreaWaitlist` model has no `organization_id` — design question for a future ticket
  (see Gap #2 above).
- 4 test files still have dead `/** @test */`-annotated tests post PHPUnit 12 upgrade (see
  "Discovered while fixing" above) — needs a dedicated cleanup PR, ideally with a CI check that
  fails if `grep -rl '@test' tests/` finds anything, to prevent recurrence.

---

**Created**: 2026-07-03
**Updated**: 2026-07-03 (gap fixes from adversarial re-review)
**Related**: [Lifecycle Security Decisions](../lifecycle-security-decisions.md), [Orders Security Hardening](../../features/orders-security-hardening.md)
