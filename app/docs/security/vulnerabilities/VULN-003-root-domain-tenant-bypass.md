# VULN-003: Root-Domain Tenant Isolation Bypass (Cross-Tenant Data Exposure)

**Status**: FIXED. Layer 1 (route-level `RequireTenant`), Layer 2 (trait-level fail-closed
backstop), and Layer 3 (session-fallback gap in `BookingController`/`AppointmentController`) are
all complete.
**Severity**: CRITICAL
**Priority**: P0
**Detected**: 2026-07-02 (code-reviewer + agent-security-audit-specialist)
**Fixed**: 2026-07-03 (Layer 1 initial), 2026-07-03 (Layer 1 gap fixes, same day), 2026-07-03
(Layer 2), 2026-07-03 (Layer 3)
**Branch**: `hotfix/require-tenant-middleware` (Layer 1), `hardening/belongs-to-organization-fail-closed` (Layer 2), `fix/booking-cross-tenant-session-fallback` (Layer 3)

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

## Layer 2 (2026-07-03, `hardening/belongs-to-organization-fail-closed`): defense-in-depth at the trait level

### Problem

Layer 1 closes VULN-003 per-route, via `RequireTenant::class` on every route confirmed to
query a `BelongsToOrganization` model. That is correct but **not self-enforcing** — any future
route that queries a tenant-scoped model and forgets `RequireTenant` silently reopens the same
vulnerability class, because `BelongsToOrganization`'s global scope itself still no-ops (applies
zero filtering) whenever no tenant is resolved. Two known, already-documented gaps existed at
the time this work started: `BookingController`/`AppointmentController` sit behind
`ResolveTenant::class` only (no `RequireTenant`) on `routes/web.php` — see the
`['auth', ResolveTenant::class]` group covering `booking.*` and `appointments.*`.

### Przyczyna

Root cause identical to Layer 1's: `BelongsToOrganization` (`app/Traits/BelongsToOrganization.php`)
treats "no tenant resolved" as "don't filter" instead of "filter everything out." Layer 1 fixed
this by gating access at the route/middleware layer; Layer 2 fixes it at the source — the trait
itself — so the vulnerability class cannot recur even if a route's middleware is misconfigured.

### Rozwiązanie

Two-file change:

1. **`app/Http/Middleware/ResolveTenant.php`** — the very first line of `handle()` (before any
   branching) now sets:
   ```php
   $request->attributes->set('tenant_resolution_attempted', true);
   ```
   Unconditional — set on every request this middleware processes, regardless of which branch
   (root domain / redirect / suspended / closed / success) is taken. Marks "ResolveTenant
   genuinely ran for this specific request", distinct from `runningInConsole()`/
   `runningUnitTests()` (which can't tell a real HTTP/feature-test request apart from a bare
   Unit test that never touches this middleware at all — `runningUnitTests()` is `true` for the
   entire PHPUnit process either way).

2. **`app/Traits/BelongsToOrganization.php`** — the global scope now fails closed, but ONLY when
   `ResolveTenant` genuinely ran for the current request and still found no tenant:
   ```php
   $tenant = TenantFeature::currentTenant();

   if ($tenant) {
       $builder->where($builder->getModel()->getTable().'.organization_id', $tenant->id);
       return;
   }

   if (app()->bound('request') && app('request')->attributes->get('tenant_resolution_attempted') === true) {
       $builder->whereRaw('1 = 0');
   }
   ```
   `app('request')` inside a global scope closure correctly sees the SAME request object
   `ResolveTenant` annotated — global scopes execute lazily at query-build time (well within the
   request lifecycle), and Laravel's test HTTP client (`$this->get()`/`$this->post()`) rebinds
   the `request` singleton as it dispatches through the kernel, so this works identically for
   real requests and feature tests.

**Why gate on a request attribute instead of just checking "was a tenant resolved":** a bare
Eloquent call with no HTTP request in flight (Unit test, queued job, `setUp()` before any
`$this->get()`) must keep today's permissive no-op — there is no "wrong" answer to fail closed
against when no request/route/tenant concept even applies. Only a genuine
`ResolveTenant`-dispatched request that resolved nothing is the actual vulnerable scenario.

### Test-suite impact (full triage, `docker compose exec -T app php artisan test`)

**Baseline (`develop`, pre-Layer-2)**: 723 passed, 7 failed, 4 skipped.
Baseline failures: `BookingServiceAreaBypassTest` ×4, `Orders\CustomerOrdersTest` ×2,
`TenantFeatureTest` ×1.

