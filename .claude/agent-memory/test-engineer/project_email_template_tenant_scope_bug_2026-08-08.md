---
name: project_email_template_tenant_scope_bug_2026-08-08
description: FIXED — EmailTemplate/SmsTemplate's tenant global scope made every transactional email fired from a real tenant HTTP request throw "template not found"; root-caused and resolved 2 of the (then) 3 known pre-existing test failures
metadata:
  type: project
---

Found while writing `tests/Browser/OrderLifecycleEmailTest.php` and `tests/Browser/OrderCancellationTest.php`
(task: two new real-browser tests that do NOT `Notification::fake()`, to finally exercise the real
Notification -> EmailServiceChannel -> EmailService -> `email_templates` lookup layer).

## The bug

`App\Models\EmailTemplate` uses `App\Traits\BelongsToOrganization`. That trait's global scope does:

```php
$tenant = TenantFeature::currentTenant();
if ($tenant) {
    $builder->where($table.'.organization_id', $tenant->id);
    return;
}
```

`database/seeders/EmailTemplateSeeder.php` seeds every row with **no `organization_id` key at all**
(NULL) — correct, per `.claude/rules/migrations.md`'s own documented exception: `email_templates` is
meant to be a global, NULL-org table.

`App\Services\Email\EmailService::sendFromTemplate()` queries it with a bare
`EmailTemplate::where('key', ...)->where('language', ...)->where('active', true)->first()` — no
`withoutGlobalScope()` anywhere in that file.

Net effect: the instant this query runs while ANY tenant is resolved (i.e. during literally any real
customer/admin action on a tenant subdomain — which is nearly all production traffic), the global
scope adds `organization_id = <tenant->id>`, but every template row has `organization_id = NULL`.
Zero rows match. `EmailService` throws `Exception("Email template '{key}' not found for language
'{lang}'.")` **before it ever creates an `EmailSend` row** (the exception is Step 3 in
`sendFromTemplate()`, `EmailSend::create()` is Step 7).

## Why nothing caught this before

Every existing order/rental test under `tests/Feature` uses `Notification::fake()`, which short-circuits
before the channel ever runs — this exact layer was untested project-wide. The handful of tests that DO
call `EmailService::sendFromTemplate()` directly (`tests/Feature/Email/EmailRetryTest.php`) never go
through `ResolveTenant`, so `TenantFeature::currentTenant()` is null there and the scope no-ops. The
tenant-registration emails verified working on production (see root `MEMORY.md`'s "VPS Deploy" entry)
fire on the ROOT domain, before any tenant is ever resolved — same reason.

## Confirmed blast radius (verified by running real code, not inferred)

- **Admin confirms an order** (`EditOrder`'s "Potwierdź" header action, or the identical table action
  in `OrderResource.php`): the state machine's `$model->save()` runs BEFORE `afterTransitionHooks()`
  (verified in `vendor/asantibanez/laravel-eloquent-state-machines/src/StateMachines/StateMachine.php`),
  so the order genuinely transitions to `'confirmed'` — but `OrderConfirmedNotification`'s send throws,
  caught by `EditOrder`'s try/catch, and the admin sees a **false** error toast ("Nie można potwierdzić
  zamówienia") for an action that actually succeeded. No `order-confirmed` email ever exists.
- **Customer cancels an order** (`OrderController::cancel()`, no try/catch at all): the order DOES
  become `'cancelled'` in the DB (same save-before-hooks reason), but `OrderService::cancel()`'s own
  `$order->update(['cancelled_at' => now()])` runs AFTER `transitionTo()` and never executes — so
  `cancelled_at` stays NULL forever. The customer gets a genuine, unhandled **500 Server Error** (raw
  Laravel error page body, confirmed via `$page->script()` DOM dump, not inferred from a redirect).
  No `order-cancelled` email ever exists.
- **This is not narrow to two templates.** Same query shape, same bug, for every other
  `EmailService`-backed notification fired from inside a tenant request: `admin-new-order`,
  `rental-cancelled`, appointment notifications, etc.
- **Root-causes 2 of the 3 known pre-existing `php artisan test` failures**: re-ran the full suite after
  adding the two Browser tests above — `tests/Feature/Orders/CustomerOrdersTest.php`'s two `cancel`
  tests fail with the exact same `"Email template 'order-cancelled' not found for language 'pl'."`
  exception. Previously logged in memory only as "cancel flow, email-template lookup" with no root
  cause identified — now identified.

## STATUS: FIXED (2026-08-08, same day, by `laravel-senior-architect`)

At the time this file was first written, the fix below was correctly out of a test-engineer's remit
(product-code change, `BelongsToOrganization`/`EmailTemplate`/`EmailService` territory per
`.claude/rules/agent-usage.md`) — the two new Browser tests asserted the CURRENT real (broken)
behavior instead, heavily commented as a live bug. **That fix has since shipped in the same commit
this file lives in.** Do not treat the "not implemented" framing below as current — it describes what
was true before the fix, kept for the historical trail, not as a task list.

## The shipped fix

- `EmailTemplate::resolveActive(string $key, string $language): ?self` and the identical
  `SmsTemplate::resolveActive()` (both models) — explicit `withoutGlobalScope('organization')`
  replaced with a narrower, hand-written condition: `organization_id IS NULL OR organization_id =
  <current tenant id>`, tenant-specific row preferred via `orderByRaw('organization_id IS NULL')`.
  Cross-tenant-safe by construction — the `orWhere` only ever adds the CALLER's own resolved tenant
  id, never any other tenant's.
- `EmailService::sendFromTemplate()` / `SmsService::sendFromTemplate()` call `resolveActive()` instead
  of the old plain `::where(...)->first()`.
- Console/queue-worker context (Horizon): `TenantFeature::currentTenant()` resolves nothing there (no
  Filament tenant, no request attribute, no session) — `resolveActive()` degrades to "global template
  only," a deliberate, documented limitation, not a regression (there was no reachable override
  mechanism there before this fix either).
