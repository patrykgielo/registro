---
paths:
  - "app/Actions/**"
  - "app/Listeners/**"
  - "app/Http/Controllers/Auth/**"
---

# Spatie Roles & Permissions Rules - CRITICAL

## ZASADA: ZAWSZE `firstOrCreate` przed `assignRole`

```php
// ✅ PRAWIDŁOWO — bezpieczne, idempotentne
Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
$user->assignRole('admin');

// ❌ ŹLE — crash jeśli rola nie istnieje w tabeli `roles`
$user->assignRole('admin');  // RoleDoesNotExist exception!
```

## Dlaczego

- Role seedowane przez `RolePermissionSeeder` — ale seeder NIE JEST wywoływany w deploy
- `deployment.md` zabrania: "NIGDY nie uruchamiaj seederów w CI/CD pipeline"
- Testy przechodzą bo `TestCase::setUp()` ręcznie seeduje role
- Produkcja/fresh dev DB nie ma ról → `assignRole()` rzuca `RoleDoesNotExist`

## Incident 2026-03-14

**Problem:** Rejestracja biznesowa → `CreateOrganizationWithOwner` → `$user->assignRole('admin')` → `RoleDoesNotExist: There is no role named 'admin' for guard 'web'`

**Root cause:** `migrate:fresh` bez `--seed` → tabela `roles` pusta. Testy przechodzą bo `TestCase::setUp()` seeduje role.

**Fix:** `Role::firstOrCreate()` przed każdym `assignRole()`.

**Zapobieganie:** Ta reguła. Każdy nowy `assignRole()` MUSI mieć `firstOrCreate` guard.

## Istniejące role w systemie

| Rola | Guard | Użycie |
|------|-------|--------|
| `admin` | web | Właściciel organizacji (onboarding) |
| `customer` | web | Klient (rejestracja customer) |
| `staff` | web | Pracownik (dodawany przez admina) |
| `super-admin` | web | Super-admin platformy |

## Pliki z `assignRole()`

| Plik | Rola | Status |
|------|------|--------|
| `app/Actions/Onboarding/CreateOrganizationWithOwner.php` | admin | ✅ Zabezpieczone |
| `app/Listeners/AssignCustomerRole.php` | customer | ✅ Zabezpieczone |
| `database/seeders/RolePermissionSeeder.php` | all | ✅ Seeder (firstOrCreate) |

## Kiedy seedować role na dev

```bash
# Po migrate:fresh ZAWSZE dodaj --seed
docker compose exec -T app php artisan migrate:fresh --seed

# Lub ręcznie seeduj role
docker compose exec -T app php artisan db:seed --class=RolePermissionSeeder
```