**After the 2-file Layer 2 change alone (test files untouched)**: 715 passed, 15 failed.
8 NEW failures, all in routes covered by `['auth', ResolveTenant::class]` but **not**
`RequireTenant` — `BookingConfirmationSecurityMinimalTest` ×2, `BookingConfirmationSecurityTest`
×4, and 2 more in `BookingServiceAreaBypassTest` (the "allows" tests, which previously passed
by accident because `Service`/`Appointment` lookups were unscoped). Each of these tests hit a
`booking.*` route via `route()` helper, which resolves to `registro.local` (the configured root
domain in `.env.testing`) with no tenant ever simulated — i.e. they were unknowingly relying on
the exact no-op-when-no-tenant behavior Layer 2 closes.

**Fix**: all 3 affected test files updated to properly simulate a tenant context — same
`actingAsTenant()` pattern used throughout the project (bind a `ResolveTenant::class` test
double via the container that sets the `tenant` request attribute directly), plus an
`Organization::factory()->autoDetailing()->create()` tenant whose `organization_id` propagates
to every model the tests create directly (`Service`, `StaffSchedule`, `ServiceArea`,
`Appointment`) by setting the tenant on the already-bound `app('request')` *before* those models
are created (so `BelongsToOrganization`'s `creating` hook auto-assigns `organization_id`).
`Notification::fake()` added to avoid an unrelated, pre-existing gap: `email_templates` is
intentionally a global, `NULL`-`organization_id` system table (see migration
`2026_06_29_120000_fix_tenant_scoped_unique_constraints` — composite tenant-scoped uniques were
deliberately skipped for it) but `EmailTemplate` still uses `BelongsToOrganization`, so with a
real tenant now resolved, template lookups get tenant-filtered and miss the global rows — the
exact same root cause behind `CustomerOrdersTest`'s pre-existing `'order-cancelled'` template
failure. Out of scope to fix here (product-level `EmailTemplate` scoping design question, not a
Layer 2 regression); faked notifications instead, matching the project's existing pattern
(`OrderSecurityTest`, `RentalCancelledTest`, etc.).

**Net effect of the test fixes**: not only did this eliminate the 8 new failures, it also fixed
all 4 of the pre-existing `BookingServiceAreaBypassTest` failures as a side effect — their root
cause was the SAME missing-tenant-context bug (with no tenant resolved,
`TenantFeature::active('service_area')` was always `false`, so the service-area validation gate
in `BookingController::confirm()` was silently skipped entirely, and the tests asserting a
block never actually exercised the validation they were testing).

**Final state**: 731 passed, 3 failed, 4 skipped — the exact same 3 pre-existing failures as
baseline (`Orders\CustomerOrdersTest` ×2 — same `'order-cancelled'` template gap;
`TenantFeatureTest` ×1 — unrelated `Service`/tenant organization_id mismatch in that test's own
fixture setup), confirmed identical failure messages/line numbers to baseline. Verified
mechanistically unaffected by Layer 2: `TenantFeatureTest` uses the `actingAsTenant()` container-
rebinding pattern, which replaces `ResolveTenant::class` entirely — the real `handle()` method
(and therefore `tenant_resolution_attempted`) never runs for those requests, so the "tenant
resolved" branch (which predates Layer 2 entirely) is what's active, not the new fail-closed
branch. Zero tests newly broken; 4 net additional passing tests (regression suite) + 4 previously
broken tests fixed = 731 vs 723 baseline passed.

New regression tests: `tests/Feature/Security/BelongsToOrganizationFailClosedTest.php` —
4 tests: (1) core mechanism — `GET /booking/available-slots` (covered by `ResolveTenant` only,
deliberately **not** `RequireTenant`) 404s for a real service on the root domain, proving the
trait-level fix works independent of per-route middleware; (2) side-benefit check — booking
wizard's service-selection step (`Service::active()->get()`, no explicit org filter, no
`RequireTenant`) shows zero services (not another tenant's) on the root domain; (3) positive
control — a properly tenant-resolved request (real subdomain) still sees its own data normally;
(4) confirms a bare Eloquent query with no HTTP request in flight is unaffected (permissive
no-op preserved, as designed).

### Booking/Appointment side-benefit — confirmed working

The task hypothesis (any `Appointment`/`Service` query in `BookingController`/
`AppointmentController` on the root domain now returns empty instead of leaking cross-tenant
data) is **confirmed working**, proven by
`test_booking_wizard_service_step_shows_no_cross_tenant_services_on_root_domain` in the new
regression file: two organizations each with their own active `Service`; on the root domain
(authenticated user, no tenant simulated, hitting `booking.step` — which carries
`ResolveTenant` but explicitly not `RequireTenant`), the response is `200 OK` (not blocked at
the route level) but the `services` view variable is empty and neither organization's service
name appears in the response body. Before Layer 2, this exact request would have returned
**every** organization's active services, unfiltered.

**Scope of this mitigation — read carefully, it is narrower than "fixed":** the regression test
above covers the pure root-domain case, with no tenant resolvable through ANY of
`TenantFeature::currentTenant()`'s three branches. It does **not** cover the session-fallback
attack chain documented in the escalated Follow-up below (an authenticated customer who
previously browsed a DIFFERENT tenant's subdomain still gets scoped, read+write, to that wrong
tenant on the root domain — Layer 2's fail-closed check is never reached in that case, because
`currentTenant()` returns non-null via the session branch). This also does **not** fix the
separately tracked `AppointmentController::store`'s `exists:services,id` IDOR (bypasses Eloquent
scopes entirely via the validator, unrelated to this trait) — still open, as originally
documented below.

