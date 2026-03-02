# Plan: Testy Automatyczne E2E dla Front Office

## Status: FAZA 1 - Analiza i Research (In Progress)

---

## 1. Cel Projektu

Zbudować system testów automatycznych E2E dla front office (strona klienta) aplikacji Paradocks, aby osiągnąć **100% lub blisko 100%** zgodność testów ze stanem faktycznym aplikacji.

**Scope:** Front office only (NIE admin panel Filament)

---

## 2. Analiza Projektu (Completed)

### 2.1 Stack Technologiczny

| Warstwa | Technologie |
|---------|-------------|
| **Backend** | Laravel 12, PHP 8.2+, MySQL 8.0 |
| **Frontend** | Vanilla JS (1620 lines booking-wizard.js), Alpine.js 3.14, Tailwind CSS 4.0 |
| **Admin** | Filament v4.2.3 (out of scope) |
| **Build** | Vite 7+, npm |
| **Queue** | Redis + Horizon |
| **Docker** | 9 services |

### 2.2 Istniejąca Infrastruktura Testów

| Element | Status | Szczegóły |
|---------|--------|-----------|
| PHPUnit | ✅ | 16 testów (8 Feature, 1 Unit) |
| Factories | ✅ | 5 fabryk (User, Service, Appointment, ServiceArea, VehicleType) |
| CI/CD | ✅ | GitHub Actions (Pint + PHPUnit) |
| Laravel Dusk | ❌ | Nie zainstalowany |
| Cypress/Playwright | ❌ | Nie zainstalowany |
| Code Coverage | ❌ | Brak raportowania |

### 2.3 Kluczowe Flow do Testowania

| # | Flow | Złożoność | Krytyczność |
|---|------|-----------|-------------|
| 1 | **Booking Wizard (5 kroków)** | WYSOKA | KRYTYCZNA |
| 2 | Registration + Email verification | ŚREDNIA | WYSOKA |
| 3 | Login + Session | NISKA | WYSOKA |
| 4 | Profile Management (5 subpages) | ŚREDNIA | ŚREDNIA |
| 5 | Appointments List + Cancel | NISKA | ŚREDNIA |
| 6 | Service Pages browsing | NISKA | NISKA |
| 7 | CMS Pages | NISKA | NISKA |

### 2.4 Zewnętrzne Integracje (wymagają mockowania)

| Serwis | Użycie | Mockowanie |
|--------|--------|------------|
| Google Maps API | Autocomplete + walidacja lokalizacji | Wymaga mock |
| SMTP (Gmail) | Email notifications | Mailpit w dev |
| SMSAPI.PL | SMS notifications | Mock w testach |
| Redis | Cache + Queue | Array driver w testach |

### 2.5 Kluczowe Pliki

**Controllers:**
- `app/Http/Controllers/BookingController.php` - 653 lines, 5-step wizard
- `app/Http/Controllers/ProfileController.php` - 235 lines
- `app/Http/Controllers/AppointmentController.php`
- `app/Http/Controllers/Auth/*.php`

**Views:**
- `resources/views/booking-wizard/` - 5 step templates
- `resources/views/profile/` - 5 subpages
- `resources/views/auth/` - Auth forms

**JavaScript:**
- `resources/js/booking-wizard.js` - 1620 lines, complete state machine

**Istniejące Testy (Reference):**
- `tests/Feature/BookingConfirmationSecurityTest.php` - Best reference
- `tests/Feature/ServiceAreaValidationTest.php` - API testing pattern

---

## 3. Research Narzędzi E2E (Completed)

### 3.1 Porównanie Narzędzi

| Narzędzie | Laravel 12 | Cross-Browser | Język | Parallel | Google Maps Mock | Rekomendacja |
|-----------|------------|---------------|-------|----------|------------------|--------------|
| **Pest v4 Browser** | ✅ Native | ✅ All | PHP | ✅ Sharding | ✅ MSW | ⭐ PRIMARY |
| Laravel Dusk | ✅ Native | ❌ Chrome only | PHP | ⚠️ Limited | ⚠️ Difficult | Fallback |
| Playwright | ⚠️ HTTP | ✅ All | JS | ✅ Workers | ✅ Native | Cross-browser |
| Cypress | ⚠️ HTTP | ⚠️ No Safari | JS | 💰 Paid | ✅ intercept | Not recommended |

### 3.2 Rekomendacja: Pest v4 Browser Testing

