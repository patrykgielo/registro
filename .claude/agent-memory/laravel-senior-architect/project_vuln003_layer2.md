---
name: project-vuln003-layer2
description: VULN-003 Layers 2-4 (BelongsToOrganization fail-closed + booking/cart session-fallback gaps) — mechanism, branches, and the test-writing pattern they require going forward
metadata:
  type: project
---

Branch `hardening/belongs-to-organization-fail-closed` (Layer 2, merged PR #103) adds
defense-in-depth to `BelongsToOrganization`'s global scope: it now fails closed (returns zero
rows) when no tenant is resolved AND `ResolveTenant` genuinely dispatched for the current request
— via a `tenant_resolution_attempted` request attribute set as the very first line of
`ResolveTenant::handle()`. Full design/rationale in
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

**Layer 3 (merged, PR #104) and Layer 4 (`fix/cart-checkout-order-cross-tenant-session-fallback`,
2026-07-03) — a DIFFERENT bug class from Layer 2, same VULN-003 doc.** `RequireTenant` gates on
the `tenant` REQUEST ATTRIBUTE, but several route groups predated `RequireTenant` and were never
retrofitted: `booking.*`/`appointments.*`/`profile.*` (Layer 3) and `cart.*`/`checkout.*`/
`orders.*` + `dev/fake-pay` (Layer 4). Their controllers all gated with
`abort_unless(TenantFeature::currentTenant() !== null, 404)` — and `currentTenant()`'s 3rd
fallback branch reads `session('tenant_id')`, written by `ResolveTenant` on ANY successful
subdomain visit (even anonymous, no auth). An authenticated customer who merely *visited* another
tenant's subdomain carries that tenant's ID into a root-domain request and passes the
`abort_unless` check against the WRONG tenant — real cross-tenant writes (`Appointment::create()`,
`CartItem`/`Order` rows). Fix is always the same one-liner: add `RequireTenant::class` right after
`ResolveTenant::class` in the vulnerable group.

**Established regression-test pattern for THIS bug class (Layers 3 and 4) — different from the
Layer 2 pattern above:** `withSession(['tenant_id' => $orgB->id])` to simulate the poisoned
session (NOT `actingAsTenant()`, which bypasses `ResolveTenant` entirely and would prove nothing)
+ assert 404 on real root-domain URLs. CRITICAL lesson from Layer 3's adversarial review,
reapplied in Layer 4: every negative test MUST reference a REAL DB row (service/order) under the
poisoned-session's target org, or route-model-binding/validation 404s for an unrelated reason and
the test is worthless — verify by temporarily removing `RequireTenant::class` and confirming the
negative tests fail via "reached the controller" symptoms (200/302 with a success flash, DB row
actually created), not a crash. Layer 4 also hit the `email_templates` gotcha on a write-path test
(`orders.cancel` triggers `OrderCancelled` → notification → missing-template 500 pre-fix) —
`Notification::fake()` in that specific test avoids the noise. Full details:
`app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md` (Layer 3 + Layer 4
sections), `.claude/rules/middleware.md`.
