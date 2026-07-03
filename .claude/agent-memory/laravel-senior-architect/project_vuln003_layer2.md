---
name: project-vuln003-layer2
description: VULN-003 Layer 2 hardening (BelongsToOrganization fail-closed) — mechanism, branch, and the test-writing pattern it requires going forward
metadata:
  type: project
---

Branch `hardening/belongs-to-organization-fail-closed` (off `develop`, not yet merged as of
2026-07-03) adds defense-in-depth to `BelongsToOrganization`'s global scope: it now fails
closed (returns zero rows) when no tenant is resolved AND `ResolveTenant` genuinely dispatched
for the current request — via a `tenant_resolution_attempted` request attribute set as the very
first line of `ResolveTenant::handle()`. Full design/rationale in
`app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md` (Layer 2 section) and
`.claude/rules/models.md`.

**Why:** Layer 1 (merged, PR #101) fixed VULN-003 per-route via `RequireTenant::class` — correct
but not self-enforcing; any future route querying a `BelongsToOrganization` model that forgets
`RequireTenant` reopens the same leak. Layer 2 closes it at the trait itself.

**How to apply — CRITICAL for any new Feature test that both (a) creates a
`BelongsToOrganization` model directly and (b) makes a real HTTP request
(`$this->get()`/`->post()`) through a route carrying `ResolveTenant::class`:** the request MUST
either simulate a tenant or the test must expect empty/404 results. `route()` helper resolves to
the root domain configured in `.env.testing` (`APP_DOMAIN=registro.local`) by default — so ANY
test hitting a `route('booking.*')`/`route('appointments.*')`-style URL without simulating a
tenant will now see empty results, not "all data" (the old, vulnerable no-op behavior it may
have unknowingly relied on).

**Established fix pattern (used in `BookingConfirmationSecurityTest`,
`BookingConfirmationSecurityMinimalTest`, `BookingServiceAreaBypassTest`):**
1. `$org = Organization::factory()->autoDetailing()->create();` (or whichever industry fits —
   `autoDetailing` gives `vehicles`+`mobile_service`+`service_area` features, useful for booking
   wizard tests).
2. `$this->app['request']->attributes->set('tenant', $org);` in `setUp()`, BEFORE any other
   model creation — makes `BelongsToOrganization`'s `creating` hook auto-assign
   `organization_id` for everything created afterward in the test (seeders, factories, direct
   `Model::create()` calls). Does NOT affect real HTTP calls (those get a fresh Request object).
3. `actingAsTenant(Organization $org)` helper (bind a `ResolveTenant::class` test double in the
   container that sets the `tenant` attribute directly) — chain `->actingAsTenant($org)` onto
   every `$this->get()`/`->post()` call. This is the project's existing established pattern (also
   in `TenantFeatureTest`, `CustomerOrdersTest`) — note it bypasses `ResolveTenant::handle()`
   entirely, so `tenant_resolution_attempted` is never set for those calls (irrelevant — the
   scope's "tenant found" branch fires instead, unaffected by Layer 2 either way).
4. `Notification::fake();` if the flow sends any notification — see the `email_templates`
   global/NULL-org gotcha in the Testing section of MEMORY.md.

**Side effect discovered:** fixing 3 test files this way also fixed 4 pre-existing
`BookingServiceAreaBypassTest` failures — their root cause was identical (no tenant context ⇒
`TenantFeature::active('service_area')` always false ⇒ validation silently skipped). Not
guaranteed for every future case, but worth checking: a test failing due to "missing tenant
context" may be masking an unrelated, also-broken assertion.
