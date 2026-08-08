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
- Uses SQLite in-memory database (`.env.testing` + `phpunit.xml`)
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

## tests/Browser (Pest v4 real-browser E2E)

- In-process server (`LaravelHttpServer`) — Playwright/Chromium hit the app in the SAME PHP process, no `artisan serve`.
- `SESSION_SECURE_COOKIE=false` in `.env.testing` — plugin forces `http://`; `true` silently drops cookies.
- Filament login rate-limits at 5/min per IP, array cache is per-process → leftover hit 429s next test's login — `Cache::flush()` first.
- Only `grent`/`qatest` slugs resolve to `127.0.0.1` in `/etc/hosts` — other slugs fail at DNS, not app logic.
- Selector gotcha: Filament ids are the dotted statePath (`id="form.email"`). Bare `"form.email"` parses as CSS `tag.class` and hangs — always `fill('[id="form.email"]', ...)`.
- Chromium is baked into the image at `/opt/playwright-browsers` (`PLAYWRIGHT_BROWSERS_PATH`), NOT `~/.cache` — `.:/var/www` would shadow anything under the project dir, and a container-local install dies on the next `build`. The version is derived from `package.json` at build time, so **bumping `playwright` there requires `docker compose build app`** — skip it and you get a misleading "Executable doesn't exist", not a version warning.
- **pest#1734 workaround (open upstream, no vendor patch):** `LaravelHttpServer` builds every request from a hardcoded `127.0.0.1` URL — the SERVER bag never gets the real tenant Host, only the HEADERS bag does. Livewire's `PersistentMiddleware::makeFakeRequest()` rebuilds headers FROM the server bag on every `/livewire/update`, so `ResolveTenant` sees `127.0.0.1` and redirects to root. Fixed by `App\Http\Middleware\Testing\PestBrowserHostBugWorkaround`, prepended to the GLOBAL stack ONLY under `APP_ENV=testing` (`bootstrap/app.php`) — dead everywhere else. Mechanism in the class docblock. Delete both once pest/pest#1734 ships upstream.

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
