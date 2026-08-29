---
name: project-lokalizacje-faza2-stan-magazynowy
description: Wielooddziałowość Faza 2 (per-location stock anchor) — service_location_stocks migracje, quantity_total mirror, RelationManager, "Ilość w magazynie" field routing
metadata:
  type: project
---

Branch `feature/lokalizacje-stan-magazynowy` (2026-08-28), from `develop` (Fazy 0+1 already merged).
Full plan: `app/docs/features/lokalizacje/plan-wdrozenia.md` Faza 2 (kroki 2.1-2.5). Zero-regression
invariant held: `RentalAvailabilityService`, `CartService`, `Location.php`, `LocationObserver.php`,
`ContentGridResolver`, CMS cards, `getAvailableQuantity()` — none touched. Only `php artisan migrate`
run against dev MySQL (no writes beyond that), per `.claude/rules/agent-usage.md`.

**Files:** `database/migrations/2026_08_28_090000_create_service_location_stocks_table.php` +
`2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php`,
`app/Models/ServiceLocationStock.php`, `database/factories/ServiceLocationStockFactory.php`,
`app/Actions/Inventory/SyncServiceLocationStock.php` (materialization, `forService()`/`forLocation()`),
`app/Actions/Inventory/RouteQuantityFieldToPrimaryLocationStock.php` (the "Ilość w magazynie" field's
single-location-tenant routing + its own eligibility check
`tenantHasExactlyOneActiveLocation(?int $organizationId)`), `app/Observers/ServiceLocationStockObserver.php`
(NEW, separate observer on `Location::class` — registered alongside `LocationObserver` in
`AppServiceProvider`, does NOT touch that file's own class), `app/Filament/Resources/ServiceResource/
RelationManagers/LocationStocksRelationManager.php`. `Service.php` gained `locationStocks(): HasMany`
+ `recalculateQuantityTotal()` (mirror `quantity_total = SUM(stocks.quantity)`, see below).

**FK onDelete for `location_id` is `cascadeOnDelete`, NOT `restrictOnDelete`** — the one real
decision the team lead flagged as risky. `locations.organization_id` is already `cascadeOnDelete`
(Faza 1); if `service_location_stocks.location_id` were `restrictOnDelete` instead, hard-deleting an
organization would race two SIBLING cascades off the same parent row (organizations → locations,
organizations → service_location_stocks) with no guaranteed MySQL ordering — if `locations` cascaded
first, the restrict would reject the whole DELETE. Making `location_id` cascade turns it into a
genuine multi-level cascade (organizations → locations → service_location_stocks), immune to sibling
ordering. `service_id` stays `restrictOnDelete` (matches `rentals.service_id` precedent) — safe only
because `services.organization_id` is `nullOnDelete`, never `cascadeOnDelete`
(`2026_03_08_000003_add_organization_id_to_existing_tables.php`), so a service row is NEVER removed
by an org's cascade, only orphaned. Proven end-to-end (not just per-FK) by
`tests/Feature/Organizations/ServiceLocationStockCascadeDeletionTest.php`.

**`quantity_total` mirror — which write paths stay deliberately "dangled":** `ServiceFactory`,
`SeedEquipmentRental`, and any other direct `Service::create(['quantity_total' => N])` never touch
`service_location_stocks` at all — no hook fires on Service creation for this. `getAvailableQuantity()`
still reads `quantity_total` literally today (Faza 4 hasn't wired the location dimension in), so
nothing about availability regresses. The gap closes lazily: `SyncServiceLocationStock::forService()`
runs the FIRST time `LocationStocksRelationManager` is opened for that service — if it finds ZERO
existing stock rows, it seeds the org's PRIMARY location with the service's CURRENT `quantity_total`
(not 0), then zero-fills every other active location. Already-existing rows are never touched
(`insertOrIgnore`, outside any lock — kontrakt-dostepnosci.md Zasada 4). Symmetric direction
(`forLocation()`) fires automatically via `ServiceLocationStockObserver::created()` the moment a NEW
location is added, zero-filling every existing item_rental service for it.

**"Ilość w magazynie" field (`ServiceResource.php:270`) — single source of truth is
`RouteQuantityFieldToPrimaryLocationStock::tenantHasExactlyOneActiveLocation()`,** called from BOTH
the form's `disabled()`/`dehydrated()`/`required()` closures AND the action's own internal guard
(defense in depth — the action refuses to run even if called directly, not just when the field is
disabled). `disabled()` alone is NOT enough in Filament — disabled fields are STILL dehydrated by
default, so `dehydrated(false)` is a separate, required call, not redundant. For a multi-location
tenant the field goes inert; per-location quantities are edited exclusively via
`LocationStocksRelationManager`'s inline `TextInputColumn`, which calls
`$record->service->recalculateQuantityTotal()` in `afterStateUpdated()`.

**`Service::recalculateQuantityTotal()` uses `DB::table('services')->where(...)->update()`, not
`save()` or even `$this->newQuery()->update()`** — Eloquent's OWN query builder (via `newQuery()`)
still auto-bumps `updated_at` even without going through `save()`; only a genuinely raw `DB::table()`
call skips it. Caught by a test asserting `updated_at` is unchanged after recalculation (this column
is a derived mirror, not a substantive edit).

**Factory gotcha (cost 12 failing tests before diagnosis) — `Service::factory()->itemRental()->for($org,
'organization')` does NOT scope the service to `$org`.** Laravel's `for()` relationship resolver is
ALWAYS applied first internally (prepended to the state-closure reduce), so any LATER state that
independently sets the same FK — `itemRental()`'s own `'organization_id' => Organization::factory()`
— overwrites it regardless of method-chain order. `Location::factory()->for($org, 'organization')`
does NOT have this problem (its `definition()` sets `organization_id` only once, never re-touched by
a state), so that pattern stays correct. Established codebase convention (grepped: `DoubleBookingTest`,
`CartCheckoutRaceTest`, `ServiceTest`, etc.) is `Service::factory()->itemRental()->create(['organization_id'
=> $org->id, ...])` — explicit array override in `create()`, never `->for()`, whenever chaining with
`itemRental()`. `ServiceLocationStockFactory` uses a resolved (`->create()`d) Organization model for
the same reason, not a raw `Organization::factory()` instance shared across three FK fields.

**Filament testing gotcha:** `Service::getRouteKeyName()` returns `slug`, so
`Livewire::test(EditService::class, ['record' => $service->getKey()])` 404s — must pass
`$service->getRouteKey()` (resolves to slug). `ServiceAreaResourceAuthorizationTest`'s precedent
using `->getKey()` works only because `ServiceArea` has no route-key override.

Baseline (develop, post Faza 0+1): 1539 passed / 5 skipped. After Faza 2: 1584 passed / 5 skipped
(+45 new tests, exactly matching new test count — zero regressions, zero modified pre-existing
tests). `migrations:check-rollback`: 145/145 valid. Not re-run twice (no flaky failure hit on first
pass, so the "repeat once" instruction for a hit didn't apply).

**2026-08-28 code-reviewer found 2 blockers, both fixed same day (still on this branch, unmerged):**

1. **`service_id` FK flipped `restrictOnDelete` → `cascadeOnDelete`.** The paragraph above ("`service_id`
   stays `restrictOnDelete` (matches `rentals.service_id` precedent)") was WRONG reasoning, copied
   without checking what `rentals.service_id` actually protects — legal records (retention), not an
   ephemeral derived count. The migration's OWN docblock already used the correct "ephemeral
   operational data" classification for `organization_id`'s cascade, just not for `service_id` too.
   Effect of the bug: since `RouteQuantityFieldToPrimaryLocationStock::handle()` runs on every save for
   a single-active-location tenant (8/8 real tenants), almost any item_rental service became
   undeletable from the panel the instant it got its first stock row — raw `QueryException`, no test in
   the repo had ever exercised `ServiceResource` deletion. New file: `tests/Feature/Filament/ServiceResourceDeletionTest.php`.

2. **Self-reinforcing `quantity_total` inflation loop**, root cause: `tenantHasExactlyOneActiveLocation()`
   only counts `Location.is_active`, but nothing cleans up a service's `service_location_stocks` row when
   a location is later deactivated (`Location.is_active` and `ServiceLocationStock.is_active` are
   independent columns). A tenant that goes 2 active → deactivates one "looks" single-location again,
   the field re-enables, and every SAVE — even with the value UNCHANGED — absorbs the orphaned row into
   the primary and re-sums it via `recalculateQuantityTotal()`: 8 → 11 → 14 → ... unbounded. Fixed with a
   NEW per-SERVICE (not per-org) guard `RouteQuantityFieldToPrimaryLocationStock::serviceHasStockOutsideItsPrimaryLocation()`,
   wired into a single `eligibleForDirectRouting(?int $organizationId, ?Service $service)` entry point
   used by BOTH `handle()` and `ServiceResource`'s field `disabled`/`dehydrated`/`required`/`helperText`
   closures (added `?Model $record` param to all four). **Rejected a `handle()`-only guard** (team lead's
   option (a) as literally stated): it stops the SUM from growing, but leaves the field enabled+dehydrated,
   so the raw Eloquent save still writes the admin's typed number into `quantity_total` while `handle()`
   silently refuses to route it — `quantity_total` then DRIFTS from `SUM(service_location_stocks.quantity)`,
   breaking the mirror invariant that `getAvailableQuantity()` already reads today (not just Faza 4). Disabling
   the field for this case is the same existing UX pattern already used for genuinely multi-location tenants,
   not a new decision. Did NOT touch `recalculateQuantityTotal()`'s own SUM (no `is_active` filter added there)
   — that would change availability on location deactivation, which is explicitly out of scope (a Faza 1
   product decision, not this bug's fix) and would need Faza 0's characterization tests re-verified.
   `git diff develop` on `RentalAvailabilityService.php`/`CartService.php` stayed empty; `RentalAvailabilityServiceTest`
   (Faza 0) passed unmodified (26/26).

Baseline after this fix: 1589 passed / 5 skipped (was 1584/5 — 5 net new tests, exact match).

**Known, accepted gaps (not fixed, out of this scope):** a `RentalCategory` referenced by a factory-
created item_rental service can belong to a DIFFERENT organization than the service itself (the
`itemRental()` state's own `'rental_category_id' => RentalCategory::factory()` has the identical
`for()`-loses-to-state bug, unfixed at the factory level — only worked around per-test where it
mattered, by passing an explicit `rental_category_id`). A tenant provisioned via
`registro:tenant-provision` AFTER Faza 1's one-time backfill migration ran gets NO automatic primary
location (that migration only ran once, at merge time) — `RouteQuantityFieldToPrimaryLocationStock`
and `SyncServiceLocationStock` both degrade safely (no-op) for a zero-location org, but this means a
brand-new tenant's "Ilość w magazynie" field silently does nothing until an admin manually creates a
location. Not touched — fixing tenant provisioning is outside Location/LocationObserver-adjacent
files this task was explicitly barred from.
