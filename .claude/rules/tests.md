---
paths:
  - "tests/**"
---

# Testing Rules

## Test Organization

```
tests/
├── Unit/           # Isolated unit tests (no database)
├── Feature/        # Integration tests (with database)
└── Browser/        # Dusk browser tests (if used)
```

## Naming Conventions

```php
// Test classes
class UserControllerTest extends TestCase

// Test methods - descriptive names
public function test_user_can_book_appointment(): void
public function test_guest_cannot_access_admin_panel(): void
```

## Database Strategy

### Feature Tests
```php
use RefreshDatabase;

public function test_user_can_create_appointment(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/appointments', $appointmentData);

    $response->assertCreated();
}
```

### Unit Tests
- No database access
- Use mocks for dependencies
- Fast execution

## Reference data seeding (once per test process, not once per test)

RBAC roles/permissions, transactional email templates, and vehicle types are reference/lookup
data every `RefreshDatabase` test needs, but no test should be re-creating from scratch. They live
in `database/seeders/TestReferenceDataSeeder.php`, wired as `Tests\TestCase::$seeder`:

```php
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected $seeder = \Database\Seeders\TestReferenceDataSeeder::class;
}
```

**Why this works, mechanically:** `RefreshDatabase`'s `migrateFreshUsing()` (from
`CanConfigureMigrationCommands`) reads the `$seeder` property and passes `--seeder=...` to
`migrate:fresh`. `migrate:fresh --seeder=X` runs `db:seed --class=X` as part of the SAME command —
i.e. still inside `migrateDatabases()`, which only executes once per process (guarded by
`RefreshDatabaseState::$migrated`) and strictly BEFORE the first test's `beginDatabaseTransaction()`.
Every later test's per-test transaction therefore starts from — and rolls back to — an
already-seeded baseline, instead of re-inserting ~185 rows (34 permissions, 4 roles, ~46 email
templates, 5 vehicle types) from scratch on every single test. This is the officially documented
Laravel mechanism for exactly this use case, not a workaround — see `vendor/laravel/framework/
src/Illuminate/Foundation/Testing/Traits/CanConfigureMigrationCommands.php`. No special-casing is
needed for tests that don't use `RefreshDatabase`: `migrateFreshUsing()`/`$seeder` is only ever
consulted from inside `RefreshDatabase::migrateDatabases()`, so a test class that never triggers
`refreshDatabase()` never triggers this either.

