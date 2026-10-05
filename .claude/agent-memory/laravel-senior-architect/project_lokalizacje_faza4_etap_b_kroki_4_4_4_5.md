---
name: project-lokalizacje-faza4-etap-b-kroki-4-4-4-5
description: Faza 4 etap B (kroki 4.4/4.5) — rewired getAvailableQuantity() call sites incl. a missed 3rd caller of checkAvailabilityForExtension() found in review, branch feature/lokalizacje-faza4-przepiecie, 2026-09-09
metadata:
  type: project
---

Faza 4 etap B (kroki 4.4/4.5 z `kontrakt-dostepnosci.md`/`plan-wdrozenia.md`), branch
`feature/lokalizacje-faza4-przepiecie`, 2026-09-09. Etap A (4.1-4.3 + migracje 4.8, PR #263) was
already merged and left the branch "dead" — none of the 9 `getAvailableQuantity()` call sites
passed `$locationId`. This step wired 6 of them (CartService's addItem/updateQuantity/
convertToOrder, CreateRental, EditRental) plus the 9th "one that gets skipped"
(`RentalExtensionService::checkAvailabilityForExtension()`).

**Source of `$locationId` per call site — the real nuance, not just "read it off the row":**
`updateQuantity()`/`convertToOrder()`/`RentalExtensionService`'s two callers already have an
existing row (CartItem/OrderItem) → read `$item->location_id` directly, no new parameter.
`addItem()` is different — there is NO existing row yet, so `location_id` must ENTER the system
as a brand new `?int $locationId = null` parameter on `addItem()` itself, persisted onto the
created CartItem so the other two paths can read it back later. Missing this distinction would
have led to guessing a wrong uniform pattern across all three CartService methods.

**`CreateRental`/`EditRental` had ZERO location field before this change** (confirmed by
grepping `RentalResource.php` for `Select::make`) — added `location_id` Select to
`RentalResource::form()`, mirroring `UnitsRelationManager`'s proven `modifyQueryUsing` defensive
pattern (`is_active` OR the record's own current value, [[filament-resources.md]} "Select
pojedynczy broni się sam"). Optional, defaults to the tenant's `primary_slot` location.

**Zasada 7 aggregation must move from per-service to per-(service,location)** — the sibling-demand
dedup in `CartService.php` (both the DB-query form in addItem/updateQuantity and the PHP-array
`acceptedByService` form in convertToOrder). Verified BOTH mutation directions by actually
breaking the code, running the specific test, confirming red, then reverting (`git diff` clean
after): dropping the location filter from addItem's sibling query → false-reject test goes red;
flattening convertToOrder's demand key back to bare `service_id` → the cross-location test goes
red. Same falsification technique applied to `RentalExtensionService`: removing
`locationId: $item->location_id` from `requestExtension()`'s call → the "cannot poach a different
location's free unit" test goes red (extension wrongly succeeds).

**Concurrency harness** (`tests/Concurrency/CartCheckoutRaceTest.php`) got 2 new scenarios
mirroring the existing 2 (same-location/last-unit → one winner; different-locations/one-unit-each
→ both succeed) — required real MySQL, ran via `bash scripts/test-concurrency.sh`, 4/4 green.

**Numbers:** SQLite baseline 957 files/1833 passed → 1851 passed after (+18 = exactly new test
count), 0 regressions. Real MySQL (throwaway container, never `registro-mysql`):
`tests/Feature/Database` 183/183, plus 123/123 targeted (CartService/RentalAvailabilityService/
RentalExtensionService + new Location test files).

**Not verified:** did not re-run the concurrency mutation-falsification on real MySQL (only at
the SQLite/unit level) — reasoning was the aggregation logic is pure PHP (no row locking
involved), so the proof should transfer, but this is an assumption not an independent
measurement. Steps 4.6 (`getMonthlyAvailability`) and 4.7 (`availabilityForServices`, doesn't
exist yet) are explicitly out of scope — `RentalBookingController` still passes no `$locationId`.

**Review round 2 caught what I missed: `checkAvailabilityForExtension()` has THREE callers, not
two.** `RentalExtensionService::requestExtension()`/`approve()` were wired, but
`RentalExtensionController::checkAvailability()` (the JSON endpoint, `:41`) was not — same "the
one that gets skipped" bug, one caller level deeper. Effect was silent and one-directional: a
false `can_extend: false` whenever the GLOBAL pool (`quantity_total`) was exhausted in a
DIFFERENT location while the item's own location had a free unit — no exception, no log.
**Lesson: when a method gains a new optional parameter, grep for ALL its callers, not just the
ones the task description names** — `grep -rn "checkAvailabilityForExtension("` would have caught
this on the first pass. Fixed + falsified the same way (reverted → red → reverted back), new HTTP
test in `RentalExtensionControllerTest.php` (not a new file — extended the existing one, since it
already had the exact bootstrap: `actingAsTenant()`, `enableRentalExtension()`, `paidOrder()`).

Also skipped in the first pass: cross-tenant `location_id` injection test for
`RentalResource::form()`'s new Select (added, mirrors `UnitsRelationManagerTest`'s pattern) — the
defensive `modifyQueryUsing` had a proven PRECEDENT elsewhere in the codebase but no test of its
own for this specific resource.

**`plan-wdrozenia.md` has ZERO per-step status markers anywhere** — verified by grep across the
whole file, including for Faza 1-3 which are fully merged. The actual "status faz" convention
(✅/🟡/⬜ + PR links + narrative "Faza N etap X — zmergowana/gałąź ..." paragraphs) lives in the
adjacent `README.md` in the same directory — that's what actually needed updating, not the plan.
Corrected the same misreading (team lead said "plan-wdrozenia.md", I updated README.md instead
and flagged the discrepancy rather than silently doing something else).

Updated `[[rental-availability]]` (`.claude/rules/rental-availability.md`) Zasady 2/6/7/8,
`kontrakt-dostepnosci.md` (both stale "Stan 2026-09-09" blocks + the Zasada 3 call-site table,
including the 3-callers correction), and `README.md`'s "Status faz" table + narrative.
