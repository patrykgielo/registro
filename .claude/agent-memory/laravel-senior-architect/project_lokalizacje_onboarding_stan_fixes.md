---
name: project-lokalizacje-onboarding-stan-fixes
description: Wielooddziałowość — trzy bugi post-Faza6 (rc37): brak lokalizacji przy provisioningu, seedowany katalog bez stanu, pierwszy egzemplarz nadpisujący ręczną ilość
metadata:
  type: project
---

Branch `feature/lokalizacje-onboarding-stan` (2026-09-19), from `develop` (rc37/Faza 6 krok 6.5
already merged). Three ClickUp tickets (`123k99cvc53`/`123k99cvcc3`/`123k99cvc54`), all found by
reading rc37 code, none needed a data migration (measured on dev: 0/8 orgs without a Location,
0/26 item_rental services without a stock row — see below for the read-only tinker checks used).
Full writeup: `app/docs/features/lokalizacje/model-danych.md` and `tryb-jednooddzialowy.md`
(new sections added this session), `.claude/rules/onboarding.md` and `rental-availability.md`
(§10 new).

**Bug 1 (123k99cvc53):** `registro:tenant-provision` created no `Location` — `ServiceResource`'s
"Ilość w magazynie" silently disabled+un-dehydrated (`tenantHasExactlyOneActiveLocation()===0`),
saving `quantity_total=NULL` with zero error. Fixed in `SeedOrganizationDefaults::
seedPrimaryLocation()` — name "Siedziba główna" (matches the Faza 1 backfill migration's own
convention, not the org's name), address blank (no `contact.*` settings exist yet at provisioning
time), idempotent via its own `Location::exists()` check (not just the `$orgWasCreated` gate it's
already wrapped in).

**Bug 2 (123k99cvcc3):** `SeedEquipmentRental::seed()` (and any vertical seeder run through
`onboarding:seed-vertical`) created Service rows via raw Eloquent, bypassing BOTH
`CreateService::afterCreate()`'s routing and `LocationStocksRelationManager`'s lazy self-heal —
seeded catalogue showed 0 available everywhere. Fixed in **two places, not one** — this mattered:
`SeedVerticalDataCommand::materializeLocationStocks()` (command-level, covers any future vertical
seeder) is NOT sufficient alone, because `StorefrontWalkthroughTest` calls `$seedVertical->seed($org)`
**directly**, bypassing the command entirely — caught this by running the full suite, not by
reasoning about it. `SeedEquipmentRental::seed()` itself now also calls
`SyncServiceLocationStock::forService()` per created service. Reverse-order half (a location
created AFTER the catalogue already exists) fixed in `SyncServiceLocationStock::forLocation()`:
when the new location is genuinely the org's FIRST (not just "currently primary" — an org can
re-promote a later branch without it being first), a service with NO stock row anywhere yet gets
that location seeded with `quantity_total` instead of 0, mirroring `forService()`'s own rule for
the opposite ordering. A SECOND new location still zero-fills.

**Rejected design (found via test evidence, not reasoning):** a blanket `Service::created()`
observer calling `forService()` for every item_rental service. Breaks the established test
fixture pattern used in ~8 files across the suite (`Location::factory()->create()` THEN
`Service::factory()->itemRental()->create()` THEN a manual `ServiceLocationStock::create()` at
that same pair to set up an asymmetric split) — `unique(service_id, location_id)` collides because
the observer would have already auto-inserted the row. Confirmed empirically by writing the
observer and running the targeted test files before reverting. Also added
`CreateService::afterCreate()` → `SyncServiceLocationStock::forService($this->record)` (before
`RouteQuantityFieldToPrimaryLocationStock::handle()`) — safe/idempotent for both tenant shapes,
closes the panel-create gap for multi-location tenants too (quantity_total is null/un-dehydrated
there, so this seeds 0 everywhere, matching what opening the tab manually already produced).

