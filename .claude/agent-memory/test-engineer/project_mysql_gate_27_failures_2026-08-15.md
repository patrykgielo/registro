---
name: project_mysql_gate_27_failures_2026-08-15
description: deploy-production.yml's Feature suite ran on real mysql:8.0 for the first time and found 27 failures invisible on SQLite — four distinct mechanisms, fixed in PR #188
metadata:
  type: project
---

`deploy-production.yml`'s "PHPUnit Tests" job ran the Feature suite against a real `mysql:8.0`
service for the first time in the project's history (v0.13.0-rc11, right after PR #187 fixed the
gate's DB image `mariadb:10.11` → `mysql:8.0`). Same commit: 0 failures on
`docker compose exec app php artisan test` (SQLite) vs **27 failed, 5 skipped, 806 passed** on the
MySQL gate. Fixed in PR #188 (branch `fix/mysql-gate-27-feature-failures`).

**Why:** SQLite structurally cannot exercise several classes of bug that only manifest under
InnoDB's real locking/constraint semantics, or under a CI env config nobody had ever exercised.

**How to apply:** Full mechanism writeup lives in the CHECKED-IN rules, not duplicated here —
read `.claude/rules/tests.md` → "MySQL 8.0 gate — what SQLite hides" and
`.claude/rules/ci-cd-troubleshooting.md` → "Incydent 2026-08-15" before touching any Feature test
that does a raw `DB::statement()`, inserts a `status`/enum-like column via `DB::table(...)->insert()`
bypassing a factory, or writes a custom `tearDown()`. One-line summary of the four mechanisms found
(all real, none "MySQL flakiness"):
1. Raw `PRAGMA foreign_keys = OFF` is SQLite-only syntax → use `Schema::disableForeignKeyConstraints()`.
2. SQLite never enforces `ENUM` — a fixture status value outside the real enum only throws on MySQL.
3. **The interesting one:** `tearDown() { Mockery::close(); parent::tearDown(); }` (in 4 files)
   inverts Laravel's own safe teardown order. An unmet mock expectation makes `Mockery::close()`
   throw BEFORE `RefreshDatabase`'s rollback runs — leaking the whole per-test transaction on MySQL,
   holding a lock on `permissions.name = 'settings.manage'` (first row every test seeds) that
   cascades ~50s-timeout failures across UNRELATED test classes for several tests in a row. SQLite
   never surfaces this (no InnoDB-style locking). Fix: never write a custom `tearDown()` that calls
   `Mockery::close()` — Laravel's base `TestCase` already does it, correctly ordered.
4. **The actual trigger for #3:** the CI step had `QUEUE_CONNECTION=redis`/`CACHE_DRIVER=redis`
   present, unvalidated, since the repo's very first commit — overriding `.env.testing`'s deliberate
   `sync`/`array` that hundreds of tests assume (documented in their own docblocks). Fixed by
   reverting that step's queue/cache vars to match `.env.testing`; it should only override `DB_*`.

Plus 2 unrelated pre-existing SQLite-only test assumptions (`OrganizationSingletonLockMigrationTest`,
`TenantProvisioningGuardsTest`) fixed alongside, same PR.

**Verified, not guessed:** every fix confirmed on a throwaway `mysql:8.0` Docker container (created
fresh, network-joined to `app_registro`, destroyed after — never touched `registro-mysql`, the real
dev DB). Full `--testsuite=Feature` on that container after all fixes: **0 failed, 5 skipped
(pre-existing, unrelated — `BookingServiceAreaBypassTest`/rate-limit/booking-step-3 skips, NOT the
"5 known failures" this repo's baseline used to describe), 833 passed.**

**Reusable technique for next time a CI-only failure needs reproducing:** don't guess the mechanism
from the CI summary line — pull the REAL job log via `gh api repos/<owner>/<repo>/actions/jobs/<id>/logs`
(NOT `gh run view --log`, which truncated to ~2800 lines / lost most of the run in this session) and
grep the actual SQLSTATE/exception text per failing test. The CI-provided "established facts" framing
in a task prompt can be a paraphrase of a real trace, not the trace itself — in this case "the trace
is always RolePermissionSeeder" was true for only 5 of the 27 failures; the other 22 had three
completely different causes that direct log inspection revealed in minutes, where guessing from the
summary alone would have taken much longer and risked a wrong fix (e.g. "add retry"/"raise
innodb_lock_wait_timeout", exactly what the task explicitly forbade accepting without proof).

**Live MySQL lock inspection technique** (for catching a real, transient InnoDB lock in the act):
`SHOW PROCESSLIST` to find the blocked connection's `Time`, then
`SELECT * FROM information_schema.innodb_trx` for `trx_state='LOCK WAIT'`/`RUNNING` + `trx_rows_locked`/
`trx_rows_modified` (a suspiciously high row count on an idle "Sleep" connection is the leak), then
`SELECT * FROM sys.innodb_lock_waits` for the exact blocking_trx_id/blocking_pid and lock mode
(`X,REC_NOT_GAP` = exclusive record lock, not a gap lock) on the specific index. This is what proved
the leaked-transaction theory beyond the timing coincidence alone.

**Redis/Horizon contamination risk when reproducing CI's `QUEUE_CONNECTION=redis` locally:** this
repo's dev `docker-compose.yml` runs Horizon continuously against the SAME `registro-redis`
container used for local reproduction. If a local repro run pushes a real job to Redis under the
SAME queue-name prefix Horizon listens to (default `REDIS_PREFIX` = `registro-database-`, shared),
the always-running dev Horizon container can pick it up and process it against DEV MySQL — a
dev-DB write from a supposedly-isolated test repro. Caught and killed mid-run in this session before
any job was actually processed (confirmed via `SHOW PROCESSLIST`/`innodb_trx` — no dev-DB writes
occurred). If a future task needs to reproduce `QUEUE_CONNECTION=redis` locally, prefer overriding
`REDIS_PREFIX` to something unique per repro run to keep it out of Horizon's default queue names,
or just don't run with a real queue connection unless the task specifically requires it.
