---
name: project-lokalizacje-faza3-krok-1-egzemplarze
description: Wielooddziałowość Faza 3, etap 1/5 (kroki 3.1-3.3) — service_units schema, ServiceUnitObserver anchor recompute, generator migration z guardem przeciw multi-location split
metadata:
  type: project
---

Branch `feature/lokalizacje-faza3-egzemplarze` (2026-09-08), from `develop` (Fazy 0-2 already
merged). Scope was DELIBERATELY narrow — tylko 3.1-3.3 (schema, observer, generator). Panel
RelationManager, wydanie/zwrot, protokoły = kolejne 4 etapy, nietknięte.

**Files:** `database/migrations/2026_09_08_090000_create_service_units_table.php` +
`2026_09_08_090001_generate_service_units_from_quantity_total.php`, `App\Models\ServiceUnit`,
`App\Enums\ServiceUnitStatus`, `App\Observers\ServiceUnitObserver` (registered in
`AppServiceProvider` next to `ServiceLocationStockObserver`), `Service::serviceUnits(): HasMany`.
`app/docs/features/lokalizacje/model-danych.md` fixed (`serial_number` → `identifier`, per
[[project_lokalizacje_faza2_stan_magazynowy]]'s sibling doc already using the correct name in
`plan-wdrozenia.md`).

**Column `status` is plain `string` + PHP BackedEnum cast, not `$table->enum()`** — grepped the
whole repo first, zero `$table->enum(` calls exist; `tests.md`'s MySQL-gate section already
documents why (SQLite never enforces real ENUM, existing status columns like `orders.status`
already use this pattern). Established convention, not a new decision.

**`ServiceUnitObserver` is a full COUNT recompute, not `+1`/`-1`** — mirrors
`Service::recalculateQuantityTotal()`'s own SUM-not-increment pattern deliberately, for the same
self-healing-against-missed-edge-cases property. On `updated()`, recalculates BOTH the old and new
`(service_id, location_id)` pair when `location_id` OR `status` changed — missing the old pair
would leave it permanently overstated by a unit it no longer has. Every recalculation ALSO calls
`Service::recalculateQuantityTotal()` on the same service in the same `DB::transaction()` — this
was NOT explicitly asked for in the task's 3.2 wording ("stocks.quantity = COUNT(...)"), but is
necessary: without it, `quantity_total` (which `getAvailableQuantity()` reads literally when
`$locationId === null`, TODAY, not after Faza 4) would silently drift from
`SUM(service_location_stocks.quantity)` the moment any unit is saved — breaking Faza 2's own
"niezmiennik mirrora" the instant this phase's code runs.