**Measured effect (2026-08-16, ephemeral `mysql:8.0`, isolated Docker network, never dev-MySQL,
one run each before/after):** `--testsuite=Feature`, 833 passed / 5 skipped / 0 failed identically
both times — **214.32s → 80.32s** (~62% reduction). SQLite locally: unaffected within noise (already
fast; the per-row cost that dominates on MySQL's real network/disk I/O barely registers in-memory).

**What was checked before trusting this** (do this again before touching this mechanism):
- **Tenant-context safety:** `EmailTemplate` uses `BelongsToOrganization`, whose `creating()` hook
  auto-assigns `organization_id` from `TenantFeature::currentTenant()` when unset. Seeding now runs
  at absolute process bootstrap — before any HTTP request, Filament tenant, or session exists — so
  `currentTenant()` deterministically returns `null` and every seeded template stays global
  (`organization_id = NULL`), identical to today's per-test behavior. `RolePermissionSeeder` and
  `VehicleTypeSeeder` have no tenant dependency at all (no `BelongsToOrganization`).
- **Idempotency:** all three seeders use `firstOrCreate`/`updateOrCreate` keyed on natural identity
  (permission name, role name, template key+language+organization_id, vehicle type slug) — required
  for a seeder invoked via `--seeder` to be safe to interact with migrations that seed the same
  rows (`2025_12_02_224732_seed_email_templates.php` and friends run first, as part of the same
  `migrate:fresh`; `EmailTemplateSeeder` then `updateOrCreate`s the same keys — same order as
  before, unaffected by running once instead of 833 times).
- **Tests that need a truly empty table** (fresh-install simulation) delete the reference rows
  themselves, inside their own per-test transaction — this still works unchanged, because the
  delete is scoped to that one test's transaction and rolls back afterward:
  `CreateOwnerCommandTest::test_it_seeds_roles_when_the_database_has_none()` explicitly
  `Role::query()->delete()` etc. before asserting `Role::count() === 0`. **Do not "fix" a test like
  this by weakening its assertion — the delete-inside-the-test pattern is the correct answer, not
  a workaround.**
- **Migration-pin tests** that assert exact row counts for a specific migration's rows
  (`OrderHandoverReturnEmailTemplateMigrationTest`, `RentalReturnReminderEmailTemplateMigrationTest`)
  are self-contained — they either compare against the already-migrated baseline (unaffected either
  way) or `DB::table(...)->delete()` and reseed inside their own transaction before asserting.
- **Non-`RefreshDatabase` test classes** (Unit tests, mostly) never seeded this data before and
  still don't — unaffected by construction (see mechanism above).

**Adding a new reference/lookup seeder:** add it to `TestReferenceDataSeeder::run()`'s `$this->call([...])`
list, keep it idempotent (`firstOrCreate`/`updateOrCreate`, never raw `insert()`), and confirm it
has no tenant-context dependency that would produce different rows at process-bootstrap time than
per-request time. **Do not** revert to seeding it from `Tests\TestCase::setUp()` via
`$this->artisan('db:seed', ...)` — that reintroduces the O(number of tests) reseed cost this
pattern exists to remove.

## Assertions

### Common Patterns
```php
$response->assertOk();              // 200
$response->assertCreated();         // 201
$response->assertNotFound();        // 404
$response->assertForbidden();       // 403
$response->assertUnauthorized();    // 401

$this->assertDatabaseHas('appointments', [
    'user_id' => $user->id,
    'status' => 'pending',
]);
```

## Factories

### Always Use Factories
```php
// GOOD
$user = User::factory()->create();

// BAD
$user = User::create(['name' => 'Test', ...]);
```

### States
```php
User::factory()->admin()->create();
User::factory()->unverified()->create();
```

## Test Coverage

- Aim for 80%+ coverage on critical paths
- Always test: Authentication, Authorization, Validation
- Run: `composer run test` before commits

### Never Weaken a Test to Pass It

Jedyna rzecz gorsza niż niezaliczony test to redukcja pokrycia testami.
NIGDY nie usuwaj/pomijaj/osłabiaj testu żeby uzyskać zielone CI — napraw kod
pod test, nie test pod kod. Jeśli test wydaje się błędny — zatrzymaj się i
zapytaj, nie modyfikuj go samodzielnie w ramach tego samego zadania.

## CI/CD Environment Rules

### CRITICAL: .env.testing chroni dev MySQL (NIGDY nie usuwaj!)

**Incident 2026-03-17:** `php artisan test` w Docker użył dev MySQL zamiast SQLite. `RefreshDatabase` zrobiło `migrate:fresh` na żywej bazie — utrata WSZYSTKICH danych.

**Przyczyna:** Docker OS-level env vars (priorytet 3) nadpisują phpunit.xml `<env>` tagi (priorytet 4).

**Rozwiązanie:** `.env.testing` — Laravel ładuje go ZAMIAST `.env` gdy `APP_ENV=testing`.

```
Priorytet env variables (od najwyższego):
1. config()->set() w kodzie testu
2. <server> tagi w phpunit.xml
3. Docker/OS environment variables  ← TU JEST DB_HOST=mysql
4. <env> tagi w phpunit.xml         ← TU JEST DB_CONNECTION=sqlite (PRZEGRYWA!)
5. .env.testing                     ← ZASTĘPUJE CAŁY .env (WYGRYWA!)
6. .env                             ← dev config (MySQL)
```

**NIGDY nie usuwaj `.env.testing`!** Bez niego testy ZNISZCZĄ bazę dev.

### CRITICAL: Tests MUST pass in CI environment

**CI Environment Differences:**
- Uses SQLite in-memory database (`.env.testing` + `phpunit.xml`) for `docker compose exec app php artisan test`
- The RELEASE GATE (`deploy-production.yml`'s "PHPUnit Tests" job) runs the SAME Feature suite
  against a real ephemeral **`mysql:8.0`** service, matching production's engine — see the
  "MySQL 8.0 gate" section below. A change that only passes on SQLite is NOT done.
- Uses `APP_LOCALE=pl` (configured in phpunit.xml)
- No Docker services (Redis may use `array` driver)
- `.env.testing` is committed to repo — provides safe test defaults

### Database Compatibility
```php
// GOOD: Use factories (works with any DB)
$service = Service::factory()->create();

// BAD: Use seeders in tests (may have DB-specific issues)
$this->artisan('db:seed', ['--class' => 'SomeSeeder']);
```

**When seeders are required:**
- Ensure they work with both MySQL AND SQLite
- Avoid MySQL-specific SQL (JSON columns, spatial types)

### Locale/Translations in Tests
```php
// GOOD: Check translation key or use app()->setLocale()
app()->setLocale('pl');
$this->assertStringContainsString('lokalizacja', $errorMessage);

// BAD: Assume locale without setting it
$this->assertStringContainsString('lokalizacja', $errorMessage);

// BETTER: Use translation helper
$expected = __('service_area.validation.outside_area', [...]);
$this->assertEquals($expected, $errorMessage);
```

### Before Pushing Feature Tests
1. Run `./vendor/bin/pint --test` - code style check
2. Run `docker compose exec app php artisan test` - tests in Docker
3. OR run tests with SQLite: `php artisan test` (requires pdo_sqlite)

## MySQL 8.0 gate (`deploy-production.yml`) — what SQLite hides

The Feature suite ran against real `mysql:8.0` for the first time on 2026-08-15 (v0.13.0-rc11,
after PR #187 fixed the gate's DB image from `mariadb:10.11`) and immediately surfaced **27
failures that were invisible on SQLite** — 0 on `docker compose exec app php artisan test`, same
commit. Three genuinely independent mechanisms, none of them a MySQL "flakiness" issue:

**1. Raw `PRAGMA foreign_keys = OFF/ON` is SQLite-only syntax** — 1064 syntax error on MySQL.
Use `Schema::disableForeignKeyConstraints()` / `Schema::enableForeignKeyConstraints()` instead —
Laravel compiles the right statement per driver (`PRAGMA` on SQLite, `SET FOREIGN_KEY_CHECKS` on
MySQL). Grep for `PRAGMA` in `tests/**` before adding a new one.

**2. SQLite never enforces `ENUM`.** A fixture inserting a string outside a column's real enum
(`payments.status = 'verified'` when the migration declares `pending|success|failed|refunded`)
silently "works" on SQLite (stored as plain text, no constraint) and throws
`1265 Data truncated for column` on MySQL. Any `DB::table(...)->insert([...])` fixture bypassing a
factory must be checked against the column's REAL enum in its migration, not assumed correct
because it didn't crash — the codebase's real orders/order_items status columns are plain
`string()`, not enum, so this only bites the columns that genuinely are enums (grep
`$table->enum(` in `database/migrations/**` for the current list).

**3. `tearDown() { Mockery::close(); parent::tearDown(); }` inverts Laravel's own safe cleanup
order and can leak the per-test transaction on MySQL.** `Illuminate\Foundation\Testing\TestCase`'s
own `tearDown()` already calls `Mockery::close()` — but only AFTER
`callBeforeApplicationDestroyedCallbacks()` (which runs `RefreshDatabase`'s rollback), and inside a
try/catch that still lets `InvalidCountException` surface without skipping cleanup. A test file
that manually calls `Mockery::close()` FIRST, before `parent::tearDown()`, bypasses that ordering:
if a `shouldReceive(...)->once()` expectation goes unmet, `Mockery::close()` throws right there and
`parent::tearDown()` — the rollback — never runs. On SQLite this is harmless (no InnoDB-style
cross-connection row/gap locking). On MySQL the whole per-test transaction is left open on an
abandoned connection, holding locks on whatever rows that test's own body touched, until PHP's GC
eventually reclaims the leaked Application container — which can take longer than one MySQL
`innodb_lock_wait_timeout` (default 50s). Every OTHER RefreshDatabase test that needs one of those
same rows in the meantime — any class, unrelated to the one that leaked — blocks for the full 50s
before itself throwing `SQLSTATE[HY000]: 1205 Lock wait timeout exceeded`, cascading for several
tests in a row. At the time this was found (2026-08-15), `RolePermissionSeeder`'s ~150 rows were
reseeded inside every single test's own transaction via `TestCase::setUp()`, which made
`permissions.name = 'settings.manage'` — the first row that seeding wrote — a guaranteed universal
collision point, so a leak anywhere cascaded to nearly the whole suite. PR #192 (2026-08-16) moved
that seeding to run exactly once per process, before any test's transaction begins (see "Reference
data seeding" above) — a leaked transaction today only blocks tests that happen to touch the same
rows the leaking test's body did, not automatically every RefreshDatabase test in the run. The
underlying rule is unchanged either way. **Never write a custom `tearDown()` that calls
`Mockery::close()` yourself** — the base class already does it, correctly ordered; if you need
extra teardown logic, put it in a method Laravel calls via `setUpTraits()`'s `tearDown{TraitName}`
convention, not a raw override that runs before `parent::tearDown()`.

The actual TRIGGER for #3 in this incident was unrelated to MySQL itself: `deploy-production.yml`'s
"Run PHPUnit tests" step set `QUEUE_CONNECTION: redis` / `CACHE_DRIVER: redis` / `CACHE_STORE:
redis` (inherited unvalidated from the workflow's very first commit — this job had literally never
run before), overriding `.env.testing`'s deliberate `sync`/`array` defaults that hundreds of
existing tests are written against (see e.g. `ProcessRentalReturnRemindersJobTest`'s own docblock).
Under `redis`, every `ShouldQueueAfterCommit` notification dispatch just sits unprocessed in Redis
instead of running synchronously inside the test — `EmailSend::where(...)->firstOrFail()` throws
`ModelNotFoundException`, a `Mockery::mock(EmailService::class)->shouldReceive('sendFromTemplate')
->once()` sees 0 calls, etc. Fixed by making that step's queue/cache vars match `.env.testing`
(`sync`/`array`) — this job only legitimately needs to override `DB_*`, to point at the ephemeral
MySQL 8.0 service instead of SQLite. Test suites should be deterministic; if a real Redis-queue
integration test is ever wanted, it should be a small, explicitly-scoped test, not the default
queue driver for the entire Feature suite.

**Two more failures were pre-existing SQLite-vs-MySQL environment-parity gaps in the tests
themselves, not the mechanisms above** — `OrganizationSingletonLockMigrationTest` hardcoded
`assertSame('sqlite', DB::connection()->getDriverName())` when the actual invariant under test
("column absent when `TENANT_SLUG` is unset") holds on any driver; and
`TenantProvisioningGuardsTest::test_assert_passes_when_slug_and_database_agree` set `TENANT_SLUG`
via `config()->set()` at runtime, which can never retroactively trigger the singleton-lock
migration's own `shouldLockSingleton()` guard (mysql + tenant_slug already set at **migrate** time)
in a harness that migrates once, before any test runs — fixed by adding the column directly in the
test, matching what the real migration would have built.

**Full incident + fix**: `.claude/rules/ci-cd-troubleshooting.md` → "Incydent 2026-08-15" and the
one directly above it (DB engine). Before adding a NEW raw `DB::statement()`/fixture-with-a-status/
custom `tearDown()` to any Feature test: check this list first.

## tests/Browser (Pest v4 real-browser E2E)

- In-process server (`LaravelHttpServer`) — Playwright/Chromium hit the app in the SAME PHP process, no `artisan serve`.
- `SESSION_SECURE_COOKIE=false` in `.env.testing` — plugin forces `http://`; `true` silently drops cookies.
- Filament login rate-limits at 5/min per IP, array cache is per-process → leftover hit 429s next test's login — `Cache::flush()` first.
- Only `grent`/`qatest` slugs resolve to `127.0.0.1` in `/etc/hosts` — other slugs fail at DNS, not app logic.
- Selector gotcha: Filament ids are the dotted statePath (`id="form.email"`). Bare `"form.email"` parses as CSS `tag.class` and hangs — always `fill('[id="form.email"]', ...)`.
- **Cross-subdomain tests must set `session.domain` explicitly.** `.env.testing` leaves it unset, so Laravel issues a host-only cookie that never travels to another tenant's subdomain — a "tenant A's admin is rejected on tenant B" test would then pass because the browser sent no credentials at all, not because anything defended. Real deployments use a wildcard (`.env.staging.example`), which is the precondition VULN-003 is about. `TenantIsolationTest` forces `session.domain` in `beforeEach` for exactly this reason.
- **One browser context per test.** Opening a second while the first is alive deadlocks the process — no exception, no timeout, no output, just a hang, because the AmpHttp server and the Playwright client share one PHP process and cooperate on fibers. To switch users, drive the real logout form and log in again (see `EmployeeCreationTest`). Note this is NOT needed for multi-tenant assertions: the tenants live in the database, one session sees them all.
- Chromium is baked into the image at `/opt/playwright-browsers` (`PLAYWRIGHT_BROWSERS_PATH`), NOT `~/.cache` — `.:/var/www` would shadow anything under the project dir, and a container-local install dies on the next `build`. The version is derived from `package.json` at build time, so **bumping `playwright` there requires `docker compose build app`** — skip it and you get a misleading "Executable doesn't exist", not a version warning.
- **pest#1734 workaround (open upstream, no vendor patch):** `LaravelHttpServer` builds every request from a hardcoded `127.0.0.1` URL — the SERVER bag never gets the real tenant Host, only the HEADERS bag does. Livewire's `PersistentMiddleware::makeFakeRequest()` rebuilds headers FROM the server bag on every `/livewire/update`, so `ResolveTenant` sees `127.0.0.1` and redirects to root. Fixed by `App\Http\Middleware\Testing\PestBrowserHostBugWorkaround`, prepended to the GLOBAL stack ONLY under `APP_ENV=testing` (`bootstrap/app.php`) — dead everywhere else. Mechanism in the class docblock. Delete both once pest/pest#1734 ships upstream.

## tests/Concurrency (two-connection oversell race, MySQL only)

`kontrakt-dostepnosci.md` Zasada 6 ("dowód, nie deklaracja"): every other oversell test in this repo
(`tests/Unit`, `tests/Feature`) is sequential and runs on SQLite — it would still pass with the lock
discipline in `CartService::convertToOrder()` completely removed, because SQLite has no InnoDB-style
row locking to defeat. `tests/Concurrency/CartCheckoutRaceTest.php` is the one test in the repo that
actually proves the locks do something, using two real OS processes (two real InnoDB sessions) against
a throwaway `mysql:8.0` container — never `registro-mysql`, the dev database.

**Run it:**

```bash
bash scripts/test-concurrency.sh
```

Provisions a uniquely-named `mysql:8.0` container on `app_registro`, points `php artisan test
--testsuite=Concurrency` at it via explicit `-e DB_*` overrides, and destroys the container
afterward (`trap cleanup EXIT`, survives a failing run). Excluded from `phpunit.xml`'s
`defaultTestSuite` (same precedent as `tests/Browser`) — a plain `php artisan test` or a manual
`--testsuite=Concurrency` without the script skips both tests immediately, with a message pointing
back here, rather than silently passing on SQLite or (worse) resolving to the dev connection.

**Mechanism:** `tests/Concurrency/Support/probe.php` is a standalone bootstrap script (same two
lines as `artisan`), spawned via `proc_open()` — a single synchronous PHP process cannot hold one
transaction's lock while a second transaction blocks on it, so true concurrency needs two real OS
processes, not two named connections in one process. Coordination is by FILE SIGNAL + injected
delay, never a guessed sleep: a `DB::listen()` hook in the probe fires the instant its own
transaction issues the `Service::lockForUpdate()` query CartService always issues first, touches a
`--ready-file`, then holds the transaction open for `--delay-ms` before letting the query return.
The orchestrating test waits for that file before starting the second probe — deterministic, not a
timing race (`feedback_verify_the_right_axis` / `feedback_no_machine_thrashing`: no `for` loop
running this N times and hoping).

**Measured result (2026-08-27, throwaway `mysql:8.0`, same scenario — two checkouts racing the last
unit of an overlapping-dates rental — each broken variant applied for one run then reverted, `git
diff` on `app/` clean afterward):**

| Variant (`CartService.php` ~213-229) | Result |
|---|---|
| unmodified (both `Service::lockForUpdate()` **and** `forUpdate: true`) | no oversell — exactly one order created, confirmed twice |
| `forUpdate: true` → `forUpdate: false`, lock kept | **oversell** — both checkouts succeeded, two orders for one unit |
| `Service::lockForUpdate()` → plain `findOrFail()`, `forUpdate: true` kept | no oversell — one order created, confirmed twice (deterministic, not luck) |

**Verdict — narrower than the docblock's "both required" framing:** for this exact scenario,
`forUpdate: true` (the locking read on `rentals`/`order_items`) is the layer that actually closes
the race; removing it reproduces the oversell every time. `Service::lockForUpdate()` measured as
**not** the closing mechanism here — most likely because MySQL's own next-key/gap locking on the
`FOR UPDATE` range scan over `order_items`/`rentals` already serialises concurrent inserts into the
same overlapping date range, independent of any lock on the `services` row. This does not mean
`Service::lockForUpdate()` is provably useless in general — it wasn't re-tested against the
multi-item/deadlock-ordering scenarios kontrakt-dostepnosci.md Zasada 4 also cites as its
justification — only that THIS harness's two scenarios don't depend on it. Zasada 4's own text
already frames it as a deliberate over-serialisation trade paid for "zero ryzyka regresji", so this
finding doesn't call for removing it — it calls for not citing it as THE thing this specific harness
proves.

**Fixtures need `customer_first_name`/`customer_last_name`** — `orders.customer_first_name` is
`NOT NULL` on MySQL; the existing SQLite suite never caught this because SQLite doesn't enforce it,
same class of gap as the "MySQL 8.0 gate" section above.

**Scope note:** no per-location scenario — locations don't exist yet (Faza 4 of the multi-location
plan), so `getAvailableQuantity()` has no `$locationId` to race on today. Add one alongside that work.

## Shell tests (`scripts/server/**`)

`scripts/server/*.sh` (apply/deploy/sync-certificate/tenant-check/tenant-backup)
have their own suite, separate from PHPUnit — plain bash, no bats (not
installed, and this project forbids adding a new package-manager dependency
for it). Run with:

```bash
bash tests/shell/run.sh
```

Runs in ~16 s. Most of it is offline; three cases start real containers because
the property they guard is not decidable by inspecting the file alone:
`19_nginx_*` (`nginx:1.25-alpine`, `--network none`, `nginx -t` — nginx still
starts when no upstream container exists; its regex predecessor missed a
trailing-space variant and an `upstream {}` block, the latter unfixable by
the recommended fix), `30_deploy_production_db_engine_matches_prod.sh`
(`mysql:8.0` + a `mariadb:10.11` negative control — the CI test gate's DB
engine must actually accept the JSON-operator syntax production's migrations
use, not just be *named* the same as production's compose file), and
`31_redis_hardening_survives_existing_appendonlydir.sh` (`redis:7.2-alpine`
against a volume with a REAL, organically-created `appendonlydir` — a
hand-written directory inherits the shell's own umask and never reproduces
the 0700 permission bits `v0.13.0-rc12` actually crashed on; extracts the
compose file's real `cap_add` list rather than hardcoding it, with the old
SETUID+SETGID-only spec as a negative control that must reproduce `find:
./appendonlydir: Permission denied` verbatim).

Every other case is offline and instant, with no real Docker daemon/server/network —
every `docker`/`certbot`/`su`/`git`/`restic` call is a fake executable on
`PATH` that records its own invocation to a call log the test then asserts
on (see `tests/shell/lib/harness.sh`'s own header for the full reasoning,
including why extraction-from-the-real-file is used for functions that live
inside a script with top-level `set -euo pipefail` and cannot run standalone).

**A fixed shell bug in `scripts/server/**` gets a test in `tests/shell/cases/`
in the same change** — same rule as a PHP bug getting a regression test.
Assert on observable behavior (which command ran, with which arguments, what
was written to disk, what exit code) — never grep the script for a string; a
grep passes even when the behavior is broken and breaks on innocent
refactors. Full catalog of what's pinned today:
`app/docs/deployment/tenant-apply.md` → "Permanent regression suite".

## Laravel Pint (Code Style)

**CRITICAL:** All code MUST pass Pint before commit.

```bash
# Check code style (used in CI)
./vendor/bin/pint --test

# Auto-fix code style
./vendor/bin/pint
```

**CI runs `./vendor/bin/pint --test` before PHPUnit tests.**
If Pint fails, tests won't even run!