**`PanelWalkthroughTest` collision from Bug 1's fix:** its `setUp()` manually created a
`Location::factory(['name'=>'Siedziba główna','slug'=>'siedziba-glowna'])` per tenant to
reproduce the cross-tenant slug-collision incident shape — now redundant AND colliding
(`UNIQUE(organization_id,slug)` against the org's own auto-created row) since provisioning creates
the identical row itself. Removed the manual creation; the walkthrough's own real provisioning
call now produces the same cross-tenant collision "for free". Lesson: a fix that changes what
provisioning creates will collide with any test-tenant setUp() that used to compensate for what
provisioning didn't do.

**Bug 3 (123k99cvc54):** `ServiceUnitObserver::recalculateAnchor()`'s `COUNT(available units)`
overwrote a manually-typed anchor quantity (5) down to 1 the moment the first `ServiceUnit` was
created. Fixed with `materializePlaceholdersForFirstUnit()`, called from `created()` BEFORE
`recalculateAnchor()`: fires only when this is genuinely the pair's first unit (total count===1
after insert, any status) and a pre-existing stock row has a quantity higher than what the new
unit accounts for; backfills the difference as unnumbered (`identifier=null`) `available`
placeholders via raw `DB::table()->insert()` (never Eloquent `create()` — would retrigger the
observer). A unit created directly in `maintenance`/`retired` backfills the FULL previous quantity
(itself doesn't count). Second+ unit never re-triggers (guarded by the total-count===1 check).

**Real bug caught by the new test suite itself, not by reasoning:** `$unit->status` can be `null`
in-memory even though the column has `DEFAULT 'available'` — `Eloquent::create()` never
round-trips a DB column default back into the model unless something refreshes it, and the
established test convention in this codebase (`ServiceUnitObserverTest`, `UnitsRelationManagerTest`)
creates units via `ServiceUnit::withoutGlobalScope('organization')->create([...])` WITHOUT an
explicit `status` key. `$unit->status->countsTowardStock()` on a null status is a fatal error —
fixed with `$status = $unit->status ?? ServiceUnitStatus::Available;`. None of the PRE-EXISTING
observer tests hit this (they never combine "pre-existing stock row" + "unit created without
explicit status" — my new test file was the first to do both), so this was genuinely new, not a
pre-existing gap I happened to notice.

**Field/row disable, per (service, location) not per-service:** `RouteQuantityFieldToPrimaryLocationStock::
eligibleForDirectRouting()` gained `primaryLocationHasUnits()` (units specifically AT the primary
location disable the field — a unit at some OTHER location doesn't, since that location was never
reachable through this single-number field). `LocationStocksRelationManager`'s inline
`TextInputColumn::make('quantity')->disabled()` mirrors this per-ROW (`locationHasUnits($record)`)
— a service with units at location A but none at B keeps B's row editable. **Found while writing
tests, not planned upfront:** a test asserting "field stays enabled when units exist only at a
non-primary location" is UNREACHABLE — creating a unit at any location always writes a nonzero
`service_location_stocks` row there too (`ServiceUnitObserver` keeps them in sync), which ALREADY
trips the pre-existing `serviceHasStockOutsideItsPrimaryLocation()` guard regardless of the new
units check. Deleted that test; the real "location A has units, B doesn't" distinction is only
observable at the per-ROW level (`LocationStocksRelationManager`), where it's correctly tested.

**Helper text branches on WHY the field is disabled** (`ServiceResource::quantityFieldHelperText()`,
new): zero active locations → "dodaj oddział w sekcji Lokalizacje" (NOT the old generic message,
which pointed at "Stany magazynowe" — actively misleading at zero locations, since that tab can
only ever say "brak aktywnego oddziału"); has units → "ustaw ilość w zakładce Egzemplarze"; else →
the pre-existing multi-location/orphaned-stock message.

**Falsification method used throughout:** `git stash push -- <one file>` isolates exactly which
fix a given failing assertion depends on (proved per-bug, and in one case per-sub-mechanism —
`forLocation()`'s reverse-order branch stashed alone while `SeedEquipmentRental`'s own fix stayed,
isolating exactly test 3 of 4 in that file). `git stash pop` restores. Never used `git checkout --`
or any destructive variant.

**Zero migration needed** — verified via read-only tinker, not assumed: `Organization::
whereNotExists(...locations...)` → 0/8; every `item_rental` Service checked against
`ServiceLocationStock::exists()` → 0/26 without a row; every stock row compared against its
paired units' any-status count → exactly 1 apparent "mismatch" (service 314 loc 2: quantity=9,
10 total units), confirmed benign on inspection (9 available + 1 maintenance — `countsTowardStock()`
correctly excludes the maintenance one, not a bug).

Baseline (develop, post rc37): 2051 passed / 5 skipped. After first pass: 2066 passed / 5 skipped
/ 0 failed (+15 new tests across 3 new files). Pint 993→996 files. `bash scripts/test-concurrency.sh`
6/6.

## Code review follow-up (same day, same branch)

**Idempotency bug found in my OWN Bug 1 fix:** `ensurePrimaryLocation()` (renamed from
`seedPrimaryLocation`) originally lived INSIDE `SeedOrganizationDefaults::execute()`, which
`ProvisionTenantOrganization` only calls when `$orgWasCreated` — so re-running
`registro:tenant-provision --slug=<existing>` against a pre-fix tenant (zero locations) could
NEVER heal it, exactly the repair path an operator would reach for.
`test_re_running_provisioning_does_not_create_a_second_location` passed for the WRONG reason
(never exercised the healing path at all — it only re-ran against an org this SAME session's
provisioning had already created correctly). Fixed: `ensurePrimaryLocation()` made public, called
UNCONDITIONALLY from `ProvisionTenantOrganization::execute()`, separately from
`seedDefaults->execute()` (which stays `$orgWasCreated`-only — its `updateOrCreate` on settings
is NOT safe to replay against an existing, admin-customized tenant). New test explicitly
constructs a "zero-location org NOT created via this command" fixture to prove healing.
**Lesson: a test asserting idempotency by re-running the SAME code path that already worked once
in that test doesn't prove healing — it proves the happy path is idempotent, a different claim.**

**Concurrency: a real, mathematically-verified constraint on when the "two first units" race can
even happen.** Initial fix (`lockForUpdate()` on the anchor row + a locking count) was correct in
mechanism but my OWN report to the coordinator overstated the trigger condition ("double
form-submit... 5→9") without proving it. While building the falsification test, proved (not
assumed) that a BARE, unwrapped `ServiceUnit::create()` (Filament's actual default — no panel
calls `->databaseTransactions()`, confirmed against vendor source) auto-commits the unit's own
INSERT strictly BEFORE the observer's own transaction starts — which makes it mathematically
IMPOSSIBLE for two SEPARATE such calls to both miss each other's unit (chronological
contradiction: each process's own insert always precedes, and is therefore visible to, that SAME
process's own count query). The race requires an AMBIENT transaction already open around the
whole create (bulk/batch action, or a future `databaseTransactions(true)` panel) — a real,
legitimate, DOCUMENTED calling convention this observer's own top docblock already claims to
support, not a contrived scenario. `tests/Concurrency/ServiceUnitFirstUnitRaceTest.php` wraps the
probe's `ServiceUnit::create()` in an explicit `DB::transaction()` to reproduce this honestly.
**Falsification result was itself a correction to my own prediction:** reverting the lock did NOT
reproduce silent inflation (5→9 as I'd guessed) — it reproduced a REAL
`SQLSTATE[40001]: 1213 Deadlock found` on the second probe's `insertOrIgnore` (two concurrent
`insertOrIgnore`s against the same unique key take conflicting S-locks — kontrakt-dostepnosci.md
Zasada 4's known mechanism, same safe-failure class as `rental-availability.md`'s "Realny
deadlock InnoDB" but a DIFFERENT specific collision). Test still correctly fails either way
(`status='error'` ≠ `'ok'`) — always measure the falsification, never assume the failure mode
matches the mental model that motivated the fix.

New `--lock-watch=service_units` mode added to `tests/Concurrency/Support/probe.php`: matches
the first `count(*) ... from `service_units`` query REGARDLESS of `for update` (unlike
`services`/`carts`, which require it) — the one hook point that exists identically in BOTH the
fixed and pre-fix code, needed because the pre-fix code has zero `FOR UPDATE` queries to hook at
all in this path.

**Lock-order evaluation (asked, not required to fix):** `ServiceUnitObserver` locks
`service_location_stocks` → `services` (unchanged by this fix — `recalculateAnchor()`'s own
UPDATE already did this before). Cart/checkout paths lock `services` → `service_location_stocks`
(opposite order). This is a genuine PRE-EXISTING AB-BA risk between an admin editing "Egzemplarze"
and a concurrent customer checkout on the same service+location — NOT introduced or worsened by
this fix (same resource, same relative position, just an explicit/earlier acquisition of a lock
that was already being taken implicitly). Deliberately NOT fixed in this pass — would require
reordering `recalculateAnchor()` to lock `services` first on EVERY unit write, touching the
checkout hot path, unproven without its own harness. If it ever fires for real, InnoDB's deadlock
detector picks a victim (`1213`), not silent corruption — reported, not fixed.

**Security defense-in-depth (low severity, both trivial):** explicit `organization_id` filter
added to `SyncServiceLocationStock::forLocation()`'s `$serviceIdsWithExistingStock` query and to
`ServiceUnitObserver`'s anchor-row lock query — both were already effectively scoped through an
upstream `organization_id`-filtered query, so this is redundancy against a future caller that
isn't, not a fix to a live leak.

**NIT (nice-to-have) addressed:** `SeedVerticalDataCommand::materializeLocationStocks()` and
`SeedEquipmentRental::seed()` both called `forService()` per item — genuine duplicate work for
THIS seeder (though StorefrontWalkthroughTest calling the seeder directly meant the seeder's own
call could not simply be removed). Fixed with one line: `whereDoesntHave('locationStocks')` on
the command's query, so it only does real work for a FUTURE seeder that doesn't materialize its
own stock — the actual reason that safety net exists.

Final baseline after code review fixes: pint 997/997 (993 + 4 new test files); `php artisan test`
2067 passed / 5 skipped / 0 failed (2051 + 16 new tests in the default suite, exact match —
`ServiceUnitFirstUnitRaceTest` lives in `tests/Concurrency`, excluded from the default suite by
design); `bash scripts/test-concurrency.sh` 7/7 (new `ServiceUnitFirstUnitRaceTest`, 1 scenario,
real two-connection MySQL).
