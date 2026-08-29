---
name: project_storefront_walkthrough_substitute_bindings_leak_2026-08-29
description: Layer 2 (storefront) walkthrough test — tests/Feature/StorefrontWalkthroughTest.php — found a real cross-tenant leak in implicit route-model-binding via SubstituteBindings ordering
metadata:
  type: project
---

`tests/Feature/StorefrontWalkthroughTest.php` (branch `test/panel-walkthrough`, not committed as of
2026-08-29) — Layer 2 of a two-layer integration-test architecture, companion to
[[project_panel_walkthrough_layer1]] (Filament admin panel walkthrough, if that memory exists —
otherwise see `tests/Feature/Filament/PanelWalkthroughTest.php` directly). Single accumulate-
violations test, 48 `check()` calls across 5 sections (content+existence, cross-tenant isolation,
listing isolation, guest-vs-authenticated, root domain), ~1.7-2.0s runtime.

**Key architectural choice, differs from most existing tenant tests in this repo:** uses the REAL
`ResolveTenant` middleware + real `Host` header (`http://{slug}.registro.local/...`), NOT the
`$this->app->bind(ResolveTenant::class, fn () => new class { ... })` test-double pattern used
throughout `tests/Feature/Security/*` and `TenantBrandNameRegressionTest`. That bind-override
pattern replaces the ENTIRE middleware class, so it can never exercise real Laravel middleware
*ordering* — which is exactly what surfaced the finding below. Two tenants provisioned via the
real `ProvisionTenantOrganization` + `SeedEquipmentRental` building blocks, seeded IDENTICALLY
(same 7-category/13-item catalog, same CMS titles → same auto-slugs on both tenants, deliberately
colliding) — each identically-slugged row gets a tenant-specific MARKER string in `body`/`excerpt`/
`description` (a field the public views actually render) so "tenant B's own row rendered" is
distinguishable from "tenant A's row leaked and nobody noticed because the catalog looks the same
anyway".

## The finding (real, reproducible, NOT fixed — reported to team-lead, out of this task's scope)

`Illuminate\Routing\Middleware\SubstituteBindings` (implicit `{model:slug}` route model binding)
ships baked into Laravel's default `web` middleware GROUP
(`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php`,
`getMiddlewareGroups()`) — group middleware always runs before ANY route-specific middleware,
including `ResolveTenant`/`RequireTenant`, regardless of `ResolveTenant::class` being listed FIRST
in a route's own `Route::middleware([...])` array in `routes/web.php`.

Consequence: for every route binding a `BelongsToOrganization` model by slug (`{service:slug}`,
`{category:slug}`, and likely others — `RentalCategory`, post/portfolio `Category`), the bound row
resolves BEFORE the current request's tenant is known. `BelongsToOrganization`'s global scope
(`app/Traits/BelongsToOrganization.php`) falls through to `TenantFeature::currentTenant()`'s
branch 3 (`session('tenant_id')`, written by `ResolveTenant` on the PREVIOUS request) instead of
the correct, current-request tenant — or, on a cold session (`tenant_resolution_attempted` not yet
set for THIS request since `ResolveTenant` hasn't run yet), the scope is a complete no-op and
`first()` returns an arbitrary matching row from ANY tenant.

**Verified with `DB::listen()`** (not guessed): a request to host B for a slug that also exists on
tenant A returned a `WHERE slug = ? AND organization_id = ?` query bound to **tenant A's id**, on
a request whose Host header was tenant B's subdomain — because that id came from
`session('tenant_id')` written by the PRIOR request (to host A). 100% reproducible on the FIRST
request after a host switch; subsequent same-host requests "self-heal" because the prior request's
own `ResolveTenant` run already corrected the session.

This is orthogonal to VULN-003's documented Layers 1-6 (`.claude/rules/middleware.md`):
`RequireTenant`'s own check (`request->attributes->get('tenant')`) still passes, because
`ResolveTenant` DOES eventually run and sets that attribute correctly — one middleware-pipeline
step too late to protect the binding that already happened. Exploitable in production wherever
`SESSION_DOMAIN` is a shared wildcard across tenant subdomains (`.registrolabs.com` — confirmed as
the real staging config, see `.claude/rules/tests.md`'s Browser-test section); even without that,
a completely cold/first-ever session on a route with implicit binding hits the same unscoped-first()
failure mode.

**Falsifiability proof performed and reverted** (per task's own acceptance criterion): temporarily
changed `ServiceController::index()`'s `Service::active()` to
`Service::withoutGlobalScope('organization')->active()` — violation count went from 2 (the real
finding above) to 4, with exactly the two new ones attributable to the injected mutation
(`services.index count -- expected 13, got 26` + excerpt-marker leak on the listing). Reverted,
`git diff` on the controller came back clean, re-run returned to exactly the same 2 baseline
violations. This is the technique to reuse for any future "prove this walkthrough actually tests
something" ask: mutate a DIFFERENT check than any already-failing one, so the new violation is
distinguishable from pre-existing ambient failures in the same accumulate-and-report test.

## Reusable technique: compact assertion helpers instead of assertSee()/assertOk()

`TestResponse::assertSee()`/`assertDontSee()`/`assertOk()`/`assertNotFound()` failure messages
embed the ENTIRE response body (a full HTML page, 1000+ lines) — fine for a single assertion, but
inside an accumulate-violations-and-report-at-the-end harness (this file's own style, and
[[project_panel_walkthrough_layer1]]'s), the FIRST such failure's page dump drowns out every other
violation in the final `$this->fail()` report (confirmed empirically: PHPUnit's pretty-printer
truncated the whole report to "... (1890 more lines) ... To contain: X", losing every other
violation's message entirely). Fix: private `expectStatus()`/`expectContains()`/
`expectNotContains()` helpers that throw a one-line `\RuntimeException` instead — cannot be named
`assertContains()`/`assertStatus()` etc., those collide with PHPUnit's own **final** `Assert::`
methods (`Cannot override final method` fatal error). Reuse this pattern for any future
accumulate-and-report walkthrough test.

## Route catalog coverage decisions (if extending this file)

Covered: home, `uslugi` (index+show), `wypozyczalnia` (index+category), `/{slug}` catch-all +
legacy `/strona/{slug}` redirect, `aktualnosci` (show+category), `promocje`, `portfolio`
(show+category), `koszyk`/`koszyk/zamowienie`/`koszyk/powrot`, `moje-zamowienia`, `moje-konto` +
4 subpages (personal/address/notifications/security — `vehicle` deliberately checked as a 404,
not a 200: `vehicles` feature is off by default for `Industry::EquipmentRental`, not a bug),
`login`, `customer.register`. Deliberately NOT covered (see file's own docblock for full
reasoning): booking-wizard routes (both test tenants are `EquipmentRental`, booking not
applicable — see [[project_business_focus]]), `checkout.submit` (own large surface, already
covered by `CheckoutFlowTest`), `cart.remove`/`cart.update` (not GET), `/moje-zamowienia/{order}`
detail + protocol/extension routes (not in the original brief's 49-route catalog — flagged to
team-lead as a possible follow-up).
