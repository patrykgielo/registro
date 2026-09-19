---
name: lokalizacje-faza6-krok62-zmiana-oddzialu
description: Faza 6 krok 6.2 — CartService::setLocation()/previewLocationChange(), non-empty-cart location-switch confirmation; a wrong concurrency-test design that looked like a real bug but wasn't
metadata:
  type: project
---

Branch `feature/lokalizacje-faza6-zmiana-oddzialu` (86cbahqgv), 2026-09-18. `CartService::previewLocationChange()`/`setLocation()`/`evaluateLocationChange()` (CartService.php:704-878) + `LocationSelectionController::store()` extended in-place (same route, `confirmed=1` second POST — never a second endpoint). Written by a prior agent, reviewed+tested (0 tests existed), no production-code changes needed.

**Four decisions, as implemented (2026-09-18 session):**
1. Never a hard rejection — `kept = min(requested, max(0, available - siblingDemand))`, clamps down or removes the item, always reports what changed.
2. Prompt shows for ANY non-empty cart on a real location change, even when everything still fits — it's about moving the pickup point, not just stock. Empty cart skips straight to the switch.
3. `cart_items.location_id` stays untouched by `setLocation()` — **SUPERSEDED, see 2026-09-19 fix below.**
4. `setLocation()` locks the cart row + calls `evaluateLocationChange(forUpdate: true)`, same `Service::lockForUpdate()` + locking-read discipline as `convertToOrder()`.

**Gap from decision 3 — FIXED 2026-09-19 (same-day follow-up), not out of scope after all.** The
2026-09-18 note below is the exact bug the follow-up session was assigned: `CartController::add()`
never forwarded `$locationId` to `addItem()`, so every real add-to-cart/checkout validated against
the GLOBAL pool regardless of the cart's own selected branch. Fix, three parts: (1)
`CartService::addItem()` now derives `$locationId = $cart->location_id ?? $locationId` — cart's own
column always wins over whatever the caller passed, single source of truth by construction; (2) new
private `syncItemLocationsToCart(Cart $cart, ?int $locationId)` heals stale/NULL `cart_items.location_id`
rows (legacy carts from between krok 6.1's deploy and this fix), called at the top of
`addItem()`/`updateQuantity()`/`convertToOrder()` — **`convertToOrder()` targets the RESOLVED, active-
filtered `$pickupLocation?->id`, not the raw `$cart->location_id` column** (a cart still pointing at a
JUST-deactivated location must fall back to the global pool, matching what the order itself does one
guard earlier — first version of this fix used the raw column and broke
`CartServicePickupLocationTest::test_convert_to_order_succeeds_with_null_pickup_when_deactivation_drops_active_count_to_one`);
(3) `setLocation()` now stamps `$newLocation->id` onto EVERY surviving item, not only the ones whose
quantity changed — an item kept at its original quantity is still a decision about the new location.
Full report + falsification output: this session's SubagentHandback (not persisted to a file — see
`feedback_business_docs_no_bugs`-adjacent guidance: this file already existed and is the right home).

**Wrong concurrency-test design, worth not repeating:** first attempt raced two concurrent `setLocation()` calls against each other for the same 1-unit location — both "won" (kept=1/kept=1). That's CORRECT, not a bug: a `CartItem` is never itself a reservation in this system (`getAvailableQuantity()` only counts committed `Rentals`/`OrderItems` — see [[rental-availability]] Zasada 1), so two non-checked-out carts can both believe they hold the last unit; only `convertToOrder()` is the real arbiter. The scenario that actually proves anything: race a REAL `convertToOrder()` checkout against a concurrent `setLocation()` confirmation targeting the same location's last unit — the confirmation must see the checkout's just-committed reservation under lock, not a stale pre-commit snapshot. Falsified (`forUpdate: $forUpdate` → `forUpdate: false` in `evaluateLocationChange()` → red; reverted → green) in `tests/Concurrency/CartLocationChangeRaceTest.php`. **General lesson for this codebase: before writing a two-cart concurrency scenario, ask whether either side actually WRITES a row `getAvailableQuantity()` reads — if neither does, there is nothing to race.**

