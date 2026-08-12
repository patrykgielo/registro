# Order Email Notifications

**Implemented:** 2026-03-29
**Extended:** 2026-08-12 (`feature/handover-return-emails`) — handover + return

---

## Overview

Transactional email notifications for the full Cart → Order lifecycle.
Follows the existing `AppointmentCreatedNotification` pattern exactly:
`EmailServiceChannel` + DB templates + `ShouldBeUnique` + `'emails'` queue.

Until 2026-08-12, a customer received exactly one email in the entire rental
lifecycle (payment confirmation) — the two transitions where equipment
physically changes hands (`confirmed → in_progress`, `in_progress →
completed`) fired nothing at all, both for the customer and as a company-side
send record. Handover and return notifications close that gap.

---

## Notification Matrix

| Trigger | Recipient | Template Key | Notification Class |
|---------|-----------|-------------|-------------------|
| Payment confirmed (P24 webhook) | Customer | `order-paid` | `OrderPaidNotification('customer')` |
| Payment confirmed (P24 webhook) | Org owner (admin) | `admin-new-order` | `OrderPaidNotification('admin')` |
| Admin confirms order (`paid → confirmed`) | Customer | `order-confirmed` | `OrderConfirmedNotification` |
| Admin hands over equipment (`confirmed → in_progress`, "Wydano klientowi") | Customer | `order-handed-over` | `OrderHandedOverNotification` |
| Admin accepts return (`in_progress → completed`, "Sprzęt zwrócony") | Customer | `order-returned` | `OrderReturnedNotification` |
| Order cancelled (`* → cancelled`) | Customer | `order-cancelled` | `OrderCancelledNotification` |

**No admin copy for handover/return** — unlike `OrderPaid`, both transitions are
triggered by the admin themselves through the Filament UI, so there is no new
information reaching them that they didn't already cause. This mirrors
`OrderConfirmed`/`OrderCancelled` (also admin-triggered, customer-only) rather
than `OrderPaid` (webhook-triggered, genuinely new to the admin — hence the
`'admin'` variant there).

---

## Event Flow

```
Przelewy24Service::handleWebhook()
  └─ $order->status()->transitionTo('paid')
  └─ event(new OrderPaid($order))            ← dispatched directly

OrderStatusStateMachine::afterTransitionHooks()
  └─ 'confirmed'   → event(new OrderConfirmed($model))
  └─ 'in_progress' → event(new OrderHandedOver($model))
  └─ 'completed'   → [1] if (completed_at === null) $model->update(['completed_at' => now()])
                      [2] event(new OrderReturned($model))
                      — TWO independent callables, deliberately not sharing a guard; see that
                      hook's own comment for why coupling them would silently drop the email
                      the moment anything sets completed_at outside this hook (backfill, import,
                      data migration)
  └─ 'cancelled'   → event(new OrderCancelled($model))

AppServiceProvider::registerEventListeners()
  └─ OrderPaid       → user->notify(OrderPaidNotification('customer'))
                     → org->owner->notify(OrderPaidNotification('admin'))
  └─ OrderConfirmed  → user->notify(OrderConfirmedNotification)
  └─ OrderHandedOver → user->notify(OrderHandedOverNotification)
  └─ OrderReturned   → user->notify(OrderReturnedNotification)
  └─ OrderCancelled  → user->notify(OrderCancelledNotification)
```

---

## Files

**New (2026-03-29):**
- `app/Events/OrderPaid.php`
- `app/Events/OrderConfirmed.php`
- `app/Events/OrderCancelled.php`
- `app/Notifications/OrderPaidNotification.php`
- `app/Notifications/OrderConfirmedNotification.php`
- `app/Notifications/OrderCancelledNotification.php`

**New (2026-08-12):**
- `app/Events/OrderHandedOver.php`
- `app/Events/OrderReturned.php`
- `app/Notifications/OrderHandedOverNotification.php`
- `app/Notifications/OrderReturnedNotification.php`
- `database/migrations/2026_08_12_120000_seed_order_handover_return_email_templates.php` —
  production data migration for the 2 new keys; see "Existing-tenant provisioning" below for why
  this is required in addition to `EmailTemplateSeeder`
