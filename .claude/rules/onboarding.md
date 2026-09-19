---
paths:
  - "app/Actions/Onboarding/**"
  - "app/Console/Commands/ProvisionTenantCommand.php"
  - "app/Enums/Industry.php"
---

# Onboarding & Tenant Provisioning Rules

## No public self-serve registration

There is no public "sign up" flow. The product is sold via contract; a new organization is
provisioned by an operator running:

```
php artisan registro:tenant-provision --slug=acme --name="Acme Sp. z o.o." \
    --industry=equipment_rental --owner-email=owner@acme.pl --owner-name="Jan Kowalski" [--no-email]
```

Full mechanics (idempotency, global-seeder gating, `TenantRegistered` dispatch, singleton lock on
dedicated tenant-stack containers): `app/docs/features/tenant-stack-provisioning.md`. A prior
2-step public wizard (`BusinessRegisterController`) was removed entirely — do not re-add a public
registration route without re-opening that decision; the archived design is at
`docs/archive/features/tenant-provisioning-wizard.md` for historical reference only.

- **Owner has no password.** Access is via `User::initiatePasswordSetup()` (same mechanism
  Filament's `UserResource` uses for admin-created staff) — the command prints the setup link to
  stdout, always, regardless of whether the `TenantRegistered` mail dispatch succeeds.
- **Idempotent by slug.** Re-running the command against an existing organization finds it via
  `firstOrCreate` rather than duplicating it, and does not re-dispatch `TenantRegistered`.

## Industry zamiast booking_type

```php
// ✅ PRAWIDŁOWO — waliduj industry
$request->validate(['industry' => ['required', new Enum(Industry::class)]]);

// ❌ ŹLE — booking_type jest DERIVED, nie user-facing
$request->validate(['booking_type' => ['required', 'in:time_slot,item_rental']]);
```

Industry automatycznie ustawia booking_type via `Industry::bookingType()`.

## Vertical Seeders — dodawanie nowej branży

**KRYTYCZNE: Nowy tenant startuje z PUSTYM katalogiem.** `SeedOrganizationDefaults::execute()` seeduje tylko settings i feature flags — nigdy produkty/usługi. Vertical seed to operacja opt-in, wyłącznie ręczna.

1. Dodaj case do `app/Enums/Industry.php`
2. Implementuj `VerticalSeeder` interface w `app/Actions/Onboarding/Seeders/`
3. Dodaj `seederClass()` return w enum
4. Seeder NIE jest wywoływany automatycznie. Ładowanie ręczne:
   `php artisan onboarding:seed-vertical {id_lub_slug} [--industry=...] [--force]`

```php
interface VerticalSeeder {
    public function seed(Organization $organization): void;
}
```

## Seeder scope bypass

Seedery MUSZĄ używać `withoutGlobalScope('organization')`:

```php
// ✅ PRAWIDŁOWO
Service::withoutGlobalScope('organization')->create([
    'organization_id' => $organization->id,
    'service_type' => ServiceType::ItemRental,
    ...
]);

// ❌ ŹLE — BelongsToOrganization trait nadpisze organization_id
Service::create([...]);
```

## Moduły — automatyczna inicjalizacja (Phase 6)

Nowa organizacja NIE wymaga ręcznego ustawiania modułów. System hasModule() automatycznie resolve'uje domyślne moduły z Industry:

```php
// EquipmentRental → ['rentals']
// AutoDetailing → ['services', 'bookings']
// GeneralServices → ['services', 'bookings']

// Seedery NIE piszą do settings.modules — priority chain to załatwia
$org->hasModule('services')  // true jeśli industry to umożliwia
```

Super-admin może nadpisać moduły w Platform panel (zapisuje do `settings.modules.*`).

## Provisioning zakłada (i LECZY) pierwszy oddział (ClickUp 123k99cvc53, 2026-09-19)

`ProvisionTenantOrganization::execute()` woła `SeedOrganizationDefaults::ensurePrimaryLocation($org)`
**bezwarunkowo, na KAŻDYM wywołaniu** — nowy tenant i ponowne uruchomienie na już istniejącym —
tworząc jeden `Location` ("Siedziba główna", `is_active=true`, bez adresu — tenant jeszcze nie ma
`contact.*` w ustawieniach). Bez tego nowy tenant miał ZERO lokalizacji i `ServiceResource`'s pole
„Ilość w magazynie" ciche wyłączało się (`RouteQuantityFieldToPrimaryLocationStock::
tenantHasExactlyOneActiveLocation() === 0`) — każdy nowy produkt zapisywał `quantity_total = NULL`,
bez błędu. Idempotentne przez `Location::exists()` (dowolny status, nie tylko `is_active`) —
organizacja z choćby jedną lokalizacją, nawet nieaktywną, nigdy nie dostaje drugiej.

**Pułapka naprawiona w code review tego samego dnia:** pierwsza wersja tej poprawki wołała
`ensurePrimaryLocation()` z WEWNĄTRZ `SeedOrganizationDefaults::execute()`, którą
`ProvisionTenantOrganization` woła TYLKO gdy `$orgWasCreated` — więc ponowne uruchomienie
`registro:tenant-provision --slug=<istniejący>` na tenancie sprzed tej poprawki (zero
lokalizacji) NIGDY go nie leczyło, mimo że to dokładnie ścieżka naprawy, po którą sięgnąłby
operator. `ensurePrimaryLocation()` jest teraz PUBLICZNA i wołana OSOBNO od `execute()` (który
zostaje `$orgWasCreated`-only — `seedSettings()` używa `updateOrCreate` i nadpisałby ustawienia,
które admin już zmienił, gdyby wołać go na istniejącym tenancie).

Vertical seedery (`SeedEquipmentRental::seed()`, `SeedVerticalDataCommand`) materializują teraz
`service_location_stocks` dla każdej utworzonej usługi (`SyncServiceLocationStock::forService()`)
— katalog tworzony bezpośrednio przez Eloquent, poza panelem, nigdy wcześniej tego nie robił.

## Seed data — referencja (opt-in manualny, nie auto)

Vertical seedery są dostępne, ale **NIE są wywoływane automatycznie** podczas provisioningu.
Uruchom ręcznie: `php artisan onboarding:seed-vertical {id_lub_slug}`

| Industry | Seeder | Ilość |
|----------|--------|-------|
| equipment_rental | SeedEquipmentRental | 7 kategorii + 13 itemów |
| auto_detailing | SeedAutoDetailing | 8 usług z metadata |
| general_services | SeedGeneralServices | 1 placeholder usługa |

Guard: jeśli org ma już usługi lub kategorie — komenda odmawia (użyj `--force` aby ominąć).

## Homepage + menu — osobny seeder od katalogu produktowego

`onboarding:seed-website {org} [--force] [--dry-run]` (`app/docs/features/tenant-website-seeder.md`)
tworzy stronę główną + minimalne menu (uniwersalne, industry-neutral). Celowo NIE jest częścią
`SeedEquipmentRental`/vertical seederów — strona to warstwa prezentacji wspólna dla branż, a
`SeedVerticalDataCommand::purgeExistingData()` kasuje tylko `Service`/`RentalCategory`, więc
dopisanie stron tam zostawiłoby sieroty przy `--force` tamtej komendy. Zapisuje
`cms.homepage_page_id` bezpośrednio przez `Setting::withoutGlobalScope()` (NIGDY
`SettingsManager::set()` w konsoli — patrz PUŁAPKA 1 w dokumencie funkcji).
