---
name: project_seed_once_per_process_2026-08-16
description: RefreshDatabase's $seeder property seeds reference data once per test process instead of once per test — 214s to 80s on MySQL 8.0, exact same 833/5/0 result
metadata:
  type: project
---

Reference/lookup data (RBAC roles+permissions, ~46 email templates, 5 vehicle types) used to be
reseeded by every single `RefreshDatabase` test via three `$this->artisan('db:seed', ...)` calls in
`Tests\TestCase::setUp()`. Moved to `database/seeders/TestReferenceDataSeeder.php`, wired as
`Tests\TestCase::$seeder = TestReferenceDataSeeder::class`. Full write-up (mechanism, four pitfalls
checked, both measured runs) lives in `.claude/rules/tests.md` -> "Reference data seeding" — this
memory is the pointer plus what's NOT in that file.

**Why:** CI minutes on GitHub Actions cost the user real money; the Feature-suite job on the MySQL
8.0 release gate (`deploy-production.yml`) was ~5.2 of ~10 total deploy minutes, and 3 seeders ×
833 tests was the dominant cost (real network/disk I/O per INSERT on MySQL, unlike SQLite
in-memory). Task explicitly forbade touching dev-MySQL or `.env.testing`, and required a real
before/after measurement on an isolated ephemeral `mysql:8.0`, one run each, not a series.

**The mechanism (verified by reading `vendor/laravel/framework` for the installed version, not
assumed):** `RefreshDatabase::migrateDatabases()` calls `$this->artisan('migrate:fresh',
$this->migrateFreshUsing())`, and `migrateFreshUsing()` (from `CanConfigureMigrationCommands`,
plain property reads: `property_exists($this, 'seeder')`) turns a `$seeder` property into
`--seeder=X`. `FreshCommand::needsSeeding()` is `$this->option('seed') || $this->option('seeder')`
— so `--seeder` alone (no `--seed`) is sufficient, `runSeeder()` always uses `--seeder` when given.
Critically, `migrateDatabases()` itself only runs once per process (`if (!
RefreshDatabaseState::$migrated)`), strictly BEFORE `beginDatabaseTransaction()` in the same
`refreshTestDatabase()` method — so the seeder's inserts land in the pre-transaction baseline every
later test's rollback restores to, not inside any one test's own transaction. This is Laravel's own
documented pattern for exactly this ("seed once, reuse via transactions"), not a workaround.

**Why a plain override in `Tests\TestCase` couldn't work instead:** every leaf test class does
`use RefreshDatabase;` DIRECTLY (139 of them). PHP trait-method precedence means a trait used
directly in a class always wins over an inherited method from a parent class, even one the parent
got from its own trait use — so overriding `migrateDatabases()`/`refreshTestDatabase()` in
`Tests\TestCase` would be silently shadowed by every leaf class's own `use RefreshDatabase;`. The
`$seeder` property has none of this problem because `CanConfigureMigrationCommands`'s methods read
`$this->seeder` as a plain property, and PHP property lookup follows ordinary inheritance — a
parent-declared default is visible to methods brought in by a trait used further down the chain.
This is the reason the fix is a one-line property in the base TestCase, not a rename of 139 files.

**Verified safe against 5 pitfalls before trusting it** (details + exact test names in
`tests.md`): tenant-context dependency at seed time (`EmailTemplate`'s `BelongsToOrganization`
`creating()` hook — safe because process bootstrap has no HTTP request/Filament tenant/session
yet), idempotency against migrations that seed the same keys, tests asserting on row counts
(`CreateOwnerCommandTest` deletes-then-asserts-zero inside its own transaction — already handled,
no change needed), migration-pin tests that self-contain their own delete+reseed
(`OrderHandoverReturnEmailTemplateMigrationTest`, `RentalReturnReminderEmailTemplateMigrationTest`),
and non-`RefreshDatabase` classes (26 of them — never seeded this data before, still don't, by
construction of the mechanism).

**Measured, ephemeral `mysql:8.0` on the `app` container's own Docker network
(`registro-test-mysql8-gate`, no host port published, never touched `registro-mysql`), one run
each via `git stash`/`git stash pop` of just `tests/TestCase.php`:**
- Before: 833 passed, 5 skipped, 2637 assertions, 0 failed — **214.32s**
- After: 833 passed, 5 skipped, 2637 assertions, 0 failed — **80.32s** (~62% reduction)

Identical pass/skip/assertion counts both runs — this was a pure perf change, zero behavior change,
zero weakened assertions. SQLite locally: unaffected within noise (already fast enough that the
per-row cost this removes barely registers in-memory).

**Reusable pattern for future perf work on this test suite:** before assuming a Laravel testing
trait can only be extended by subclassing/overriding methods, check whether it reads a plain
property instead (`property_exists($this, ...)`) — those compose safely across trait-precedence
rules in a way method overrides in a shared base `TestCase` do not. See [[project_mysql_gate_27_failures_2026-08-15]]
for the sibling investigation into what SQLite hides on this same MySQL 8.0 gate.
