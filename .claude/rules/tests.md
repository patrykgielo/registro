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