**Dlaczego Pest v4:**
- ✅ Natywna integracja z Laravel 12 (RefreshDatabase, factories)
- ✅ Znajoma składnia PHP (ten sam Pest co unit testy)
- ✅ Test sharding w CI (`--shard=1/4`)
- ✅ Oparte na Playwright (pełne możliwości)
- ✅ Alpine.js support: `waitUntil('Alpine.store("wizard").step === 2')`
- ✅ Darmowe i open-source

**Wymagania:**
- ⚠️ **PHP 8.3+** (upgrade z 8.2)
- npm + Playwright browsers

### 3.3 Google Maps Mocking: MSW (Mock Service Worker)

```javascript
// Mockowanie Places Autocomplete API
http.get('https://maps.googleapis.com/maps/api/place/autocomplete/json', () => {
    return HttpResponse.json({
        predictions: [{ description: 'Marszałkowska 1, Warszawa' }],
        status: 'OK'
    });
});
```

### 3.4 Visual Regression Testing

| Narzędzie | Koszt | Rekomendacja |
|-----------|-------|--------------|
| BackstopJS | $0/mies | ⭐ MVP (free) |
| Percy | $349/mies | Growth phase |
| Playwright Screenshots | $0 | Quick start |

### 3.5 CI/CD: GitHub Actions z Test Sharding

- 4 równoległe maszyny (sharding)
- MySQL 8.0 + Redis services
- Artifacts: screenshots, reports

---

## 4. Rekomendowany Stack

| Warstwa | Narzędzie | Koszt |
|---------|-----------|-------|
| **E2E Testing** | Pest v4 Browser Testing | $0 |
| **Google Maps Mock** | MSW (Mock Service Worker) | $0 |
| **Visual Regression** | BackstopJS (MVP) | $0 |
| **CI/CD** | GitHub Actions (sharded) | $0 |
| **TOTAL** | | **$0/mies** |

---

## 5. Plan Implementacji (Draft)

### Faza 1: Foundation (Tydzień 1-2)

```bash
# 1. Upgrade PHP do 8.3
# 2. Install Pest v4 Browser Testing
composer require pestphp/pest-plugin-browser --dev
npm install playwright@latest
npx playwright install

# 3. Create test structure
tests/Browser/
├── BookingWizardTest.php
├── AuthenticationTest.php
├── ProfileManagementTest.php
└── Pages/
    ├── BookingWizardStep1.php
    ├── BookingWizardStep2.php
    └── ...
```

### Faza 2: Core Flows (Tydzień 3-4)

**Testy do napisania:**
1. ✅ Booking Wizard (5 kroków) - KRYTYCZNE
2. ✅ Registration + Login
3. ✅ Profile Management (5 subpages)
4. ✅ Appointments List + Cancel
5. ✅ Service Pages browsing

### Faza 3: Google Maps Mocking (Tydzień 5)

- Setup MSW handlers
- Mock Places Autocomplete
- Mock Place Details
- Integration z Pest v4

### Faza 4: Visual Regression (Tydzień 6)

- BackstopJS setup
- Baseline screenshots (10 components)
- CI integration

### Faza 5: CI Optimization (Tydzień 7)

- Test sharding (4 shards)
- DatabaseTruncation strategy
- Artifact uploads

---

## 6. Przykładowy Test Booking Wizard

```php
test('customer can complete booking wizard', function () {
    $service = Service::factory()->create(['name' => 'Exterior Detailing']);

    $this->browse(function (Browser $browser) use ($service) {
        $browser->visit('/booking/create')
            // Step 1: Service selection
            ->click("@service-{$service->id}")
            ->click('@next-step')

            // Step 2: Date/time
            ->type('@date', '2026-02-15')
            ->waitFor('@time-slot-14:00')
            ->click('@time-slot-14:00')
            ->click('@next-step')

            // Step 3: Location (Google Maps - mocked)
            ->type('@autocomplete-input', 'Marszałkowska 1, Warszawa')
            ->waitFor('.pac-container')
            ->keys('@autocomplete-input', ['{arrow_down}', '{enter}'])

            // Step 4: Vehicle
            ->click('@vehicle-type-medium')
            ->select('@brand-select', 'Toyota')
            ->select('@model-select', 'Corolla')
            ->click('@next-step')

            // Step 5: Confirm
            ->check('@terms')
            ->click('@submit')
            ->waitForText('Rezerwacja potwierdzona')
            ->assertPathIs('/my-appointments');
    });
});
```

---

## 7. Pliki do Utworzenia/Modyfikacji

| Plik | Akcja | Opis |
|------|-------|------|
| `composer.json` | Modify | Add pestphp/pest-plugin-browser |
| `package.json` | Modify | Add playwright |
| `tests/Browser/` | Create | Folder na browser testy |
| `tests/Browser/BookingWizardTest.php` | Create | Main booking flow test |
| `tests/Browser/Pages/` | Create | Page Objects |
| `.github/workflows/e2e.yml` | Create | E2E CI workflow |
| `backstop.json` | Create | Visual regression config |

