# VULN-007: P24 Payment Reconciliation Gaps

**Status**: FIXED
**Severity**: HIGH
**Detected**: 2026-07-04 (multi-agent security review, 13 review domains)
**Fixed**: 2026-07-05
**Branch**: `fix/payment-reconciliation`

## Problem 1 — successful P24 payments for already-cancelled/expired orders were silently orphaned

`CartService::convertToOrder()` sets `expires_at = now()->addMinutes(20)`. The scheduled
`orders:cleanup-expired` command cancels any `pending_payment` order matched by
`Order::scopeExpired()`, with no exclusion for orders that already had a P24 transaction
registered (i.e. a customer actively mid-payment when the cron ran). `cancelled` is a terminal
state — if the genuine P24 success webhook arrived after the cron cancelled the order,
`transitionTo('paid')` threw, escaped to `WebhookController`'s catch-all (logged, still returned
HTTP 200 so P24 never retried), leaving a `Payment(status=success)` row with real captured money
against an order stuck `cancelled` forever — no email, no admin visibility, no `PaymentResource`
to discover it short of querying the DB directly.

## Problem 2 — CheckoutController left an orphaned order + emptied cart on P24 registration failure

`submit()` committed the Cart→Order conversion (marking the cart `converted`) BEFORE calling
`registerTransaction()`. If that call failed (P24 outage, network error), the customer saw a
generic error but their cart was already empty and the orphaned `pending_payment` order continued
blocking inventory until its TTL expired, with no compensation.

## Rozwiązanie

- `Order::ttlGraceMinutes()` — a single, clamped (`[0, 1440]` minutes) source of truth — is now
  used identically by both `Order::scopeExpired()` (an order with a registered `p24_token` gets
  an extended grace period before the cron cancels it) and `OrderItem::scopeBlockingAvailability()`
  (the same order keeps blocking the inventory it holds for exactly as long as the expiry scope
  considers it alive). A first-pass fix only touched the expiry scope, which review caught as
  actually *widening* an overbooking window (order alive per one scope, inventory freed per the
  other) — fixed by sharing one source of truth between both.
- `OrderStatusStateMachine` gained a narrow `cancelled -> paid` reconciliation transition, but —
  after review found the first-pass version was enforced only by comment/convention, not code —
  it's now gated by a `validatorForTransition()` override requiring a `Payment(status=success)`
  row to already exist, making the transition self-defending regardless of caller (not just the
  webhook path).
- `Przelewy24Service::handleWebhook()`: a lock-free pre-check short-circuits known-`paid`/unknown
  sessions; the live P24 `verify()` API call happens with NO database lock or transaction held
  (a first-pass fix wrapped the whole thing in one transaction, which review caught as holding a
  row lock across a ~30s-timeout external HTTP call — a DB-connection/PHP-FPM exhaustion risk
  under any P24 slowdown); only the final Payment-create + state-transition is wrapped in a short,
  re-locked transaction that re-checks idempotency (protects against a concurrent delivery
  completing while this one was blocked on the network call).
- Reconciliation (successful or blocked) sends `PaymentReconciliationAlertNotification` to all
  super-admins — proportionate content (order number, status, no PII/payment-card data), correctly
  not `ShouldBeUnique` on the multi-recipient send.

  > **Correction 2026-08-12:** the "fan-out lesson" this cited never happened — `ShouldBeUnique` is
  > inert on notifications in Laravel 12.60.2 and could not have dropped anyone's mail. Verified in
  > the framework source and empirically (5 recipients → 5 deliveries). Leaving it off is still
  > right, just not for the stated reason. See `.claude/rules/notifications.md`.
- `CheckoutController::submit()` now compensates a `registerTransaction()` failure: cancels the
  just-created order (`OrderService::cancel(..., notify: false)` — a new parameter added after
  review found the naive fix fired a confusing customer-facing "order cancelled" email
  immediately before a successful retry's real confirmation email) and reactivates the cart
  (`CartService::reactivate()`, which checks for an already-existing active cart first —
  best-effort, not a full atomic guarantee, documented as such).
- `transaction_grace_minutes` config is clamped defensively (a negative value would otherwise
  invert the intent and cancel in-flight P24 orders early; an unbounded value would disable
  expiry entirely for P24-registered orders).

## Verification

Two full review rounds (code-reviewer + agent-security-audit-specialist) plus a third targeted
follow-up, each independently re-verifying the prior round's fixes rather than trusting the
summary — this caught the state-machine bypass, the availability-scope desync, and the
lock-across-network-call issue, none of which were present in the first-pass implementation.
The state-machine guard was verified bidirectionally (reverted → new negative test fails,
restored → passes). Full suite: 808 passed, 3 pre-existing unrelated failures (baseline
unchanged), 5 skipped.

## Zapobieganie

- A state-machine transition that exists for one specific, narrow reconciliation scenario MUST be
  gated by `validatorForTransition()` (or equivalent), not left to caller discipline/comments —
  any future caller of the same `transitionTo()` method gets the same guard for free.
- Any two scopes/queries meant to describe "is this order still alive" for different purposes
  (cancellation eligibility vs. inventory-blocking) MUST share one computed value, or they will
  drift out of sync — exactly what happened here on the first pass.
- Never hold a DB row lock or open transaction across an external HTTP call (payment gateway
  APIs, webhooks) — acquire locks only around the short DB read/write phases before and after.

**Related**: [VULN-005](VULN-005-cart-rental-overselling-race.md) (same "grace-period"/availability
theme, different subsystem).
