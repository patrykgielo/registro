# VULN-003: Root-Domain Tenant Isolation Bypass (Cross-Tenant Data Exposure)

**Status**: FIXED (Layer 1 — see Follow-ups for Layer 2)
**Severity**: CRITICAL
**Priority**: P0
**Detected**: 2026-07-02 (code-reviewer + agent-security-audit-specialist)
**Fixed**: 2026-07-03
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

## Follow-ups (out of scope for this fix)

- **Layer 2**: `BelongsToOrganization` global scope should itself refuse to no-op when no
  tenant is resolved (defense in depth) — requires careful test-suite analysis given how many
  contexts call `TenantFeature::currentTenant()` (Filament tenant context, request attribute,
  session fallback for Livewire).
- `BookingController`/`AppointmentController` — missing org checks (separate ticket).
- `AppointmentController::store` — `exists:services,id` validation rule allows cross-tenant
  service IDs (IDOR follow-up ticket).

---

**Created**: 2026-07-03
**Related**: [Lifecycle Security Decisions](../lifecycle-security-decisions.md), [Orders Security Hardening](../../features/orders-security-hardening.md)