**Test-fixture gotcha (repeats [[project_lokalizacje_faza2_stan_magazynowy]]'s own finding):** `service_location_stocks` anchor rows only auto-materialize on `Location::created()` (`ServiceLocationStockObserver` → `SyncServiceLocationStock::forLocation()`) — NOT on `Service::created()` (`forService()` is lazy, called only from the Filament relation manager). Create the Service FIRST, then Locations, or a `->update(['quantity' => N])` on the missing anchor row silently updates 0 rows and every location-scoped availability check reads capacity 0.

**Second test-fixture gotcha, found by the 2026-09-19 fix landing:** a real Service saved through
`ServiceResource`'s form on a single-active-location tenant ALSO gets its primary location's anchor
row populated for free, via `RouteQuantityFieldToPrimaryLocationStock::handle()` (an `afterSave`
hook routing the "Ilość w magazynie" field into `service_location_stocks`) — a bare
`Service::factory()->create(['quantity_total' => N])` skips this entirely (Filament-only side
effect), same class of gap as `RentalFactory`/`AppointmentFactory` in `tests.md`. Broke 6 PRE-EXISTING
tests the moment `addItem()`/`convertToOrder()` started actually reading the location dimension
(`CartServicePickupLocationTest` x2, `CheckoutPickupLocationTest` x2, `LocationDeactivationCheckoutGuardTest`
x1) — all fixed the same way: explicit `ServiceLocationStock::updateOrCreate(...)` in the fixture.
**Checked and ruled out before treating this as a real gate:** a documented `multi_location_stock`
tenant flag (`tryb-jednooddzialowy.md`) that would supposedly gate this behavior data-independently
— grepped, zero code references anywhere in `app/`, only doc mentions. The actual Faza 4-6
implementation gates purely on `LocationContext::selectionRequired()`
(`Location::active()->count() > 1`), not that flag — the doc appears to describe a design that was
superseded during implementation. Did not attempt to reconcile that divergence; out of this
session's scope.

`tests/Concurrency/Support/probe.php` generalized with `--action=convertToOrder|setLocation` (default unchanged) — same `Service ... for update` DB::listen hook works for both, since both write paths share the same `Service::lockForUpdate()` convention.

Files: `app/Services/Cart/CartService.php`, `app/Http/Controllers/LocationSelectionController.php`, `resources/views/cart/location-change-confirm.blade.php`, `tests/Unit/Services/CartServiceLocationChangeTest.php`, `tests/Feature/Cart/LocationChangeConfirmationTest.php`, `tests/Concurrency/CartLocationChangeRaceTest.php`, `tests/Concurrency/Support/probe.php`, `tests/Feature/Cart/CartLocationStockEnforcementTest.php` (new, 2026-09-19). Verified 2026-09-18: pint 989 files clean, `php artisan test` 2020 passed/5 skipped/0 failed, `bash scripts/test-concurrency.sh` 5/5 green. Verified 2026-09-19 (stock-enforcement fix): pint 990 files clean, `php artisan test` 2025 passed/5 skipped/0 failed, concurrency harness 5/5 green.

**Third incident, same day (2026-09-19), same session's own follow-up review found it: `addItem()`/`updateQuantity()` never locked the cart row at all.** Both derived `location_id` straight off the caller's in-memory `$cart` argument — loaded in a SEPARATE, already-committed transaction (`CartController::add()` -> `getOrCreateCart()`) — so a concurrent `setLocation()` branch switch committing in between left them trusting a stale value, unlike `setLocation()`/`convertToOrder()` which both already re-locked the cart row first. Fix: both now open with `$cart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();` before reading `location_id` — same global lock order as the other two (cart row -> services). **A plain unlocked re-read would not have been enough** — under MySQL REPEATABLE READ a normal SELECT can still return the pre-commit snapshot while `setLocation()` holds the row locked; only a locking read is guaranteed to block until commit and return the fresh value.

`probe.php` gained `--action=addItem` (requires `--service-id`/`--start-date`/`--end-date`) and a `--lock-watch=services|carts` flag (default `services`, unchanged for every existing scenario) — this new race is contended on the **cart** row, not the services row every prior scenario watched, so the DB::listen hook needed to become configurable about which table's first `for update` query it treats as "ready". New test in `CartLocationChangeRaceTest.php`: `setLocation()` (delayed 1500ms holding the cart lock, `--lock-watch=carts`) races `addItem()` (its own plain unlocked `Cart::findOrFail()` mirrors `CartController::add()` exactly) on the SAME cart; asserts every surviving `cart_item.location_id` equals the cart's own post-switch `location_id`.

**Falsification note, root-caused (not left as a guess) after coordinator review:** reverting just the `addItem()` lock didn't reproduce the "quiet mismatch" first expected under the two-process MySQL harness — it produced a real InnoDB `1213 Deadlock found` instead (`setLocation`'s own `services ... for update` query as the reported victim). Confirmed against InnoDB's documented FK-locking behaviour (dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html + Percona's "InnoDB locking and Foreign Keys"): an `INSERT` into a child table takes a **shared** lock on the referenced parent row to verify the FK, even when the parent row's own columns aren't touched. Exact cycle: broken `addItem()` holds an X-lock on `services` (`Service::lockForUpdate()`), then its `CartItem::create()` — via the `cart_items.cart_id -> carts.id` FK — needs an S-lock on the `carts` row `setLocation()` already holds X (its own `lockForUpdate()`, held since the start of its transaction). Meanwhile `setLocation()`, after its delay, tries to lock that SAME `services` row `addItem()` holds. Classic AB-BA: `setLocation` waits on `addItem` (services), `addItem` waits on `setLocation` (carts, via FK) → InnoDB deadlock-detects and kills one side. Still a valid FAIL (the test asserts `status === 'ok'` before it ever reaches the location-match assertion), and a stronger proof than a quiet mismatch would have been: skipping the cart-lock discipline on one of four write paths doesn't just risk silent data corruption — it opens a real, deterministic-once-you-know-the-shape deadlock hazard.

