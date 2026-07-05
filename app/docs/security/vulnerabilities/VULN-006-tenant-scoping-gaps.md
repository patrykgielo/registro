# VULN-006: Tenant-Scoping Gaps (ServiceAreaWaitlist Admin Leak + Registration-Toggle Session Fallback)

**Status**: FIXED
**Severity**: HIGH (finding 1), LOW (finding 2)
**Detected**: 2026-07-04 (multi-agent security review, 13 review domains)
**Fixed**: 2026-07-05
**Branch**: `fix/tenant-scoping-gaps`

## Problem 1 (HIGH) — ServiceAreaWaitlistResource cross-tenant PII leak

`app/Models/ServiceAreaWaitlist.php` has no `organization_id`/`BelongsToOrganization` — a known,
accepted design gap for the public submission API only (see VULN-003's Gap #2 follow-up:
"does a waitlist entry even belong to one tenant?"). But
`app/Filament/Resources/ServiceAreaWaitlists/ServiceAreaWaitlistResource.php` had no
authorization at all beyond `$module = 'service_area'` (any tenant's own super-admin can enable
that module) — any tenant with the module on could browse Settings → "Lista Oczekujących" and
see **every other tenant's** waitlist leads: name, email, phone, requested address, GPS
coordinates.

## Rozwiązanie 1

Gated `canViewAny()`/`canView()`/`canEdit()`/`canDelete()`/`canDeleteAny()`/`canCreate()` on
`auth()->user()?->hasRole('super-admin') ?? false`, and fixed `getNavigationBadge()` to return
`null` for non-super-admins — mirroring the established pattern already used for other
genuinely-global, no-natural-tenant-owner resources in this codebase (`AuditLogResource`,
`EmailEventResource`, `SmsEventResource`). Confirmed via Spatie's `config/permission.php`
(`'teams' => false`) that roles in this app are global, not per-organization, so
`hasRole('super-admin')` is a sufficient and correct check — a tenant admin has no path to grant
themselves that role (`UserResource`, where roles are assigned, is itself super-admin-only).

Kept the Resource in the `/admin` (tenant) panel rather than moving it to `/platform` — consistent
with the established convention above, and `canViewAny()` fully blocks the route regardless of
which panel discovers it.

Verified (two independent reviews, one via a live HTTP 403 reproduction) no bypass via Filament
global search (this Resource has no `$recordTitleAttribute`, so it isn't globally searchable
regardless of role), no relation manager or widget queries this model outside the Resource's own
gates, and Filament's authorization check (`CanAuthorizeResourceAccess`) re-runs on every
subsequent Livewire hydrate, not just the initial page load.

## Problem 2 (LOW) — CheckRegistrationEnabled session-fallback

`CheckRegistrationEnabled::handle()` resolved the registration-enabled toggle via
`SettingsManager::isRegistrationEnabled()` → `TenantFeature::currentTenant()`'s session-fallback
branch — the same class of bug as VULN-003 Layers 3-5, documented there as a known follow-up.
Confirmed lower severity because `RegisterController` never used this for the actual
org-attachment decision (already read `$request->attributes->get('tenant')` directly) — a
poisoned session could only cause the wrong tenant's registration on/off toggle to be honored, a
UX glitch, not a cross-tenant account-creation bug.

## Rozwiązanie 2

`CheckRegistrationEnabled` now reads `$request->attributes->get('tenant')` directly (defensively
typed against anything but a real `Organization` instance) and calls a new
`SettingsManager::isRegistrationEnabledFor(?Organization $organization)` / `getForOrganization()`
pair that takes an explicit tenant parameter and never touches `TenantFeature::currentTenant()`/
session. `get()`/`isRegistrationEnabled()` now delegate to these explicit-tenant variants (DRY'd
after review flagged the initial pair as near-duplicate, driftable logic) — one source of truth
for the tenant/global-fallback query. The one remaining `isRegistrationEnabled()` call site
(`AppServiceProvider`'s nav-link view composer) is intentionally left on the session-fallback
default — purely cosmetic (show/hide a "Zarejestruj się" link), not a security boundary.

## Verification

Two independent review rounds (code-reviewer + agent-security-audit-specialist): live HTTP-level
403 reproduction for the ServiceAreaWaitlistResource fix, confirmed Spatie roles are global (not
team-scoped) so the chosen check is sufficient, confirmed `$request->attributes->get('tenant')`
has exactly one writer in the whole codebase (`ResolveTenant::handle()`) and cannot be
client-tampered, and confirmed the registration-toggle fix's cache-key format matches the
existing convention (no cross-tenant cache contamination).

Full suite: 793 passed, 3 pre-existing unrelated failures (baseline unchanged), 5 skipped.

## Zapobieganie

- Any Filament Resource for a model with no natural tenant owner (`BelongsToOrganization` absent)
  MUST explicitly gate `canViewAny()`/etc. on a role check — `$module` flags control navigation
  visibility only, never authorization.
- Any middleware/service reading a per-tenant setting for a security-relevant decision must take
  an explicit tenant (or the request-attribute tenant), never `TenantFeature::currentTenant()`'s
  session-fallback-capable default — same rule as VULN-003 Layers 3-5.

**Related**: [VULN-003](VULN-003-root-domain-tenant-bypass.md) (same session-fallback bug class),
[VULN-004](VULN-004-template-rendering-rce.md), [VULN-005](VULN-005-cart-rental-overselling-race.md).
