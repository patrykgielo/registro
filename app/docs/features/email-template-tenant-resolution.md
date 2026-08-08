# Email/SMS Template Lookup — Tenant Resolution

## Problem (found 2026-08-08)

`EmailTemplate` and `SmsTemplate` both use `BelongsToOrganization`, whose global scope
restricts every query to `organization_id = <current tenant>` the instant a tenant is
resolved. But every seeded template has `organization_id = NULL` (global by design). Net
effect: `EmailService::sendFromTemplate()` / `SmsService::sendFromTemplate()` used a plain
`::where('key', ...)->where('language', ...)->first()`, which found **nothing** whenever a
tenant was resolved — i.e. for essentially every real customer/admin action. Every order
email (`order-paid`, `order-confirmed`, `order-cancelled`, `admin-new-order`) threw
`"Email template '...' not found"`. `OrderController::cancel()` has no try/catch, so a
customer cancelling an order got a bare 500. Filament's admin "confirm" action does have a
try/catch, so admins saw a silent false-negative error toast for an action that had actually
succeeded server-side (the state machine persists before `afterTransitionHooks()` runs).

Tenant *registration* email (sent from the root domain, no tenant resolved) worked, which is
why this went unnoticed.

## Fix

`EmailTemplate::resolveActive(string $key, string $language)` and the identical
`SmsTemplate::resolveActive()` (app/Models/EmailTemplate.php, app/Models/SmsTemplate.php):
bypass the trait's global scope deliberately (`withoutGlobalScope('organization')`) and
replace it with an explicit one — current tenant's own override (`organization_id = tenant
id`) OR the global row (`organization_id IS NULL`), **never** another tenant's row. Tenant
override wins over the global fallback via `orderByRaw('organization_id IS NULL')`.
`EmailService`/`SmsService::sendFromTemplate()` call this instead of a plain
`::where()->first()`.

**Queue-worker context:** `TenantFeature::currentTenant()` has no request or Filament tenant
to resolve in a Horizon/queue worker process — this is a deliberate, accepted limitation:
per-tenant template overrides do not apply to queued notification sends, they only ever
resolve the global template there. Not a regression — there was no override mechanism
reachable at all before this fix.

**Migration required:** the original `(key, language)` unique constraint on both tables made
a tenant override collide with the global row it was meant to override (same key+language),
so overrides were schema-impossible even though the model always carried `organization_id`.
`2026_08_08_100001_scope_template_uniques_to_organization.php` converts both to composite
`(organization_id, key, language)` — same trade-off already accepted for `categories` in
`2026_06_29_120000_fix_tenant_scoped_unique_constraints.php` (MySQL/SQLite treat each NULL as
distinct, so this no longer blocks a second accidental global row; nothing in the seeders
creates one deliberately).

## Do not

- Don't remove `withoutGlobalScope('organization')` from `resolveActive()` — that re-enables
  the original defect (unreachable global templates the instant a tenant is resolved).
- Don't add `orWhere('organization_id', ...)` for anything other than the resolved
  `TenantFeature::currentTenant()->id` — that is the cross-tenant boundary this fix exists to
  hold. See `EmailTemplate::resolveActive()`'s docblock for the full argument.
