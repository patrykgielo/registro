# Onboarding & Business Registration

## Overview

3-krokowy wizard do zakładania konta firmowego. Tworzy User + Organization + seed data na podstawie wybranej branży.

## Flow

```
/register (step 1)           ← guest
  → Nazwa firmy, slug, branża (Industry enum)
  → POST validates: org_name, slug, industry

/register/step/2             ← guest
  → Dane właściciela: imię, nazwisko, email, hasło, terms
  → POST creates: User (admin role) + Organization (14-day trial)
  → Seeduje: settings + feature flags + vertical data
  → Loguje użytkownika

/register/step/3             ← auth (optional)
  → Personalizacja: miasto, adres
  → Per branża: toggle mobile_service, service_radius_km
  → "Pomiń" → welcome

/register/welcome            ← auth
  → Gratulacje + link do panelu admina
  → Auto-redirect po 5s
```

## Industry Enum

`app/Enums/Industry.php` — 3 cases:

| Case | Label | booking_type | Default features |
|------|-------|-------------|------------------|
| `equipment_rental` | Wypożyczalnia sprzętu | `item_rental` | all false |
| `auto_detailing` | Auto detailing | `time_slot` | vehicles, mobile_service, service_area = true |
| `general_services` | Inna działalność | `time_slot` | all false |

**Metody:** `label()`, `icon()`, `description()`, `bookingType()`, `defaultFeatures()`, `terminology()`, `seederClass()`

## Vertical Seeders

Interface: `app/Actions/Onboarding/Seeders/VerticalSeeder.php`

### SeedEquipmentRental
- 7 kategorii RentalCategory + 13 Service (service_type=item_rental)
- Tiered pricing: `price_per_day` + `price_per_day_long` + `price_threshold_days`
- Specs w `specifications` JSON (hybrid: `specs` + `custom_specs`)
- Dane z researchu PL rynku (Mińsk Maz., Sed-Bruk, Rentbud, Ramirent)

### SeedAutoDetailing
- 8 usług Service z `metadata` JSON
- `prices_by_size`: {A, B, C, D} — per vehicle category
- `durations_by_size`: {A, B, C, D}
- `available_for_mobile`: bool

### SeedGeneralServices
- 1 placeholder usługa ("Przykładowa usługa")

## Key Files

| Plik | Rola |
|------|------|
| `app/Enums/Industry.php` | Enum branż |
| `app/Actions/Onboarding/OnboardingData.php` | Value object (readonly) |
| `app/Actions/Onboarding/CreateOrganizationWithOwner.php` | Transaction: User + Org + Seed |
| `app/Actions/Onboarding/SeedOrganizationDefaults.php` | Settings + features + vertical seeder |
| `app/Actions/Onboarding/Seeders/VerticalSeeder.php` | Interface |
| `app/Actions/Onboarding/Seeders/Seed*.php` | 3 implementacje |
| `app/Http/Controllers/Auth/BusinessRegisterController.php` | Controller (7 metod) |
| `resources/views/auth/register-business-step1.blade.php` | Firma + branża |
| `resources/views/auth/register-business-step2.blade.php` | Dane właściciela |
| `resources/views/onboarding/step3.blade.php` | Personalizacja |
| `resources/views/onboarding/welcome.blade.php` | Gratulacje |

## Dodawanie nowej branży

1. Dodaj case do `Industry` enum z wszystkimi metodami
2. Stwórz seeder implementujący `VerticalSeeder` w `app/Actions/Onboarding/Seeders/`
3. Zwróć FQCN seedera w `Industry::seederClass()`
4. Dodaj test w `tests/Unit/Actions/VerticalSeederTest.php`
5. Gotowe — `SeedOrganizationDefaults` automatycznie wywołuje seeder

## DNS / Subdomain Setup

After onboarding creates a new Organization with slug `demo`, the tenant is accessible at `demo.registro.local`.

### Local Development Requirements

1. **DNS resolution** — `*.registro.local` must resolve to `127.0.0.1`:
   ```bash
   # /etc/hosts (per tenant)
   echo "127.0.0.1 demo.registro.local" | sudo tee -a /etc/hosts

   # dnsmasq (wildcard, recommended)
   echo "address=/registro.local/127.0.0.1" | sudo tee /etc/dnsmasq.d/registro.conf
   sudo systemctl restart dnsmasq
   ```

2. **Nginx** — wildcard `server_name *.registro.local` in the Docker nginx config.

3. **SSL** — self-signed wildcard cert for `*.registro.local`. Browsers show ERR_CERT_AUTHORITY_INVALID until the cert is trusted in OS/browser keystore.

### Known Issue: New Tenant Not Accessible

If a freshly created tenant's subdomain returns DNS error, the slug was not added to `/etc/hosts` (when not using dnsmasq). Either add it manually or switch to dnsmasq wildcard resolution.

---

## Backward Compatibility

- `Organization.industry` jest nullable — istniejące orgi bez industry działają
- `hasFeature()` ma fallback: industry defaults → booking_type defaults
- `term()` zwraca domyślne terminy gdy industry=null
