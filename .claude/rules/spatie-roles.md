---
paths:
  - "app/Actions/**"
  - "app/Listeners/**"
  - "app/Http/Controllers/Auth/**"
  - "app/Http/Middleware/**"
  - "app/Providers/HorizonServiceProvider.php"
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
- Testy przechodzą bo `TestReferenceDataSeeder` (wpięty jako `Tests\TestCase::$seeder`,
  patrz `tests.md` -> "Reference data seeding") seeduje role raz na proces testowy
- Produkcja/fresh dev DB nie ma ról → `assignRole()` rzuca `RoleDoesNotExist`

## Incident 2026-03-14

**Problem:** Rejestracja biznesowa → `CreateOrganizationWithOwner` → `$user->assignRole('admin')` → `RoleDoesNotExist: There is no role named 'admin' for guard 'web'`

**Root cause:** `migrate:fresh` bez `--seed` → tabela `roles` pusta. Testy przechodzą, bo zestaw
testowy seeduje role sam (obecnie: `TestReferenceDataSeeder`, raz na proces).

**Fix:** `Role::firstOrCreate()` przed każdym `assignRole()`.

**Zapobieganie:** Ta reguła. Każdy nowy `assignRole()` MUSI mieć `firstOrCreate` guard.

## Istniejące role w systemie

| Rola | Guard | Użycie |
|------|-------|--------|
| `admin` | web | Właściciel organizacji (onboarding) |
| `customer` | web | Klient (rejestracja customer) |
| `staff` | web | Pracownik (dodawany przez admina) |
| `super-admin` | web | Super-admin platformy — **na dedykowanym stacku tenanta NIE istnieje** (patrz niżej) |

## Bramka na `super-admin` to martwa bramka na stacku tenanta

**Problem:** Czterech funkcji klienta nie dało się użyć na dedykowanym stacku: tryb konserwacji (admin
włączał, `AdminMaintenanceCheck` wpuszczał tylko `super-admin` → klient zamykał sobie panel, wracała go
tylko CLI), wniosek o zamknięcie konta (mail do `User::role('super-admin')->get()` → pusta kolekcja,
flaga ustawiona, UI pokazywało „Wniosek wysłany"), `/horizon` (403 dla wszystkich), harmonogram
pracownika (zasoby tylko `admin|super-admin`, choć seeder daje `staff` uprawnienia do dostępności).

**Przyczyna:** Stack tenanta ma własną bazę i **zero** użytkowników `super-admin` — nigdy ich nie będzie
(`/platform` w ogóle nie jest rejestrowany, `bootstrap/providers.php`). Kod pisany pod współdzielony stack
traktował `super-admin` jako „operatora, który zawsze jest pod ręką". Każdy `hasRole('super-admin')` jako
JEDYNA droga do czegoś, co klient ma wolno zrobić albo uruchomić, jest tam ścianą. Test na współdzielonym
stacku jej nie widzi: jest zielony, bo super-admin tam istnieje.

**Rozwiązanie:** Funkcja, którą może uruchomić tenant `admin`, musi go też wpuścić z powrotem — jawnie, nie jako
skutek uboczny awansu roli — ale **zależnie od modelu wdrożenia**: stack tenanta (`config('app.tenant_slug')`) →
`admin` + `super-admin`; stack współdzielony → `super-admin` jak dotąd (flaga konserwacji to jeden globalny klucz,
admin jednego tenanta nie może nadpisać okna konserwacji operatora). Tak działają `AdminMaintenanceCheck` i bramka Horizona. Odbiorca maila operatora to
ADRES (`getGlobal('account.closure_request_email')`), nigdy rola. Pusty odbiorca = `Log::error` + komunikat
błędu + brak zmiany stanu, nie „sukces". Bramka zależna od stacku czyta `config('app.tenant_slug')`
(`HorizonServiceProvider`).

**Zapobieganie:**
- Pisząc `hasRole('super-admin')` zapytaj: *czy to jedyna droga i czy na stacku tenanta ktoś ją ma?* Jeśli
  nie — dołóż `admin` albo zdecyduj, że to funkcja operatora (CLI), i nie wystawiaj jej w UI klienta.
- `Notification::send(User::role('super-admin')->get(), …)` jest zabronione: pusta kolekcja = brak błędu,
  brak maila. Użyj `Notification::route('mail', $adres)` i sprawdź adres przed zmianą stanu.
- Test na wzorzec „stack przypięty": `putenv('TENANT_SLUG=…')` przed `parent::setUp()`, `tearDown()` je zdejmuje
  (`tests/Feature/Horizon/HorizonGatePinnedStackTest.php`, `Auth/SuperAdminPasswordResetPinnedStackTest.php`).
  Po stronie danych: nie zakładaj, że w tabeli `users` jest ktokolwiek z `super-admin`
  (`ClosureRequestRecipientTest::setUp()` to asercjonuje).
- Zasób Filament dla pracownika filtruje po `user_id`, nie po roli widoku: `App\Filament\Traits\ScopesStaffToOwnRecords`
  (admin widzi wszystko, `staff` tylko własne; `user_id` przypinany po stronie serwera w `Create*/Edit*`).

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
