# VULN-005: Cart/Rental Overselling via Unlocked Availability Checks

**Status**: FIXED
**Severity**: CRITICAL
**Priority**: P0
**Detected**: 2026-07-04 (multi-agent security review, 13 review domains)
**Fixed**: 2026-07-05
**Branch**: `fix/cart-rental-reservation-integrity`

## Problem

`CartService::convertToOrder()` — the method that actually commits a rental reservation by
creating `Order`/`OrderItem` rows — performed **no availability check at all**. It only locked
the `Cart` row and blindly copied whatever quantity sat in each `CartItem` into an `OrderItem`.
`RentalAvailabilityService::getAvailableQuantity()` only ever subtracted quantities reserved
via `Rental` rows and `OrderItem::blockingAvailability()` — CartItem rows sitting in *other
users'* active carts never counted against availability at all. Two customers could each add
the last unit of a `quantity_total=1` item to their own separate carts (both checks pass, since
carts don't reduce reported stock) and both successfully check out, creating two `Order`s for
one physical item.

The equivalent admin-panel write path (`RentalResource`'s Filament create/edit pages) had the
same gap: any admin could create or edit a `Rental` row with an arbitrary quantity with zero
call to the availability service at all.

## Przyczyna (Root Cause) — and the deeper bug found while fixing it

The first-pass fix (lock the `Service` row + re-check availability inside
`convertToOrder()`'s transaction) turned out to have **two** layered bugs, both caught during
review before merge:

1. **Dead-code lock.** `$cart->refresh()->lockForUpdate();` discarded the query builder
   `lockForUpdate()` returns without calling a terminal method (`->first()`/`->get()`) —
   `Model::lockForUpdate()` only sets a flag on a fresh `Builder`; nothing executes without a
   terminal call. **No SQL ran, no lock was ever acquired.** Caught independently by both
   `code-reviewer` and `agent-security-audit-specialist` via query-log inspection.

2. **MVCC snapshot race, deeper than the first fix.** Even after fixing (1) and adding a real
   `Service::lockForUpdate()`, the availability re-check still used
   `OrderItem::blockingAvailability()`, which was a correlated `whereHas('order', ...)` — an
   `EXISTS` subquery against `orders`. Under MySQL's default REPEATABLE READ, a transaction's
   plain (non-locking) reads all share the snapshot fixed by its first consistent read — a
   `SELECT ... FOR UPDATE` on a *different* row (the `Service`) does not reset that snapshot for
   later plain reads. So a transaction that queued on the Service lock and resumed after the
   winner committed could still evaluate the correlated `EXISTS` against its own pre-lock
   snapshot, computing "1 available" even though the winner had already committed its
   `OrderItem`. **Empirically reproduced with two real concurrent MySQL connections** (forced
   interleaving via `DB::listen()` + a flag-file handshake + `sleep()` inside the held
   transaction) before being fixed, and reproduced-as-fixed after.

## Rozwiązanie (Fix)

- `OrderItem::scopeBlockingAvailability()` converted from a correlated `whereHas('order', ...)`
  to a real SQL `INNER JOIN` against `orders` (+ `->select('order_items.*')` to prevent column
  pollution from the join). A JOIN's rows are part of the *same* statement's row set as the
  outer query, so they ARE covered by an outer `FOR UPDATE` — unlike a correlated subquery's own
  table, which is evaluated independently. See the docblock on this method for the full
  MVCC reasoning.
- `RentalAvailabilityService::getAvailableQuantity()` gained a `$forUpdate` parameter: when
  `true`, its internal `rentals`/`order_items` count queries themselves become locking reads
  (bypassing snapshot timing entirely — the actual mechanism that closes the race, not the
  `Service` lock alone). Write paths (`CartService::addItem()`/`updateQuantity()`/
  `convertToOrder()`, and the Filament `CreateRental`/`EditRental` pages) pass `true`; read-only
  display callers (e.g. the frontend "X available" widget) keep the default `false` so unrelated
  readers aren't serialized for no benefit.
- `CartService::convertToOrder()`'s cart-status guard now uses a genuine locking read
  (`Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail()`), closing the dead-code gap.
- `app/Filament/Resources/RentalResource/Pages/{Create,Edit}Rental.php` now lock the `Service`
  row and re-check availability (`forUpdate: true`, `EditRental` additionally excludes its own
  existing reservation via `excludeRentalId`) inside a `DB::transaction()`, with a `Notification`
  + `Halt` on insufficient stock — closing the previously-unlocked admin write path into the same
  inventory pool.
- Also fixed in this pass: empty-cart guard in `convertToOrder()`, duplicate-active-cart
  prevention (new `active_slot` nullable column + unique index on `carts`, same NULL-for-inactive
  pattern as the appointments double-booking fix), and an N+1 query fix (`items.service`
  eager-loaded in `getOrCreateCart()`, `$depositTotal` computed once in `CheckoutController`
  instead of twice in the Blade view).

**Verification**: two independent review rounds (code-reviewer + agent-security-audit-specialist)
plus a live, full-production-path empirical reproduction — two real carts/users, forced
transaction interleaving against the real MySQL container — confirmed overselling before the
JOIN fix and confirmed it closed after. An exhaustive grep of `app/` for every `Rental`/
`OrderItem`-creating code path confirmed no other writer bypasses the fixed lock→forUpdate-check
pattern (the one other candidate, `RentalAvailabilityService::createHold()`, is `@deprecated`
dead code with zero callers).

## Zapobieganie (Prevention)

- **`Model::lockForUpdate()` does nothing without a terminal query method** — always write
  `Model::where(...)->lockForUpdate()->first()` (or equivalent), never call `lockForUpdate()` on
  an already-loaded model instance and discard the result.
- **A `Service::lockForUpdate()` (or any row lock) does NOT make a *different* table's plain
  reads fresh** — under MySQL REPEATABLE READ, only locking reads (`FOR UPDATE`/`LOCK IN SHARE
  MODE`) bypass snapshot timing. Any availability/count check on a write path MUST make its own
  queries locking reads, not rely on an unrelated row lock elsewhere in the same transaction.
- **A correlated `whereHas()`/`EXISTS` subquery is not "covered" by an outer `FOR UPDATE`** —
  its own table is evaluated independently. Use a real `JOIN` (with an explicit `->select()` to
  avoid column collisions) when the joined table's freshness matters under concurrency.
- Any new code path that creates a `Rental` or `OrderItem` (or otherwise consumes `Service`
  inventory) MUST go through `RentalAvailabilityService::getAvailableQuantity(forUpdate: true)`
  after acquiring `Service::lockForUpdate()` — see this method's docblock.
- Full suite after this fix: 790 passed, 3 pre-existing unrelated failures (baseline unchanged),
  5 skipped.

**Related**: found during the same 2026-07-04 multi-agent security review as
[VULN-004](VULN-004-template-rendering-rce.md) and the appointment double-booking fix
(`app/docs/features/appointment-booking-integrity.md`, same NULL-uniqueness pattern used for
`active_slot`).
