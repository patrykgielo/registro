---
name: project_concurrency_harness_cart_checkout_race_2026-08-27
description: Built tests/Concurrency (two-OS-process, real-MySQL oversell race harness) for CartService::convertToOrder() — measured that forUpdate:true, not Service::lockForUpdate(), is the layer that actually closes the race in this scenario
metadata:
  type: project
---

Faza 0 krok 0.3 (multi-location plan, `feature/lokalizacje-oddzialy`): built the repo's first
two-connection concurrency test. Full writeup lives in the CHECKED-IN docs, not duplicated here —
read `.claude/rules/tests.md` → "tests/Concurrency" before touching this area again. Key files:
`tests/Concurrency/CartCheckoutRaceTest.php`, `tests/Concurrency/Support/probe.php`,
`scripts/test-concurrency.sh`.

**Why this mattered:** every other oversell test in the repo (`tests/Unit`, `tests/Feature`) runs
on SQLite, sequentially — none of them would fail if the lock discipline in
`CartService::convertToOrder()` were silently deleted, because SQLite has no InnoDB row locking to
defeat. `kontrakt-dostepnosci.md` Zasada 6 flagged this as a real gap, not a hypothetical one.

**How to apply:** any future change to `CartService`'s lock discipline (Zasada 4 in
`kontrakt-dostepnosci.md`) needs `bash scripts/test-concurrency.sh` run against it, not just the
SQLite suite — the SQLite suite passing proves nothing about the actual race.

**The one surprising, measured (not guessed) result:** the docblock at
`RentalAvailabilityService.php:22-53` and Zasada 4 both frame `Service::lockForUpdate()` +
`forUpdate: true` as "jedno i drugie", both required. Measured on a throwaway `mysql:8.0` container
(each broken variant applied for exactly one run, then reverted — `git diff` on `app/` clean
afterward):
- Remove `forUpdate: true` only → **oversell** (both concurrent checkouts of the last unit
  succeeded).
- Remove `Service::lockForUpdate()` only (keep `forUpdate: true`) → **no oversell**, confirmed
  twice for determinism.

So for THIS scenario (single item, `quantity_total=1`, overlapping dates, no cart siblings),
`forUpdate: true` is the layer that closes the race; `Service::lockForUpdate()` measured as not
load-bearing here — most likely MySQL's own next-key/gap locking on the `FOR UPDATE` range scan
over `order_items`/`rentals` already serialises concurrent inserts into that date range on its own.
**This does not mean the Service lock is provably useless** — it wasn't tested against the
multi-item/deadlock-ordering scenarios Zasada 4 also cites, only against the two scenarios this
harness covers. Framed honestly as a narrower verdict than "both required" in `.claude/rules/tests.md`,
not as "remove the lock" — nobody asked for that and the harness doesn't cover enough ground to
justify it.

**Technique reusable for the next two-connection test:** real concurrency needs two real OS
processes (`proc_open`), not two named Eloquent connections in one PHP process — a single
synchronous process cannot hold one transaction's lock open while a second transaction's query
blocks on it. Coordination by FILE SIGNAL, not sleep-and-hope: a `DB::listen()` hook in the spawned
probe script detects the specific contended query by SQL-text match, touches a ready-file the exact
instant it fires (i.e. the instant the lock is acquired, still inside `usleep()` before the query
returns), and the orchestrating test waits on that file before launching the second probe. This
makes the sequencing deterministic — reran the "no oversell" result twice and got the identical
outcome both times, not a coincidence of timing.

**Safety mechanics for reuse:** three independent guards, all checked in this session — (1) a
uniquely-named throwaway container never called `registro-mysql`/`mysql`, (2) `CartCheckoutRaceTest
::setUp()` reads raw `getenv('DB_HOST'/'DB_DATABASE')` and throws BEFORE calling `parent::setUp()`
(i.e. before the app — and therefore `DatabaseTruncation`'s `migrate:fresh` — ever boots) if either
looks like the dev DB, (3) `probe.php` re-checks the same thing independently since it can be
invoked standalone. Verified post-run: `docker ps -a` shows no orphaned probe containers, and
`registro.orders`/`registro.carts` both show 0 rows with `created_at >= NOW() - INTERVAL 3 HOUR` —
the dev database was never reached, not just "should not have been."

**Fixture gotcha carried over from the MySQL 8.0 gate work** ([[project_mysql_gate_27_failures_2026-08-15]]):
`orders.customer_first_name`/`customer_last_name` are `NOT NULL` on MySQL; SQLite doesn't enforce
it, so a fixture missing them only breaks on the real engine — same class of gap, different table.

**Baseline preserved:** full `docker compose exec -T app php artisan test` (SQLite, all suites) —
1479 passed, 5 skipped, unchanged from the pre-existing baseline this task specified. `./vendor/bin/pint
--test tests/Concurrency` clean.