### Zapobieganie

**Precise scope of the backstop (do not overclaim this):**

- `BelongsToOrganization`'s fail-closed behavior protects any route that still carries
  `ResolveTenant::class` (so `tenant_resolution_attempted` gets set) but is **missing**
  `RequireTenant::class` — that is the exact gap pattern this Layer 2 closes. A future route in
  that shape no longer leaks data; it serves empty results on the root domain instead.
- It does **NOT** protect a route carrying **neither** middleware — `tenant_resolution_attempted`
  is never set in that case, so `BelongsToOrganization` falls back to the original,
  fully-permissive no-op (same as pre-Layer-1). Layer 2 is not a substitute for wiring
  `ResolveTenant` in the first place.
- It does **NOT** protect Filament's Livewire AJAX layer. `POST /livewire/update` (registered by
  the Livewire package itself,
  `vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php`) carries only the
  `web` middleware group — neither `AdminPanelProvider` nor Filament core routes it through
  `ResolveTenant`/`RequireTenant`. Since most actual `/admin` panel interaction (table filters,
  form saves, component re-renders) happens via this route, `tenant_resolution_attempted` is
  never `true` there — that entire surface still relies purely on the pre-existing
  `TenantFeature::currentTenant()` session-fallback mechanism, exactly as before. Layer 2 does
  not make this worse, but it does not newly protect it either — do not treat it as a backstop
  for the admin panel's live interaction layer specifically.
- `BelongsToOrganization`'s fail-closed check is also never reached at all when
  `TenantFeature::currentTenant()` resolves a tenant via its session-fallback branch (3rd branch,
  `session('tenant_id')`) — the `if ($tenant)` branch returns first, scoped to whatever tenant
  the session says, right or wrong. See the Booking/Appointment follow-up below for a concrete,
  confirmed exploit of exactly this.
- **Do not revert this to a no-op** without understanding why it's there — see
  `.claude/rules/models.md` for the `tenant_resolution_attempted` mechanism (cross-referenced
  there against the related LC-9 session/global-row incident).
- Any test that creates `BelongsToOrganization` models AND makes a real HTTP request through a
  route carrying `ResolveTenant` (even indirectly, e.g. via `route()` resolving to the
  configured root domain) MUST either simulate a tenant (`actingAsTenant()` pattern) or expect
  empty/404 results — it can no longer rely on the previous unscoped-by-default behavior.

## Layer 3 (2026-07-03, `fix/booking-cross-tenant-session-fallback`): booking/appointments session-fallback gap

### Problem

`routes/web.php:232` — `Route::middleware(['auth', ResolveTenant::class])->group(...)` covered all
14 `booking.*`/`appointments.*` routes (plus `profile.*`), none carrying `RequireTenant::class`.
Both `BookingController` and `AppointmentController` create `Appointment` rows with zero explicit
`organization_id` — 100% implicit via `BelongsToOrganization`'s `creating` hook, which calls
`TenantFeature::currentTenant()`.

**Confirmed attack chain** — lower exploit bar than the original VULN-003 (any authenticated
customer, one unauthenticated `GET`, no admin/staff credential needed), narrower blast radius
(scoped to one specific wrong tenant rather than all tenants at once):

1. Any authenticated customer of Org A visits `orgB.<domain>/` (any public page,
   **unauthenticated is fine** — no login on Org B needed). `ResolveTenant.php` unconditionally
   writes `session()->put('tenant_id', $tenant->id)` on every successful subdomain resolution,
   for anonymous visitors too — so the customer's session now carries `tenant_id = orgB.id`.
