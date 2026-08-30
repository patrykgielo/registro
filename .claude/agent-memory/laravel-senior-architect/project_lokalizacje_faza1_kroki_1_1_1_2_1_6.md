---
name: project-lokalizacje-faza1-kroki-1-1-1-2-1-6
description: Wielooddziałowość Faza 1 (locations entity) kroki 1.1/1.2/1.6 — migracje, Location model, LocationObserver primary_slot mechanism
metadata:
  type: project
---

Branch `feature/lokalizacje-encja` (2026-08-27), not `feature/lokalizacje-oddzialy` — the
`app/docs/features/lokalizacje/README.md` had the wrong branch name, corrected in the same
change. Full plan: `app/docs/features/lokalizacje/plan-wdrozenia.md` (Faza 0 already merged via
PR #227). This memory covers only Faza 1 steps 1.1 (migration), 1.2 (model), 1.6 (primary branch
observer + backfill) — steps 1.3-1.5/1.7 (Filament resource, map picker, CMS block) are a
separate agent's scope.

**Files:** `app/Models/Location.php`, `app/Observers/LocationObserver.php` (registered in
`AppServiceProvider::boot()`), `database/migrations/2026_08_27_120000_create_locations_table.php`,
`2026_08_27_120001_backfill_primary_location_for_organizations.php`,
`database/factories/LocationFactory.php`. Tests: `tests/Unit/Models/LocationPrimarySlotTest.php`,
`LocationTenantIsolationTest.php`, `tests/Feature/Database/CreateLocationsTableMigrationTest.php`,
`BackfillPrimaryLocationForOrganizationsMigrationTest.php`.

**primary_slot mechanism — Observer, not `booted()`:** unlike `Cart::booted()` + `active_slot`
(derives purely from the row's OWN `status` field), promoting a location to primary requires
coordinating a SECOND row (demote old, promote new) — that complexity level is where this repo
already reaches for an Observer (`OrganizationObserver` precedent), not a `booted()` hook.

**Two-commit promotion is intentional, not a bug:** `LocationObserver::updating()` demotes the
old primary inside its own `DB::transaction()`, which commits BEFORE Eloquent's own (separate,
autocommit) UPDATE for the row being promoted runs right after the hook returns. This is NOT one
enclosing transaction around both writes. Considered and rejected: `return false` from
`updating()` + `saveQuietly()` inside the transaction for true atomicity — this makes the
caller's `$location->save()` return `false` despite the row having actually persisted, which
would misfire Filament's own save-result handling (and anything else branching on `save()`'s
return value or the `saved`/`updated` events). The two-commit ORDER (null old, then let the
pending update set new to 1) is what actually prevents the UNIQUE(organization_id, primary_slot)
rejection — full single-COMMIT atomicity lives in `Location::promoteToPrimary()` instead, a
static helper wrapping both writes in one `DB::transaction()`. **The future Filament "ustaw jako
główny" one-click action (step 1.3) should call `promoteToPrimary()`, never assign
`primary_slot` directly.**

**Backfill migration `down()` needed a stronger condition than name/slug match:** matching only
`name='Siedziba główna' AND slug='siedziba-glowna' AND primary_slot=1` isn't safe — the
realistic edit an admin makes is filling in the address/photo on that exact row while leaving
the auto-generated name alone (not renaming it). Added `whereColumn('created_at', 'updated_at')`
— true only for a row nothing has touched since the migration's own INSERT; any edit bumps
`updated_at` and permanently excludes the row from rollback. Tested directly
(`test_down_never_touches_a_location_that_was_edited_since_the_backfill_ran`).

**`contact.address_line` is a single free-text field**, no separate street/building split exists
anywhere upstream (`SystemSettings.php`'s form only has one `contact.address_line` TextInput) —
backfill maps it to `locations.street`, leaves `building` null.

Baseline before this work (develop, post Faza 0): 1479 passed / 5 skipped. After: 1503 passed / 5
skipped (+24 new tests, zero regressions). Verified on dev MySQL via `php artisan migrate` only
(read-only tinker check after) — 8 real tenants → 8 backfilled locations, each correctly scoped.
Rollback tests ran on SQLite locally only (`.env.testing` convention);
[[feedback_never_destroy_db]]-adjacent constraint means MySQL-real rollback proof only happens in
CI (`deploy-production.yml` runs Feature suite against real `mysql:8.0`).
