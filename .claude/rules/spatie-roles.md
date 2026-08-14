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
| `app/Actions/Onboarding/ProvisionTenantOrganization.php` | admin | ✅ Zabezpieczone |
| `app/Listeners/AssignCustomerRole.php` | customer | ✅ Zabezpieczone |
| `database/seeders/RolePermissionSeeder.php` | all | ✅ Seeder (firstOrCreate) |

## Permissions — module-namespaced (Phase 6)

Od Phase 6 permissions używają formatu `module.action`:

| Moduł | Permissions |
|-------|-------------|
| `settings` | settings.manage |
| `services` | services.view, services.create, services.edit, services.delete |
| `bookings` | bookings.view, bookings.create, bookings.edit, bookings.delete, bookings.view_own, bookings.cancel_own |
| `rentals` | rentals.view, rentals.create, rentals.edit, rentals.delete |
| `staff` | staff.view, staff.create, staff.edit, staff.delete, staff.manage_availability, staff.view_availability |
| `customers` | customers.view, customers.create, customers.edit, customers.delete |
| `communication` | communication.manage_templates, communication.view_logs, communication.view_events, communication.manage_suppressions |
| `website` | website.manage |
| `vehicles` | vehicles.view |
| `service_area` | service_area.manage |
| `users` | users.view, users.create, users.edit, users.delete |

**Migracja:** `2026_03_15_000001_rename_permissions_to_module_namespaced.php` automatycznie rename'uje stare nazwy.

**Stary → Nowy:**
```
'view services' → 'services.view'
'create appointments' → 'bookings.create'
'manage email templates' → 'communication.manage_templates'
// itd. — pełne mapowanie w migracji
```

## Role-granting przez formularz (UI-facing, nie internal `assignRole()`)

`assignRole()`/`syncRoles()` z tej reguły to zaufane, wewnętrzne call sites (hardcoded nazwa roli).
Gdy rolę wybiera UŻYTKOWNIK przez formularz (`UserResource`'s `roles` Select, `RoleResource`'s
`name` field) — to jest system boundary i wymaga osobnej walidacji, nie tylko `firstOrCreate`.
Patrz `.claude/rules/filament-resources.md` (sekcja "Role Escalation Guard") i
`app/docs/security/patterns/role-escalation-guard.md`.

## Kiedy seedować role na dev

```bash
# Po migrate:fresh ZAWSZE dodaj --seed
docker compose exec -T app php artisan migrate:fresh --seed

# Lub ręcznie seeduj role
docker compose exec -T app php artisan db:seed --class=RolePermissionSeeder
```
