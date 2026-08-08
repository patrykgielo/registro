---
name: project_email_template_tenant_scope_bug_2026-08-08
description: CRITICAL app bug — EmailTemplate's tenant global scope makes every transactional email fired from a real tenant HTTP request throw "template not found"; root-causes 2 of the 3 known pre-existing test failures
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

## What I did NOT do

Did not fix `BelongsToOrganization`, `EmailTemplate`, or `EmailService` — that's a product-code change,
outside a test-engineer's remit per `.claude/rules/agent-usage.md` (`laravel-senior-architect` territory).
The two new Browser tests assert the CURRENT real (broken) behavior instead, heavily commented as a live
bug, not a design choice, so that fixing this bug forces someone to come back and update those tests
deliberately (not silently drift green).

## Likely fix shape (not implemented, for whoever picks this up)

`EmailTemplate::where(...)` in `EmailService::sendFromTemplate()` needs
`->withoutGlobalScope('organization')` (or a dedicated scoped-vs-global template lookup strategy) since
these rows are intentionally NULL-org and meant to be visible regardless of tenant context. Verified via
mutation testing (temporarily adding `withoutGlobalScope('organization')` to the query) that this exact
change makes both Browser tests' current assertions fail — i.e. it genuinely fixes the underlying
problem and the tests are correctly wired to notice.
