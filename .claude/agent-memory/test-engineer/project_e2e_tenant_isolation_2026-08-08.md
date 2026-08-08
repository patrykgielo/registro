---
name: project-e2e-tenant-isolation-2026-08-08
description: Third E2E browser test — tests/Browser/TenantIsolationTest.php, the two VULN-003 scenarios (global-scope leak + cross-subdomain session/cookie bypass); requires forcing SESSION_DOMAIN wildcard at runtime to genuinely reproduce the attack precondition
metadata:
  type: project
---

Built the THIRD E2E browser test: `tests/Browser/TenantIsolationTest.php` (branch
`feature/e2e-browser-tests`). Two `it()` blocks sharing a `beforeEach()` fixture (2 orgs `grent`/
`qatest`, 1 `Service` each via `Service::factory()->create(['organization_id' => ..., 'name' => ...])`
— explicit `organization_id`, not the `creating`-hook auto-assign, since fixtures are built outside
any HTTP request/tenant context — and 1 admin attached only to `grent`).

## Resource choice: Service / ServiceResource (`admin/services`)

Chosen because it: uses `BelongsToOrganization`, has a factory, and needs zero extra Policy/module
wiring for a plain `Organization::factory()->create()` — default `booking_type = 'time_slot'` maps to
`Organization::MODULE_DEFAULTS['time_slot'] = ['services', 'bookings', 'website']`, so the `services`
module (and therefore `ServiceResource::shouldRegisterNavigation()`/route access) is on by default.
No `app/Policies` directory exists in this project at all — Filament resource access here is
role+tenant gated, not per-resource-Policy gated. `TextColumn::make('name')` renders plainly
(searchable/sortable, no badge/formatter), so `assertSee`/`assertDontSee` on the name works directly
against real rendered HTML — verified via a throwaway probe test (`$page->content()` dump), same
workflow as prior two Browser tests.

## The one non-obvious decision: had to force `SESSION_DOMAIN` at runtime for scenario 2

`.env.testing` leaves `SESSION_DOMAIN` unset → Laravel issues a **host-only** session cookie
(`Domain` attribute absent). Playwright's real cookie jar honors normal cookie-domain-matching rules
even against the in-process test server (confirmed by reading `LaravelHttpServer::handleRequest()` —
`$cookies = array_map(..., $request->getCookies())` comes from the AmpRequest, i.e. genuinely what the
browser chose to send for that exact host). Concretely this means: with the default test config, a
cookie set while logged in on `grent.registro.local` is **never sent** to `qatest.registro.local` at
all — scenario 2 would trivially "pass" (blocked before even reaching `ResolveTenant`'s admin/staff
check, since `Auth::check()` would already be false) without ever exercising the code path the task
asked to guard. That is exactly the "test that passes from construction, worse than no test" trap the
task warned about.

**Fix:** `config(['session.domain' => '.registro.local'])` in `beforeEach()`, reset to `null` in
`afterEach()` (Pest hooks are file-scoped, confirmed safe against `SmokeTest`/`EmployeeCreationTest`
in the same process — full 3× suite run showed no interference). This isn't an arbitrary choice — it
mirrors the real deployed precondition: `.env.staging.example` has
`SESSION_DOMAIN=.srv1203357.hstgr.cloud` (an actual wildcard, cited directly in VULN-003's Gap #1
writeup: "SESSION_DOMAIN is a wildcard in this project's own staging config, so this isn't
local-only"). Setting it at runtime (not editing `.env.testing`) keeps the change scoped to this one
file and leaves no artifact in `app/` or shared test config.

Config mutation *does* take effect on the real HTTP response cookie even though this is an in-process
server — confirmed empirically (Set-Cookie's Domain attribute changes the browser's actual send
behavior on the next cross-subdomain navigate), and unsurprising given the vendor driver itself
mutates shared `config()` per-request already (`config(['app.debug' => false])` around every
`$kernel->handle()` call).

## Assertion choice: `assertHostIs`/`assertHostIsNot`, not path-only

`pest-plugin-browser` ships `MakesUrlAssertions::assertHostIs()`/`assertHostIsNot()`
(`vendor/pestphp/pest-plugin-browser/src/Api/Concerns/MakesUrlAssertions.php`) — reads
`parse_url($page->url(), PHP_URL_HOST)`, port-agnostic. Used instead of `assertPathIs('/')` alone:
`ResolveTenant`'s admin/staff redirect lands on the **root domain's own homepage**, whose path is also
`/` — a path-only assertion can't distinguish "redirected to safety" from "some other page that
happens to also be at `/`". Host assertion is the actual signal that matters here.

## Mutation testing — both fired exactly as predicted, cross-checked against each other

1. `app/Traits/BelongsToOrganization.php` — commented out the `$builder->where(...)` filter (kept the
   early `return`). Scenario 1 test failed exactly as expected
   (`Serwis QATEST E2E` leaked into grent's admin list); scenario 2 test still passed (different code
   path entirely — this trait has nothing to do with the middleware redirect). Reverted, `git diff`
   clean.
2. `app/Http/Middleware/ResolveTenant.php` — replaced the `if ($user->hasAnyRole(...) &&
   !$user->canAccessTenant($tenant))` condition with `if (false)`. Scenario 2 failed exactly as
   expected (`assertHostIsNot('qatest.registro.local')` failed — the admin genuinely stayed on
   qatest's subdomain instead of being redirected); scenario 1 still passed unaffected. Reverted,
   `git diff` clean.

Each mutation broke only its own scenario and left the other one green — good cross-confirmation that
the two `it()` blocks are actually testing two independent mechanisms, not accidentally relying on the
same guard twice.

## Verified real behavior (throwaway probe, deleted before finishing)

With the wildcard `SESSION_DOMAIN`, an authenticated grent admin navigating to
`qatest.registro.local/admin/services` genuinely round-trips through a real 302 → lands at
`http://registro.local:{port}/` (the public home-fallback page, `data-page-type="homepage"`), not an
error page. Noted but NOT investigated further (out of scope, not a security finding): that page's
logo `<a href>` pointed at `grent.registro.local` instead of the current root domain — almost
certainly `URL::forceRootUrl()` (`ResolveTenant.php:141`) being sticky across requests within this
one long-lived in-process test PHP instance (the singleton `UrlGenerator` never gets torn down between
requests the way a real per-request PHP-FPM process would). Cosmetic only — no tenant data was
rendered, only a stale nav link — so left alone.

## Full verification (2026-08-08)

- `TenantIsolationTest` alone: 2 passed, 8 assertions.
- Full `--testsuite=Browser` (3 files, 4 tests): passed 3 consecutive full runs, ~15.9s each, no flake.
- `php artisan test` (default suite): `3 failed, 5 skipped, 1054 passed` — identical to baseline
  (`CustomerOrdersTest` × 2, `TenantFeatureTest` × 1), before AND after the mutation round-trips.
- `./vendor/bin/pint --test`: 769 files (was 768 before this file), pass.
- Both mutations individually confirmed RED on their own scenario, GREEN on the other; both fully
  reverted (`git diff app/` empty after each, and after the full session).