**Because the concurrency harness's falsification landed on the deadlock branch and never actually exercised the location-match assertion, the "silent mismatch" invariant itself needed its own, separate, deterministic proof — added same-day, sequential, SQLite, zero timing.** `CartServicePickupLocationTest::test_add_item_with_a_stale_in_memory_cart_checks_availability_against_the_carts_current_location_not_the_stale_one` and `::test_update_quantity_with_a_stale_in_memory_cart_...` — single PHP process, no proc_open: load `$stale = Cart::find($cart->id)` (location A), call the REAL `setLocation()` to B (commits), then call `addItem($stale, ...)`/`updateQuantity($stale, $item, ...)` on the stale object. Stock rigged so location A and B give DIFFERENT verdicts (A has stock, B doesn't) — the fixed code must reject (checks B); reverting either method's new `Cart::where(...)->lockForUpdate()->firstOrFail()` line makes the operation wrongly SUCCEED against the stale A instead, which the test catches directly (`RentalUnavailableException` expected but not thrown). Falsified independently for both methods, both confirmed red, both restored to green. This pair, not the concurrency test, is what actually pins the silent-mismatch invariant `rental-availability.md` describes as the motivating scenario.

Verified 2026-09-19 (coordinator's follow-up review round): pint still 990 files clean (no new files, only edits to existing ones). Targeted run of just the 2 new sequential tests: 2 passed (8 assertions) with the fix; falsified independently (removing each method's own new lock line, one at a time, restoring after) — both went red with the expected message (`addItem`: "Failed asserting that exception ... is thrown"; `updateQuantity`: explicit `$this->fail(...)` message), both restored to green. Full 2025-passed/5-skipped Feature+Unit suite NOT re-run this round per explicit instruction (no production-code net change since the run that produced that baseline — CartService.php was reverted after each falsification, confirmed via the targeted re-runs passing again). `bash scripts/test-concurrency.sh` re-run once: still 6/6 green.

**CartItem writer inventory (checked this session, grepped `app/`):** the ONLY writers of `cart_items` rows anywhere in the app are inside `CartService` itself — `addItem()` (`CartItem::create()`), `updateQuantity()`/`setLocation()` (`$item->update()`), `removeItem()`/`setLocation()` (`$item->delete()`), and `syncItemLocationsToCart()`'s bulk `CartItem::where(...)->update()`. No controller, job, console command, or observer writes a `CartItem` directly. All four public write methods now lock the cart row first — `removeItem()` is the only one that doesn't, and correctly so: a delete of an already-owned row (ownership already checked by `cart_id`) can't produce a cross-location inconsistency, and it runs outside any transaction today, same as before this fix.