**`is_active` on `service_location_stocks` deliberately left untouched by this observer** —
documented as a considered decision (task explicitly asked to justify, not just decide silently).
`is_active` is an unrelated operator toggle ("does this location stock this product at all"),
unread by any code today (confirmed in Faza 2's own docs). Driving every unit of a service at a
location into `maintenance` already correctly signals "zero available now" via
`quantity = 0` from the recompute — auto-flipping `is_active` too would conflate two different
questions and would be a real behavior change for whatever future code eventually reads that flag.

**Generator (3.3) safety guard beyond what was literally asked:** the task said "z quantity_total
twórz N egzemplarzy w oddziale domyślnym" — taken completely literally, this collides with any
tenant whose stock is ALREADY split across more than the primary location: all N units would land
in primary, the observer would overwrite primary's own anchor with the WHOLE quantity_total
(inflating past its real per-location share), while the other location's stock row would reference
units that were never created there. Added `hasStockOutsidePrimary` guard: a service with any
`service_location_stocks` row outside primary having `quantity > 0` is skipped entirely, not
force-collapsed. Confirmed via [[project_lokalizacje_faza2_stan_magazynowy]] that 0/8 real tenants
have `multi_location_stock` ON today, so this is a guard against a future scenario, not a fix for
an active bug — flagged as such in both the migration docblock and `model-danych.md`.

**Generator idempotency does NOT rely on `UNIQUE(organization_id, identifier)`** — every generated
row has `identifier = NULL`, and NULL never collides with NULL in that index (verified empirically
on SQLite, both in `CreateServiceUnitsTableMigrationTest` and as the mechanism itself — this is
standard SQL semantics shared by SQLite/MySQL, not one of the SQLite-vs-MySQL divergences
`tests.md`'s MySQL-gate section warns about). The actual guard is a per-service existence check
(`$alreadyHasUnits`) — a service with ANY `service_units` row, from this migration or a future
manual add, is skipped entirely on re-run. Tested by rolling back (down() is a deliberate no-op,
same precedent as `2026_08_28_090001`, so the rollback does NOT delete the units it created) then
re-running `up()` against a service that already has 3 units — proves 3, not 6.

Baseline (develop before this branch): not independently re-measured pre-change; after this
branch's 28 new tests: full suite = 1716 passed / 5 skipped, 0 failures, `pint --test` clean
(940 files), `migrations:check-rollback` 149/149 valid. `php artisan migrate` run against dev
MySQL is safe here per `.claude/rules/migrations.md` (additive schema + generator populating a
brand-new table only — no destructive operation, no `migrate:fresh`).

**Known, explicitly flagged gaps (not fixed, out of this step's scope):** FK `cascadeOnDelete`
behavior only proven on SQLite locally, same caveat as every Faza 1/2 migration test — MySQL CI
gate is the only place that actually exercises real InnoDB semantics. No end-to-end
`Organization`-hard-delete cascade test written for `service_units` (Faza 2 has a dedicated
`ServiceLocationStockCascadeDeletionTest` through the real `Organization` model; this step only has
table-level FK tests, mirroring `CreateServiceLocationStocksTableMigrationTest`'s own scope, not
the deeper organization-lifecycle one). Panel/RelationManager wiring (3.4), single-unit maintenance
UX (3.5 — already functionally correct as a side effect of 3.2's COUNT, just not panel-exposed
yet), hand-over/return unit assignment (3.6/3.7), and protocol PDFs (3.8) are all untouched by
design.

**2026-09-08 review-fix follow-up (same branch, no code path change to the observer's actual
logic — only proof + guard + docs):** reviewer's Ustalenie 1 hypothesis was CONFIRMED, not fixed —
`updated()`'s existing `wasChanged('location_id') || wasChanged('status')` guard already means
issue/return (Invariant A: a rented-out unit never changes either field) never re-triggers
`insertOrIgnore` on the lock-holding path; kontrakt-dostepnosci.md Zasada 4 now says this
explicitly and forbids ever changing status/location_id inside a `Service::lockForUpdate()`
transaction (materialize via `SyncServiceLocationStock::forService()` first if that's ever
needed). Proof is observable, not value-based: comparing before/after values doesn't work here
because `insertOrIgnore` on an existing row is a no-op on values too while still taking the S-lock
— the test (`ServiceUnitObserverTest::test_updating_a_field_unrelated_to_status_or_location_never_touches_the_anchor_table`)
asserts via `DB::enableQueryLog()` that literally zero SQL touches `service_location_stocks` when
only `notes`/`inventory_number` change. Ustalenie 2: added an `updating()` guard in
`ServiceUnit::booted()` (mirrors `Order`'s immutable-field pattern) throwing `LogicException` on
`organization_id`/`service_id` changes — **`location_id` deliberately excluded**, it's a legal
transfer (Faza 7), not a bug; only `service_id`/`organization_id` re-pointing would silently
overstate a service's `quantity_total` since the anchor recompute is keyed on the unit's CURRENT
`service_id`. Both new tests verified falsifiable (temporarily reverted the guard/check, watched
red, restored, confirmed `git diff` clean) before finalizing — full suite after: 1720 passed / 5
skipped (baseline's 5 pre-existing skips, unchanged), `pint --test` clean.