- `tests/Feature/Orders/OrderHandoverReturnNotificationTest.php`
- `tests/Feature/Database/OrderHandoverReturnEmailTemplateMigrationTest.php` — pins the migration's
  `up()`/`down()`, that it never touches unrelated rows or a tenant's own override, and that both
  keys resolve from production migrations ALONE (i.e. without `EmailTemplateSeeder`, which every
  other test in this suite relies on via `TestCase::setUp()` and which would otherwise mask exactly
  this class of bug)

**Modified:**
- `app/Enums/TemplateKey.php` — 6 order-lifecycle cases: `ORDER_PAID`, `ORDER_CONFIRMED`,
  `ORDER_CANCELLED`, `ORDER_HANDED_OVER`, `ORDER_RETURNED`, `ADMIN_NEW_ORDER`
- `app/Providers/AppServiceProvider.php` — event listeners in `registerEventListeners()`
- `app/Services/Payment/Przelewy24Service.php` — `event(new OrderPaid($order))` after `transitionTo('paid')`
- `app/StateMachines/OrderStatusStateMachine.php` — `afterTransitionHooks()` for `confirmed`,
  `in_progress`, `completed`, and `cancelled`
- `database/seeders/EmailTemplateSeeder.php` — 12 templates (6 keys × 2 languages) — dev/test only,
  see "Existing-tenant provisioning" below