---

## 8. Decyzje Użytkownika

| Pytanie | Decyzja |
|---------|---------|
| PHP Version | ✅ Upgrade do PHP 8.3 |
| Cross-Browser | ✅ Tylko Chrome/Chromium |
| Visual Regression | ❌ Nie teraz (później) |
| Scope | ✅ Wszystkie flow front office |

---

## 9. FINALNY PLAN IMPLEMENTACJI

### Faza 1: Foundation (Dzień 1-2)

**1.1 Upgrade PHP do 8.3**
```bash
# Docker: zmiana w Dockerfile
FROM php:8.3-fpm

# Lokalne: update composer.json
"php": "^8.3"
```

**1.2 Instalacja Pest v4 Browser Testing**
```bash
composer require pestphp/pest-plugin-browser --dev
npm install playwright@latest
npx playwright install chromium  # tylko Chrome
```

**1.3 Struktura testów**
```
tests/
├── Browser/                        # Pest v4 browser testy
│   ├── BookingWizardTest.php
│   ├── AuthenticationTest.php
│   ├── ProfileManagementTest.php
│   ├── AppointmentsTest.php
│   ├── ServicePagesTest.php
│   ├── CmsPagesTest.php
│   └── Pages/                      # Page Objects
│       ├── BookingWizardStep1.php
│       ├── BookingWizardStep2.php
│       ├── BookingWizardStep3.php
│       ├── BookingWizardStep4.php
│       ├── BookingWizardStep5.php
│       ├── LoginPage.php
│       ├── RegisterPage.php
│       └── ProfilePage.php
└── mocks/
    └── google-maps-handlers.js     # MSW handlers
```

---

### Faza 2: Booking Wizard Tests (Dzień 3-5)

**Testy do napisania (KRYTYCZNE):**

| Test | Scenariusz | Priorytet |
|------|------------|-----------|
| `test_customer_completes_full_booking` | Happy path 5 kroków | P0 |
| `test_step_navigation_back_forward` | Nawigacja między krokami | P0 |
| `test_session_persistence_on_refresh` | Dane nie giną po refresh | P0 |
| `test_service_selection_updates_summary` | Wybór usługi → summary | P1 |
| `test_date_picker_shows_available_dates` | Kalendarz + dostępność | P1 |
| `test_time_slots_load_for_selected_date` | AJAX load slotów | P1 |
| `test_google_maps_autocomplete` | Mocked autocomplete | P1 |
| `test_vehicle_selection_cascade` | Brand → Model → Year | P1 |
| `test_validation_errors_display` | Błędy walidacji | P2 |
| `test_booking_confirmation_email` | Email po rezerwacji | P2 |

---

### Faza 3: Authentication Tests (Dzień 6-7)

| Test | Scenariusz |
|------|------------|
| `test_guest_can_register` | Rejestracja nowego użytkownika |
| `test_user_can_login` | Logowanie z valid credentials |
| `test_login_fails_with_invalid_credentials` | Błędne dane |
| `test_user_can_logout` | Wylogowanie |
| `test_password_reset_flow` | Reset hasła (email → link → nowe hasło) |
| `test_email_verification_required` | Weryfikacja emaila |

---

### Faza 4: Profile Management Tests (Dzień 8-10)

| Test | Scenariusz |
|------|------------|
| `test_user_can_view_profile_dashboard` | Dashboard overview |
| `test_user_can_update_personal_info` | Edycja imienia, telefonu |
| `test_user_can_add_vehicle` | Dodanie pojazdu |
| `test_user_can_update_vehicle` | Edycja pojazdu |
| `test_user_can_delete_vehicle` | Usunięcie pojazdu |
| `test_user_can_add_address` | Dodanie adresu (Google Maps) |
| `test_user_can_update_notifications` | Preferencje email/SMS |
| `test_user_can_change_password` | Zmiana hasła |
| `test_user_can_request_account_deletion` | Usunięcie konta |

---

### Faza 5: Appointments & Services Tests (Dzień 11-12)

| Test | Scenariusz |
|------|------------|
| `test_user_sees_appointments_list` | Lista rezerwacji |
| `test_user_can_cancel_appointment` | Anulowanie rezerwacji |
| `test_cancelled_appointment_shows_status` | Status anulowania |
| `test_services_page_displays_all_services` | Lista usług |
| `test_service_detail_page_loads` | Szczegóły usługi |
| `test_service_page_has_book_now_button` | CTA "Zarezerwuj" |

