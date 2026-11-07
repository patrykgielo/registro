---
name: faza5-5-dostepne-gdzie-indziej
description: Faza 5.5 (86cbahqgn) — "Dostępne też w" section on service show page; LocationContext scoped() binding and the Laravel test-harness query-count pitfall it required
metadata:
  type: project
---

Implemented `ServiceController::availableElsewhere()` + a "Dostępne też w: Gdańsk (2 szt.)"
section in `resources/views/services/show.blade.php` (branch
`feature/lokalizacje-faza5-dostepne-gdzie-indziej`, not merged as of 2026-09-09). Full
rationale is in `app/docs/features/lokalizacje/plan-wdrozenia.md` under "Stan 2026-09-09
(krok 5.5)" — this memory only captures what isn't obvious from re-reading that doc.

**`App\Support\LocationContext` is now bound `scoped()` in `AppServiceProvider::register()`**
— the first `scoped()` binding in this project. Needed because `header.blade.php`'s
`app(LocationContext::class)` call and any controller's constructor-injected instance were
previously two separate instances (default container resolution), each with its own
`$activeLocationsCache` — adding a second `activeLocations()` caller (this feature) would have
paid a genuinely new `locations` query the header's switcher had already paid for in the same
request. `scoped()`, not `singleton()`, matches `architecture-models.md`'s explicit ban on a
`LocationContext` singleton (tenant-leak risk under a long-lived worker) while still sharing
one instance for the lifetime of a single request under classic php-fpm.

**Test-methodology trap, worth remembering for any future query-count assertion in this
repo:** the existing pattern in `RentalCatalogueLocationAvailabilityTest.php` (warm up caches
with one `->get()`, then measure a second `->get()` and diff counts) is UNSOUND for anything
backed by a `scoped()` container binding — Laravel's HTTP test harness does not tear down
`$this->app` between simulated `->get()` calls within one test method, so a `scoped()`
instance's cache survives the "warm-up" call and silently masks the real per-request cost.
Measured directly: a count-diff test written this way passed identically whether the
`scoped()` binding was present or entirely removed. The fix that actually catches the
regression: assert the exact count of a specific query SHAPE within a SINGLE request's query
log (see `test_the_active_locations_query_the_header_switcher_needs_is_shared_with_the_new_section_not_duplicated`),
falsified by temporarily reverting the binding and confirming the count moves 1→2.

**Decision worth remembering if this section is ever revisited:** it shows for every OTHER
active location with stock > 0 regardless of whether the SELECTED location itself has stock —
not gated to "only when the selected branch is empty". The ticket's acceptance criterion was
literally "wolny gdzie indziej" with no second clause; a narrower reading was considered and
rejected as adding an unstated threshold.

See also [[project_lokalizacje_faza5_krok_5_1_location_context.md]] (laravel-senior-architect's
memory, if present) for the `LocationContext`/`ShareSelectedLocation` foundation this built on.