- `tests/Browser/OrderLifecycleEmailTest.php` — updated to assert the new handover/return emails
  instead of pinning their absence (see that file's own docblock for the full before/after)

---

## Existing-tenant provisioning (why there's a data migration too)

`EmailTemplateSeeder` runs exactly once per stack, at first-tenant provisioning
(`ProvisionTenantCommand::runGlobalSeedersOnce()`, gated by `TenantProvisioningState` so a re-run
never overwrites a tenant's customized templates — see that method's own docblock). Every
already-provisioned stack — including UAT's `budowlana` — never runs it again, so adding a key only
to the seeder means that stack's first handover/return attempt fails with "template not found"
straight into `failed_jobs`, unmonitored (the exact class of bug behind the 2026-08-08
tenant-scope incident, just one seeding-mechanism removed).

`database/migrations/2026_08_12_120000_seed_order_handover_return_email_templates.php` follows the
established pattern (`2025_12_02_224732_seed_email_templates.php`,
`2026_07_07_000001_seed_rental_extension_email_templates.php`,
`2026_08_02_000001_seed_tenant_registration_email_templates.php`): `insertOrIgnore()` with explicit
`organization_id => null`, so it only ever inserts the two new global rows and never touches an
existing row (including a tenant's own override of the same key, should one already exist) —
`down()` mirrors that, deleting only `key IN (...) AND organization_id IS NULL`.

**A repo-wide audit while building this found the same gap, pre-existing, for `order-paid`,
`order-confirmed`, `order-cancelled`, `admin-new-order`, `rental-cancelled` and
`service-area-available`** — none of them are in any production data migration either, only in
`EmailTemplateSeeder`. Not fixed here (out of this branch's scope — six unrelated keys, each
deserving its own reviewed change, not a drive-by); reported separately.

---

## `ShouldBeUnique` + `message_key` — can handover/return collide within 5 minutes?

No, for two independent reasons:

1. **Different lock identity, different dedup identity.** `uniqueId()` returns
   `'order-handed-over:'.$order->id` vs `'order-returned:'.$order->id` — different strings, and
   `EmailService`'s `message_key = md5(template_key:recipient:metadata)` differs too, since
   `template_key` itself differs (`order-handed-over` vs `order-returned`). Neither mechanism has
   any shared key for these two notifications to collide on, regardless of timing.
2. **`ShouldBeUnique` on a `Notification` subclass has no effect in this Laravel version anyway**
   (verified empirically, not just by reading the source: a throwaway test spied on
   `EmailService::sendFromTemplate` and called `notify()` twice in a row with the *same*
   notification instance — the spy was invoked twice, not once). `Illuminate\Notifications\NotificationSender::queueNotification()`
   dispatches the queued job via a direct `Bus::dispatch()` call on a manually-built
   `SendQueuedNotifications` instance — which does **not** itself implement `ShouldBeUnique` — and
   `Illuminate\Bus\UniqueLock::acquire()` is only ever invoked from
   `Illuminate\Foundation\Bus\PendingDispatch` (the `SomeJob::dispatch()` static helper), a path
   notifications never go through. This appears to make `ShouldBeUnique` inert for **every**
   notification in this codebase today, not just these two — the actual protection against a
   duplicate resend of the *same* notification has always been `EmailService`'s `message_key`
   UNIQUE constraint + `isRetryable()`, not Laravel's queue-level lock. Pre-existing, systemic,
   unrelated to handover/return specifically; not fixed here — flagged separately, since "fixing"
   it touches 8+ existing notification classes and this rule file's own guidance and deserves its
   own investigation and consensus on the right replacement mechanism.

---

## Known gap — no admin-facing visibility into whether a customer actually got these emails

There is no way for an admin to answer "did the customer get the handover email" from the UI today.
`email_sends` (via `EmailSend::metadata->order_id`) is the only evidence it happened at all —
`EmailSendResource` has no filter on `metadata->order_id`, and neither `OrderResource` nor
`EditOrder` has a relation manager or infolist section showing the order's related sends. This
predates handover/return (the same gap already existed for `order-paid`/`order-confirmed`/
`order-cancelled`) and is not introduced here — noted so it is not rediscovered from scratch, not
attempted in this branch.

---

## Template Variables

| Key | Variables |
|-----|-----------|
| `order-paid` | `customer_name`, `order_number`, `total_amount`, `orders_url`, `app_name`, `items_list_html`, `items_list_text`, `deposit_amount`, `pickup_address`, `pickup_phone` |
| `order-confirmed` | `customer_name`, `order_number`, `orders_url`, `app_name` |
| `order-handed-over` | `customer_name`, `order_number`, `orders_url`, `app_name`, `items_list_html`, `items_list_text` |
| `order-returned` | `customer_name`, `order_number`, `orders_url`, `app_name`, `items_list_html`, `items_list_text` |
| `order-cancelled` | `customer_name`, `order_number`, `reason`, `orders_url`, `app_name` |
| `admin-new-order` | `customer_name`, `order_number`, `total_amount`, `admin_url`, `app_name` |

`order-handed-over`/`order-returned` reuse `OrderPaidNotification::buildRentalVariables()`'s item-table
approach (own copy per notification, same style as the rest of this file — see `models.md`'s "no
unnecessary abstractions" convention) but omit `deposit_amount`/`pickup_address`/`pickup_phone`: the
deposit lifecycle and pickup logistics are out of scope for these two emails (deliberately untouched
— see the design decisions below), and the equipment is already with/returned from the customer by
the time either fires.

---

## Design Decisions

- **OrderPaid dispatched in service, not state machine** — the P24 webhook also needs to `update(['paid_at' => now()])` after transition; keeping both calls together in the service is simpler and avoids the `paid` hook firing for any future programmatic `transitionTo('paid')` in tests.
- **`afterTransitionHooks()` for confirmed/in_progress/completed/cancelled** — all four transitions always come from admin UI actions (`OrderResource` row actions / `EditOrder` header actions, same call sites for both), so hooking the state machine is the single source of truth; it covers any future Artisan commands or API calls that trigger the same transition, rather than duplicating the dispatch in both Filament call sites.
- **`in_progress` has no timestamp column** — deliberately out of scope for this change (no `handed_over_at` was added); the `OrderHandedOver` event dispatch itself is the only record of when the transition happened, recoverable from `order_status_history` (the state machine's own audit trail) if needed.
- **`completed`'s event and its `completed_at` write deliberately do NOT share a guard** — see that hook's own comment in `OrderStatusStateMachine.php`. Coupling "was completed_at already set" with "should we email" would silently skip the email if anything ever set `completed_at` outside this hook (backfill, import, data migration) before a genuine `transitionTo('completed')` call. The timestamp write keeps its own null-guard as defense-in-depth for the (currently unreachable) re-entry case; the email dispatch has none, matching every other hook in this method.
- **No admin copy for handover/return** — see the Notification Matrix section above for the full reasoning (admin already knows, since they triggered it).
- **Null-safe user check** — orders in theory always have a `user_id` (checkout requires auth), but each listener logs a warning and skips rather than crashing if the relation is missing.
- **Email only, no SMS** — rental orders are email-only per project spec.
- **`total_amount` formatted as `number_format(..., 2, ',', ' ')`** — Polish locale formatting.