- Migration `2026_08_08_100001_scope_template_uniques_to_organization.php`: the old `(key, language)`
  unique made a tenant override collide with the global row it was meant to override, so overrides
  were schema-impossible even though both models always carried `organization_id`. Converted to
  composite `(organization_id, key, language)` — deliberately reverses the 2026-06-29 migration's
  decision to skip these two tables from that treatment; safe only because both seeders
  (`EmailTemplateSeeder`, `SmsTemplateSeeder`) were also fixed in the same change to match
  `organization_id IS NULL` explicitly on re-seed, so re-seeding can never touch a tenant's override
  or create a duplicate global row.
- `tests/Browser/OrderLifecycleEmailTest.php` / `tests/Browser/OrderCancellationTest.php`: rewritten
  to assert the NOW-CORRECT behavior — cancellation completes cleanly with `cancelled_at` set and a
  real `order-cancelled` `EmailSend` row, `order-confirmed` genuinely sends and the admin sees the real
  success toast. `OrderLifecycleEmailTest`'s two still-real, untouched gaps (dev payment bypass never
  dispatches `OrderPaid`; `in_progress`/`completed` have no notification hooks at all) are preserved
  deliberately — its final count assertion is `1`, not `0` and not `4`.
- `tests/Feature/Email/TemplateResolutionTest.php` (new): Feature-level coverage of `resolveActive()`
  itself for BOTH models — tenant override wins, global fallback, tenant A cannot resolve tenant B's
  override, console/no-tenant-context resolves only the global row. Every assertion mutation-verified
  (temporarily reverting `resolveActive()` to a naive scoped query and confirming the relevant test
  fails, then restoring) to actually catch a regression, not just exercise the happy path.

## Verified after the fix

- `./vendor/bin/pint --test` — clean.
- `php artisan test` (Unit+Feature) — 1 failed (unrelated `TenantFeatureTest` booking-wizard 404), 5
  skipped, 1062+ passed. Both `CustomerOrdersTest` cancel-flow tests that used to fail on this exact
  bug are green.
- `php artisan test --testsuite=Browser` — all Browser tests pass, including the two rewritten ones.
