---
paths:
  - "app/Actions/Onboarding/**"
  - "app/Http/Controllers/Auth/BusinessRegisterController.php"
  - "resources/views/auth/register-business-*"
  - "resources/views/onboarding/**"
  - "app/Enums/Industry.php"
---

# Onboarding & Business Registration Rules

## Flow — 3 kroki

```
Step 1: Firma + branża (guest)
  → POST validates: org_name, slug, industry (NOT booking_type!)
  → Session: business_register.step1

Step 2: Dane właściciela (guest)
  → POST validates: first_name, last_name, email, password, terms
  → Creates: User + Organization + seed data (w transakcji)
  → Logs in user
  → Session: business_register.organization_id
  → Redirect: step3 (NIE welcome!)

Step 3: Personalizacja (auth, optional)
  → City, address, mobile_service toggle, service_radius_km
  → "Pomiń" → welcome (skip link)
  → "Zapisz" → welcome

Welcome: Auto-redirect do panelu admina (5s)
```

## Kluczowe wzorce

### Industry zamiast booking_type w onboardingu

```php
// ✅ PRAWIDŁOWO — waliduj industry
$request->validate(['industry' => ['required', new Enum(Industry::class)]]);

// ❌ ŹLE — booking_type jest DERIVED, nie user-facing
$request->validate(['booking_type' => ['required', 'in:time_slot,item_rental']]);
```

Industry automatycznie ustawia booking_type via `Industry::bookingType()`.

### OnboardingData — value object

```php
new OnboardingData(
    orgName: $step1['org_name'],
    slug: $step1['slug'],
    bookingType: $industry->bookingType(),  // derived!
    industry: $step1['industry'],           // string value
    firstName: ...,
    lastName: ...,
    email: ...,
    password: ...,
);
```

### Vertical Seeders — dodawanie nowej branży

1. Dodaj case do `app/Enums/Industry.php`
2. Implementuj `VerticalSeeder` interface w `app/Actions/Onboarding/Seeders/`
3. Dodaj `seederClass()` return w enum
4. Gotowe — `SeedOrganizationDefaults` automatycznie wywołuje seeder

```php
interface VerticalSeeder {
    public function seed(Organization $organization): void;
}
```

### Seeder scope bypass

Seedery MUSZĄ używać `withoutGlobalScope('organization')`:

```php
// ✅ PRAWIDŁOWO
RentalItem::withoutGlobalScope('organization')->create([
    'organization_id' => $organization->id,
    ...
]);

// ❌ ŹLE — BelongsToOrganization trait nadpisze organization_id
RentalItem::create([...]);
```

## Session keys

| Key | Gdy | Zawartość |
|-----|-----|-----------|
| `business_register.step1` | Po step1, przed step2 | `org_name`, `slug`, `industry` |
| `business_register.organization_id` | Po step2 | int (org ID) |

## Walidacja slug

- AJAX check: `GET /register/check-slug?slug=xxx`
- Server-side: `ValidOrganizationSlug` rule + `unique:organizations,slug`
- Race condition guard w `storeStep2()`: re-check + regenerate if taken
- 36 reserved slugs (admin, api, www, registro, etc.)

## Assets

- **`npm run build`** — buduje assety (jednorazowo, statyczne pliki w `public/build/`)
- **`npm run dev`** — TYLKO do hot-reload podczas aktywnego developmentu CSS/JS
- NIGDY nie sugeruj `npm run dev` jako rozwiązania problemu z assetami

## Seed data — referencja

| Industry | Seed | Ilość |
|----------|------|-------|
| equipment_rental | SeedEquipmentRental | 7 kategorii + 13 itemów |
| auto_detailing | SeedAutoDetailing | 8 usług z metadata |
| general_services | SeedGeneralServices | 1 placeholder usługa |