2. They then hit the root-domain booking flow (`booking.step`, `booking.confirm`, etc.) while
   authenticated. `TenantFeature::currentTenant()` resolves Org B via its 3rd fallback branch
   (`session('tenant_id')`) — **truthy, not null** — so `BelongsToOrganization`'s Layer 2
   fail-closed check is **never even reached**; the `if ($tenant)` branch returns first, scoped
   to Org B.
3. The query — and worse, `Appointment::create()` in `BookingController::confirm()` (which has no
   explicit `organization_id`, relying on the `creating` hook's auto-assign via the same
   `currentTenant()` call) and `AppointmentController::store()` — proceeds scoped to Org B. This
   is a genuine **cross-tenant write**: a customer can plant a bogus appointment into a
   completely different tenant's calendar, consuming a real slot — not merely viewing Org B's
   data.

### Przyczyna

Same root cause class as Layer 1/2, but via a different bypass: `RequireTenant` gates on the
`tenant` request attribute, which only `ResolveTenant`'s subdomain-success branch sets — so any
route carrying `RequireTenant` was already immune to this. `booking.*`/`appointments.*` simply
never had `RequireTenant` added (a deliberate, documented gap — see the Layer 1 "Explicitly NOT
touched" note), leaving `TenantFeature::currentTenant()`'s session-fallback branch as the only
signal, which an unauthenticated visit to any tenant's subdomain can poison.

### Rozwiązanie

One-line middleware change — `RequireTenant::class` added to the same outer group, right after
`ResolveTenant::class`, at `routes/web.php:232`:

```php
Route::middleware(['auth', ResolveTenant::class, RequireTenant::class])->group(function () {
    // ... all 14 booking.*/appointments.* routes, plus profile.*, unchanged
});
```

Sufficient because every one of these routes is a genuine full-page HTTP request (not Livewire
AJAX — zero `wire:`/`@livewire` usage in `resources/views/booking-wizard/**` or
`resources/views/appointments/**`), so `RequireTenant` (gated on the request attribute, never the
session) correctly 404s the request on the root domain regardless of stale session content —
closing both the read and write vectors, since the controller code (including
`Appointment::create()`) never executes if `RequireTenant` aborts first. `booking.confirmation`
and `booking.ical` (deliberately outside the `CheckBookingEnabled` sub-group, so a customer
mid-flow isn't bounced if booking gets toggled off) are still inside the outer group and get the
same protection — `showConfirmation()`'s `Appointment::findOrFail()` is the read-half of this
same bug.

### Test-suite impact

New regression file: `tests/Feature/Security/BookingCrossTenantSessionFallbackTest.php` — 2 real
`Organization`s, an authenticated customer of Org A, `withSession(['tenant_id' => $orgB->id])` to
simulate the poisoned session, then asserts 404 on `booking.step`, `booking.confirm` (POST, with
`assertDatabaseMissing` confirming no `Appointment` row was created), and `appointments.index` on
the root domain — plus 2 positive-path tests (`actingAsTenant()` pattern, real tenant subdomain)
confirming `RequireTenant` doesn't break legitimate booking.

**Adversarial review caught a weak assertion** in the first draft of
`test_booking_confirm_post_returns_404_on_root_domain_with_poisoned_session`: the crafted session
payload used `service_id: 1` with no matching `Service` row in the fresh test DB, so
`Service::findOrFail()` 404'd on its own — the test passed even with `RequireTenant::class`
removed from the route, proving nothing. Fixed by building a real, fully bookable `Service` +
staffed schedule under Org B (the tenant the poisoned session resolves to) and referencing its
real ID. Verified mechanistically: with `RequireTenant::class` temporarily removed from the route
group, this exact request reaches and executes `Appointment::create()` (confirmed via
`storage/logs/laravel.log` — `"Booking confirm: appointment created successfully"`, scoped to Org
B's service/staff) and the test correctly **fails**; with `RequireTenant::class` restored, the
request 404s before the controller runs and the test passes. This is the standard bar for any
"assert 404" security regression test in this codebase — a 404 must be shown to come from the
control being tested, not from an unrelated missing-fixture accident.

One existing test needed updating as a direct, mechanical consequence of this route change:
`BelongsToOrganizationFailClosedTest::test_booking_wizard_service_step_shows_no_cross_tenant_services_on_root_domain`
asserted `200 OK` with an empty `services` view variable — the exact Layer-2-without-Layer-3
backstop behavior for this specific route. Since `booking.step` now carries `RequireTenant`, the
route 404s before the `Service` query runs; renamed to
`test_booking_wizard_service_step_returns_404_on_root_domain` and updated to assert `404`. The
sibling `test_available_slots_endpoint_fails_closed_on_root_domain_without_require_tenant` needed
no code change (still asserts 404, same outcome via a different mechanism now) — only its
docblock was updated for accuracy.

**Also fixed in this pass**: `tests/Feature/AppointmentStaffValidationTest.php` used the old
`/** @test */` doc-comment annotation (dropped by PHPUnit 12 — silently `0` tests executed).
Renamed all 4 methods to the `test_` prefix (same fix as `ServiceAreaValidationTest.php` in the
Layer 1 gap-fix pass); all 4 pass once discovered, no logic changes needed. 3 files with this same
latent bug remain open follow-ups: `tests/Unit/ServiceAreaHaversineTest.php`,
`tests/Feature/ServiceAreaWaitlistTest.php`, `tests/Feature/ProfileSynchronizationTest.php`.

**Full suite**: baseline (`develop`, pre-Layer-3) 731 passed / 3 failed / 4 skipped →
740 passed / 3 failed / 4 skipped. +9 passed (5 new regression tests +
4 previously-dead `AppointmentStaffValidationTest` tests now executing), 0 new failures — the
same 3 pre-existing failures (`Orders\CustomerOrdersTest` ×2, `TenantFeatureTest` ×1), confirmed
identical failure line/message against unmodified `develop`.

### Zapobieganie

- Any new authenticated route that creates or reads a `BelongsToOrganization` model MUST carry
  `RequireTenant::class` right after `ResolveTenant::class` — `['auth', ResolveTenant::class]`
  alone is **not** sufficient, `auth` says nothing about which tenant, and
  `TenantFeature::currentTenant()`'s session fallback can resolve a *wrong* tenant just as easily
  as a right one for an authenticated user.
- Do not treat "the user is logged in" as a substitute for "the tenant was resolved for this
  specific request" — they are orthogonal checks, and Laravel session `tenant_id` predates any
  per-request authorization decision.

## Follow-ups (out of scope for this fix)

- **HIGH PRIORITY — same vulnerability class, higher stakes (real money): `CartController`/
  `CheckoutController`/`OrderController`.** Adversarial re-review (2026-07-03, same pass as Layer
  3) found these controllers gate tenant access via `TenantFeature::currentTenant() !== null` —
  the exact same permissive, session-fallback-vulnerable check Layer 3 just stopped relying on for
  booking. The identical attack (poison session via an unauthenticated visit to another tenant's
  subdomain, then hit `/koszyk`, checkout, or order-cancel on the root domain) still resolves the
  wrong org via the session fallback and proceeds — cart/checkout/order data, real money, a bigger
  blast radius than booking. Flagged for a separate, deliberate follow-up task — **not fixed
  here**, do not touch these three controllers as part of Layer 3.
- `LoginController::authenticated()`'s customer-role redirect
  (`return redirect()->route('appointments.index')`) has no tenant/subdomain check, unlike the
  admin/staff branch above it. Since `appointments.index` now requires `RequireTenant` (Layer 3),
  a customer authenticating via a root-domain `/login` would hit a 404 instead of the previous
  empty-list page. Reviewer could not find an actual in-app navigation path to `/login` from the
  root domain, so real-world exposure looks low — documented as a known, low-exposure limitation,
  no code change made here.
- `AppointmentController::store` — `exists:services,id` validation rule allows cross-tenant
  service IDs (IDOR follow-up ticket).
- `ServiceAreaWaitlist` model has no `organization_id` — design question for a future ticket
  (see Gap #2 above).
- 3 test files still have dead `/** @test */`-annotated tests post PHPUnit 12 upgrade (see
  "Discovered while fixing" above and Layer 3's "Also fixed in this pass") — needs a dedicated
  cleanup PR, ideally with a CI check that fails if `grep -rl '@test' tests/` finds anything, to
  prevent recurrence.

---

**Created**: 2026-07-03
**Updated**: 2026-07-03 (Layer 1 gap fixes from adversarial re-review), 2026-07-03 (Layer 2 —
BelongsToOrganization fail-closed), 2026-07-03 (Layer 3 — booking/appointments session-fallback
gap)
**Related**: [Lifecycle Security Decisions](../lifecycle-security-decisions.md), [Orders Security Hardening](../../features/orders-security-hardening.md)
