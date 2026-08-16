---
name: project-offline-settlement-mode
description: Faza 1 payment-settlement-modes.md — offline (pay-at-pickup) checkout, implemented 2026-08-16 on feature/offline-settlement-mode
metadata:
  type: project
---

Faza 1 of `app/docs/features/payment-settlement-modes.md` shipped 2026-08-16
(`feature/offline-settlement-mode`, PR pending). Full detail lives in the doc
itself — this is the non-obvious part worth remembering for next time.

**Why:** UAT tenant `budowlana` could not close a single order — the only two
code paths that ever set `status=paid` required either live P24 credentials
or `APP_ENV!=production`. Any cash-at-pickup rental business was fully
blocked. See [[project_two_machines_uat_preprod]].

**The TTL decoupling turned out to need ZERO changes to `Order::scopeExpired()`
or `OrderItem::scopeBlockingAvailability()`.** Both scopes' "no P24 token"
branch already reads only `expires_at` — the fix was entirely in
`CartService::convertToOrder()` writing a different `expires_at` at creation
time (20 min fixed for online, unchanged; `SettingsManager::offlineReservationHoldHours()`
for offline, default 48h, clamped 1-168h). The two scopes staying in sync was
never actually at risk here — don't over-rotate on that risk next time this
area is touched; the real risk is always in the WRITE path (CartService), not
the two read-side scopes.

**New `TemplateKey` case needs a production data migration, not just the
seeder** — `EmailTemplateSeeder` only runs once, at first-ever tenant
provisioning. Already-provisioned stacks (UAT) never see a seeder-only key.
Pattern: `insertOrIgnore` + `organization_id => null`, `down()` scoped to that
key + null org only. Documented in `.claude/rules/migrations.md` now — a
recurring bug class (order-handed-over/order-returned, rental-return-*, and
this one all needed it; order-paid/order-confirmed/order-cancelled/
admin-new-order/rental-cancelled/service-area-available are STILL missing
their production migration — pre-existing, not fixed here, out of scope each
time it's been found).

**`OrderService::recordOfflinePayment()` dispatches `OrderPaid` OUTSIDE its
`DB::transaction()`** — deliberately different from `Przelewy24Service::handleWebhook()`'s
pre-existing pattern (which dispatches `event(new OrderPaid($order))` as the
last line INSIDE its transaction, undocumented pre-existing risk per
[[feedback_notify_inside_transaction]] if that memory exists, else see
`.claude/rules/notifications.md`). Do not copy Przelewy24Service's inside-transaction
placement as "the pattern" — it predates the notify()-inside-transaction rule
and was left alone (out of scope) rather than fixed on this branch.

**Key design choice:** `orders.settlement_method` (online/offline, checkout-time
customer choice) is a SEPARATE column from `payments.method` (p24/cash/bank_transfer,
what actually happened on a given Payment row) — collapsing these into one
concept would have broken the existing P24 reconciliation flow reading
`payments.status`. Also: no partial payments / amount validation on the
"odnotuj wpłatę" panel action — deliberately out of scope per product owner
("Bez zaliczek"), the amount field is staff-trusted free entry, not validated
against `order.total_amount`.

**File map for next session:** `app/Events/OrderAcceptedOffline.php`,
`app/Notifications/OrderAcceptedOfflineNotification.php` +
`app/Notifications/Concerns/BuildsOrderRentalEmailVariables.php` (extracted
from `OrderPaidNotification` so both notifications share the items-table/
deposit/pickup-address rendering), `OrderService::recordOfflinePayment()`,
`SettingsManager::availableSettlementMethods()`/`offlineReservationHoldHours()`,
panel action `record_offline_payment` duplicated in `OrderResource.php` (table)
and `Pages/EditOrder.php` (header) per the project's existing duplication
convention for Order actions.

**Not done in this phase (explicitly deferred):** settlement method annotation
on the handover protocol PDF; `frontend-ui-architect` review of the new
settlement-method radio picker in `checkout/show.blade.php`. Fazy 2-4
(gateway abstraction, multi-provider, marketplace onboarding) are unstarted —
see the doc for the P24/PayU/Tpay sandbox research already done for Faza 2.