---

### Faza 6: CMS Pages Tests (Dzień 13)

| Test | Scenariusz |
|------|------------|
| `test_cms_page_loads` | Strona CMS ładuje się |
| `test_homepage_displays_hero` | Hero section na home |
| `test_post_page_loads` | Blog post ładuje się |
| `test_promotion_page_loads` | Promocja ładuje się |

---

### Faza 7: Google Maps Mocking Setup (Dzień 14)

**MSW Handlers:**
```javascript
// tests/mocks/google-maps-handlers.js
export const googleMapsHandlers = [
    http.get('**/maps/api/place/autocomplete/*', () => {
        return HttpResponse.json({
            predictions: [
                { description: 'Marszałkowska 1, Warszawa', place_id: 'mock_id' }
            ],
            status: 'OK'
        });
    }),
    http.get('**/maps/api/place/details/*', () => {
        return HttpResponse.json({
            result: {
                geometry: { location: { lat: 52.2297, lng: 21.0122 } },
                address_components: [/* mock */]
            }
        });
    })
];
```

---

### Faza 8: CI/CD Integration (Dzień 15)

**.github/workflows/e2e.yml:**
```yaml
name: E2E Tests

on:
  push:
    branches: [develop, main]
  pull_request:
    branches: [develop, main]

jobs:
  browser-tests:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        shard: [1, 2, 3, 4]

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: paradocks_test
          MYSQL_ROOT_PASSWORD: password
        ports: ['3306:3306']
      redis:
        image: redis:7-alpine
        ports: ['6379:6379']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3
          extensions: mbstring, pdo_mysql, redis

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: 20

      - name: Install Dependencies
        run: |
          composer install
          npm ci
          npx playwright install chromium

      - name: Build Assets
        run: npm run build

      - name: Run E2E Tests (Sharded)
        run: php artisan test --parallel --shard=${{ matrix.shard }}/4

      - name: Upload Screenshots
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: screenshots-${{ matrix.shard }}
          path: tests/Browser/Screenshots/
```

---

## 10. Pliki do Utworzenia/Modyfikacji

| Plik | Akcja | Priorytet |
|------|-------|-----------|
| `docker/php/Dockerfile` | Modify | P0 - PHP 8.3 |
| `composer.json` | Modify | P0 - php ^8.3, pest-plugin-browser |
| `package.json` | Modify | P0 - playwright |
| `tests/Browser/BookingWizardTest.php` | Create | P0 |
| `tests/Browser/AuthenticationTest.php` | Create | P1 |
| `tests/Browser/ProfileManagementTest.php` | Create | P1 |
| `tests/Browser/AppointmentsTest.php` | Create | P1 |
| `tests/Browser/ServicePagesTest.php` | Create | P2 |
| `tests/Browser/CmsPagesTest.php` | Create | P2 |
| `tests/Browser/Pages/*.php` | Create | P1 - Page Objects |
| `tests/mocks/google-maps-handlers.js` | Create | P1 |
| `.github/workflows/e2e.yml` | Create | P1 |

---

## 11. Metryki Sukcesu

| Metryka | Cel | Mierzenie |
|---------|-----|-----------|
| **Test Coverage** | 100% critical paths | Booking, Auth, Profile covered |
| **Test Execution Time** | < 5 min (sharded) | CI pipeline time |
| **Flakiness Rate** | < 5% | Retries needed |
| **Browser Compatibility** | Chrome 100% | All tests pass |

---

## 12. Timeline

| Dzień | Faza | Deliverable |
|-------|------|-------------|
| 1-2 | Foundation | PHP 8.3 + Pest v4 zainstalowane |
| 3-5 | Booking Wizard | 10 testów booking flow |
| 6-7 | Authentication | 6 testów auth flow |
| 8-10 | Profile | 9 testów profile management |
| 11-12 | Appointments & Services | 6 testów |
| 13 | CMS | 4 testy CMS pages |
| 14 | Google Maps | MSW mocking setup |
| 15 | CI/CD | GitHub Actions workflow |

**Total: 15 dni roboczych (~3 tygodnie)**

---

## 13. Ryzyka i Mitygacja

| Ryzyko | Prawdopodobieństwo | Mitygacja |
|--------|-------------------|-----------|
| PHP 8.3 breaking changes | Niskie | Testy jednostkowe przed upgrade |
| Google Maps mocking complexity | Średnie | Fallback: skip location step tests |
| Flaky tests (timing) | Średnie | Explicit waits, retry logic |
| CI timeout | Niskie | Test sharding (4 machines) |
