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
cross-connection row/gap locking). On MySQL the whole per-test transaction (RolePermissionSeeder's
~150 rows from `TestCase::setUp()` included) is left open on an abandoned connection, holding an
exclusive lock on `permissions.name = 'settings.manage'` (the first row every RefreshDatabase test
seeds) until PHP's GC eventually reclaims the leaked Application container — which can take longer
than one MySQL `innodb_lock_wait_timeout` (default 50s). Every OTHER RefreshDatabase test that
reaches its own seeding in the meantime — any class, unrelated to the one that leaked — blocks for
the full 50s before itself throwing `SQLSTATE[HY000]: 1205 Lock wait timeout exceeded`, cascading
for several tests in a row. **Never write a custom `tearDown()` that calls `Mockery::close()`
yourself** — the base class already does it, correctly ordered; if you need extra teardown logic,
put it in a method Laravel calls via `setUpTraits()`'s `tearDown{TraitName}` convention, not a raw
override that runs before `parent::tearDown()`.

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
