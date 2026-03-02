# [WYCENA] System Rezerwacji Wielu Usług — Główna usługa + Addony

**Data:** 2026-02-02
**Status:** Wycena wewnętrzna (technical) — do wyciągnięcia wersji client-facing
**ClickUp task:** https://app.clickup.com/t/86c78c4vj
**Stawka:** 160 PLN/h netto (196,80 PLN/h brutto, 23% VAT)
**Źródła:** 53+ źródeł rynkowych, analiza kodu (BookingController 745 linii, AppointmentService 607 linii, 12 migracji, 5 modeli)

---

## 1. Porównanie: STARE vs NOWE wymagania

Stara wycena (pre-2026-02) opisywała "zaznacz dowolne N usług checkboxami". Nowe wymagania (01.02.2026) zmieniają koncept na **główna usługa + upsell addonów**:

| Aspekt | STARE | NOWE (01.02.2026) |
|--------|-------|-------------------|
| Model wyboru | Checkboxy, dowolne N usług | **Główna usługa + małe addony (sugerowane)** |
| UI | Grid z checkboxami | **Main card + addon cards** + e-commerce 2-kolumnowy layout |
| Koszyk | Nie określony | **Persistent cart w header**, dodawanie/usuwanie pozycji |
| Layout | Nie określony | **Lewa kolumna: dane zamówienia, prawa: podsumowanie (usługi + cena brutto)** |
| Promocje | Brak | **"Perfumy za 1zł"** — specjalna pozycja promocyjna w koszyku |
| Panel klienta | Brak | **Nowy widok szczegółów zamówienia** ze wszystkimi usługami |
| GDPR | Brak | **Mechanizm aktualizacji/pobierania danych zamówień** |
| Admin upsell | Nie określony | **Admin konfiguruje sugerowane addony per główna usługa** |
| Czas | Suma czasów | Tak samo + **konfiguracja czasu usługi po stronie admin** |
| Etap 2 (upsell) | Osobny etap | **Wchłonięty w Etap 1** — upsell jest teraz core |

**Usunięte:** "Checkbox multi-select dowolnych usług" (zastąpione main+addon). Statystyki/raportowanie przesunięte do przyszłego scope.

---

## 2. Analiza obecnej architektury (z kodu)

### 2.1. Baza danych: 1 Appointment = 1 Service

`appointments.service_id` — NOT NULL FK z CASCADE DELETE (migration `2025_10_06_190503`, linia 16). **Brak pivot table** `appointment_services`. Brak `service_addons`.

### 2.2. BookingController (745 linii)

Session: `booking.service_id` (pojedyncza wartość). Cały wizard zakłada jedną usługę:
- Krok 1: Radio buttons, single select
- Krok 2: `getAvailableSlotsAcrossAllStaff(serviceId, date, service.duration_minutes)` — single duration
- Krok 5: Review z jedną usługą/ceną
- `confirm()`: Tworzy appointment z jednym `service_id`, liczy `end_time` z jednego `duration_minutes`

### 2.3. AppointmentService (607 linii)

Wszystkie 6 publicznych metod przyjmuje pojedyncze `int $serviceId`:
- `getAvailableSlotsAcrossAllStaff(int $serviceId, Carbon $date, int $serviceDurationMinutes)`
- `findBestAvailableStaff(int $serviceId, Carbon $dateTime, int $durationMinutes)`
- `getBulkAvailability(int $serviceId, Carbon $startDate, Carbon $endDate)`

### 2.4. Service Model

Brak relacji addon/upsell. Brak flagi `is_addon`. Brak pivota `recommended_services`. Tylko `appointments()` i `staff()`.

### 2.5. Notyfikacje i eventy

`AppointmentCreatedNotification` referuje `$appointment->service->name` (singular). 3 listenery w `AppServiceProvider` (linie 150-174) — email notifications. Kalendarz Filament i panel klienta — `$appointment->service->name`.

### 2.6. Filament Admin

`AppointmentResource`: single service select. `ServiceResource`: brak konfiguracji addonów.

---

## 3. Research rynkowy — kluczowe ustalenia (53+ źródeł)

### 3.1. Jak robią to platformy booking

| Platforma | Pattern | Detale |
|-----------|---------|--------|
| **Booksy** | Combo Services | Pakiety wielu usług w jeden bookable unit, sequential scheduling |
| **Acuity** | Native packages | Zestawy 5+ sesji, multi-service flow built-in |
| **Setmore** | Multi-service toggle | Feature aktywowany globalnie, klient dodaje usługi |
| **Urable** (car detailing) | Quoting + scheduling | Wbudowane upselle i addony per główna usługa |
| **OrbisX** (car detailing) | AI forms + upsell | Embedded upselle w formularzu, 100+ customizacji |

### 3.2. E-commerce layout (checkout)

- **Left-right layout**: Standard branżowy — 14% top 100 e-commerce (readymadeUI, webflow)
- **Sticky cart**: +7.9% zamówień desktop, +5.2% mobile (growthrock.co)
- **Cart drawer mobile**: Surge w popularności, mainstream od 2025 (vervaunt.com)
- **Mini-cart**: 3-4x wyższy click rate gdy cart + checkout razem (medium.com/ab-design)

### 3.3. Upsell conversion rates

- **Upsell average**: 20-25% konwersja (opensend.com, salesgenie.com)
- **Revenue boost**: 10-40% wzrost AOV z efektywnego upsellingu
- **Personalizacja**: Do 300% wzrostu przychodów (getcensus.com)
- **Pricing rule**: Cross-sell ≤50% ceny głównego produktu (shno.co)

### 3.4. Czas i bufor

- **Buffer time**: 10-15 min standard (bookingpressplugin.com, fluentbooking.com)
- **Sequential scheduling**: Suma czasów + bufory, złożoność O(n) (sciencedirect.com)
- **Duration display**: Klient widzi 60 min, system blokuje 70 min (60+10 buffer)

### 3.5. DB architektura

- **Standard**: Pivot table `appointment_services` z price/duration snapshots
- **Pola**: appointment_id, service_id, price_snapshot, duration_snapshot, sort_order
- **Laravel**: BelongsToMany z custom pivot model (laravel.com/docs/12.x)

### 3.6. GDPR/UE

- **B2C**: Ceny MUSZĄ zawierać VAT (brutto) — regulacja UE
- **Ukryte koszty**: #1 powód porzucania koszyka (Baymard Institute)
- **Transparentność**: Wszystkie opłaty widoczne od początku procesu

---

## 4. Decyzje architektoniczne

### D1: Pivot table `appointment_services` (REKOMENDOWANE)

```sql
appointment_services:
  id, appointment_id (FK), service_id (FK),
  is_primary (boolean), price_snapshot (decimal),
  duration_snapshot (integer), sort_order (integer),
  UNIQUE(appointment_id, service_id)
```

Istniejący `service_id` na `appointments` zostaje (primary service shortcut). Pivot przechowuje WSZYSTKIE usługi.

### D2: Pivot `service_addons` (admin-configurable upsell)

```sql
service_addons:
  id, service_id (FK->services), addon_service_id (FK->services),
  sort_order, is_active (boolean),
  UNIQUE(service_id, addon_service_id)
```

Self-referencing many-to-many na `services`. Admin konfiguruje które addony sugerować per główna usługa.

### D3: Koszyk — session-based

`session('booking.cart')` = `{ primary_service_id: 5, addon_service_ids: [2, 8] }`. DB-backed cart nie jest potrzebny (brak persistent carts, sesja wystarczy).

### D4: "Perfumy za 1zł" — promotional service

Dodaj `is_promotional` (boolean) + `promotional_price` (decimal) do `services`. Gdy addon jest promocyjny → użyj `promotional_price` zamiast `price`. Max 1 per appointment.

### D5: Kumulatywny czas + kompetencje staff

Suma: `primary.duration + addons.sum(duration)`. Staff musi mieć kompetencje do **głównej usługi**. Addony (małe, proste) nie wymagają osobnego sprawdzenia.

### D6: Backward compatibility

`service_id` FK na `appointments` NIE zmienia się. Stare appointmenty dostają 1 wiersz w pivocie (backfill migration).

---

## 5. Zakres prac — 3 etapy

### Etap 1: Backend + Admin (27h)

| # | Zadanie | Godziny |
|---|---------|---------|
| 1.1 | Migration: `appointment_services` pivot | 1.5h |
| 1.2 | Migration: `service_addons` pivot | 1h |
| 1.3 | Migration: `is_addon`, `is_promotional`, `promotional_price` na services | 1h |
| 1.4 | Migration: `buffer_minutes` setting w SettingsManager | 0.5h |
| 1.5 | Model Appointment: `services()` BelongsToMany, `totalPrice()`, `totalDuration()`, `primaryService()` | 2h |
| 1.6 | Model Service: `addons()`, `addonOf()`, `isAddon()`, promotional logic | 2h |
| 1.7 | BookingCartService: cart CRUD, totals, walidacja | 4h |
| 1.8 | AppointmentService: metody availability dla cumulative duration + multi-service staff check | 4h |
| 1.9 | Filament ServiceResource: "Suggested Addons" relation manager | 3h |
| 1.10 | Filament ServiceResource: pola `is_addon`, `is_promotional`, `promotional_price` | 1h |
| 1.11 | Filament AppointmentResource: wszystkie usługi (primary + addons), total price/duration | 2h |
| 1.12 | Data migration: backfill `appointment_services` dla istniejących appointments | 1h |
| 1.13 | Testy: migracje, modele, BookingCartService, availability | 4h |

### Etap 2: Wizard Redesign + Cart UI (31h)

| # | Zadanie | Godziny |
|---|---------|---------|
| 2.1 | Krok 1 redesign: main service + addon upsell cards | 6h |
| 2.2 | Cart component: persistent header (Alpine.js, AJAX), usługi, cena, remove | 5h |
| 2.3 | E-commerce layout: 2-kolumnowy (lewa: dane, prawa: podsumowanie), responsive | 3h |
| 2.4 | BookingController: refactor session na cart (multi-service), update all steps | 5h |
| 2.5 | Krok 2 (DateTime): cumulative duration dla slot search, update calendar API | 2h |
| 2.6 | Krok 5 (Review): wszystkie usługi, itemized breakdown, gross total | 2h |
| 2.7 | Confirm: appointment z pivot data, snapshot prices/durations | 2h |
| 2.8 | "Perfumy za 1zł": auto-suggest promotional items, badge | 1.5h |
| 2.9 | Routes: cart AJAX endpoints (add/remove addon) | 0.5h |
| 2.10 | Testy: wizard flow, cart, promotional pricing, availability | 4h |

### Etap 3: Notyfikacje, Panel Klienta, GDPR (18h)

| # | Zadanie | Godziny |
|---|---------|---------|
| 3.1 | Notifications: update AppointmentCreatedNotification — lista usług | 2h |
| 3.2 | Notifications: update Rescheduled, Cancelled | 1h |
| 3.3 | Confirmation page: wszystkie usługi z cenami | 1.5h |
| 3.4 | Panel klienta: enhanced appointment detail view | 3h |
| 3.5 | Filament calendar widget: primary + addon count w event title | 1h |
| 3.6 | Event listeners: multi-service context w AppServiceProvider | 1h |
| 3.7 | GDPR: data export mechanism (usługi w eksporcie danych) | 3h |
| 3.8 | GDPR: data retrieval endpoint | 1.5h |
| 3.9 | Testy: notifications, client panel, GDPR export | 4h |

---

## 6. Pliki do modyfikacji

### Nowe pliki (13):
- `database/migrations/xxxx_create_appointment_services_table.php`
- `database/migrations/xxxx_create_service_addons_table.php`
- `database/migrations/xxxx_add_addon_fields_to_services_table.php`
- `database/migrations/xxxx_backfill_appointment_services_from_existing.php`
- `app/Services/BookingCartService.php`
- `app/Filament/Resources/ServiceResource/RelationManagers/AddonsRelationManager.php`
- `resources/views/components/booking-cart.blade.php`
- `resources/views/appointments/show.blade.php`
- `tests/Feature/MultiServiceBookingTest.php`
- `tests/Unit/BookingCartServiceTest.php`
- `tests/Feature/MultiServiceWizardTest.php`
- `tests/Feature/MultiServiceNotificationTest.php`
- `tests/Feature/GdprDataExportTest.php`

### Modyfikowane pliki (15+):
- `app/Http/Controllers/BookingController.php` (745 linii — HEAVIEST)
- `app/Services/AppointmentService.php` (607 linii)
- `app/Models/Appointment.php`
- `app/Models/Service.php`
- `app/Filament/Resources/ServiceResource.php`
- `app/Filament/Resources/AppointmentResource.php`
- `app/Filament/Widgets/AppointmentsCalendar.php`
- `app/Providers/AppServiceProvider.php`
- `app/Support/Settings/SettingsManager.php`
- `app/Notifications/AppointmentCreatedNotification.php`
- `app/Notifications/AppointmentRescheduledNotification.php`
- `app/Notifications/AppointmentCancelledNotification.php`
- `resources/views/booking-wizard/steps/service.blade.php` (REWRITE)
- `resources/views/booking-wizard/layout.blade.php`
- `resources/views/booking-wizard/steps/review.blade.php`
- `resources/views/booking-wizard/confirmation.blade.php`
- `resources/views/appointments/index.blade.php`
- `routes/web.php`

---

## 7. Weryfikacja wyceny — bottlenecki (analiza kodu)

**Data analizy:** 2026-02-02
**Przeczytane pliki:** BookingController.php (745 linii), AppointmentService.php (607 linii), Appointment.php, Service.php, AppServiceProvider.php (event listenery), routes/web.php, ServiceResource.php, AppointmentResource.php

### Bottleneck #1: BookingController refactor (30 coupling points) — KRYTYCZNY

**Ryzyko: WYSOKIE | Estymacja: 5h (zadanie 2.4)**

BookingController (745 linii) jest tightly coupled z single-service flow:
- Session: `booking.service_id` (single value) — 12+ odwołań
- Krok 1: radio buttons → musi być main card + addon upsell cards
- Krok 2: single `duration_minutes` → cumulative duration
- Krok 5: single service display → itemized breakdown
- `confirm()`: single `service_id` w create → pivot data insert

**Wymagane:** Refaktor sesji na cart-based flow, update wszystkich 5 kroków + confirm().
**Max overflow:** +2-4h jeśli edge cases (np. zmiana głównej usługi po dodaniu addonów).

### Bottleneck #2: AppointmentService — 6 metod single-service

**Ryzyko: ŚREDNIE | Estymacja: 4h (zadanie 1.8)**

Wszystkie 6 publicznych metod przyjmuje `int $serviceId` + `int $durationMinutes`. Zmiana na cumulative duration wymaga:
- Nowe sygnatury metod (lub overloady)
- Logika: staff musi mieć kompetencje do primary service
- Duration = suma primary + addonów
- Istniejące callery muszą przekazywać nowe parametry

**Max overflow:** +1-2h jeśli staff competency check wymaga dodatkowej logiki.

### Bottleneck #3: Notyfikacje — singular service reference

**Ryzyko: NISKIE | Estymacja: 3h (zadania 3.1 + 3.2)**

`AppointmentCreatedNotification` referuje `$appointment->service->name` (singular). 3 listenery w AppServiceProvider (linie 150-174). Po zmianach appointment ma wiele usług — notyfikacje muszą wyświetlać listę.

**Wpływ:** Prosty refaktor, niskie ryzyko overflow.

### Bottleneck #4: E-commerce layout — mobile responsive

**Ryzyko: ŚREDNIE | Estymacja: 3h (zadanie 2.3)**

2-kolumnowy layout na desktop → 1-kolumnowy na mobile. Cart w header musi być sticky/drawer na mobile. Alpine.js AJAX cart updates.

**Max overflow:** +1h na edge cases mobile.

### Bottleneck #5: Testy — tight estimates

**Ryzyko: ŚREDNIE | Estymacja: 12h łącznie (4+4+4)**

Multi-service flow wymaga testów: cart CRUD, availability z cumulative duration, wizard flow end-to-end, notifications z listą usług, GDPR export.

**Max overflow:** +2h jeśli edge cases.

### Podsumowanie ryzyk

| Bottleneck | Estymacja | Max overflow |
|------------|-----------|-------------|
| BookingController refactor (30 coupling points) | 5h | +2-4h |
| AppointmentService — 6 metod single-service | 4h | +1-2h |
| Filament v4 namespace issues | 3h+1h | +0-1h |
| E-commerce layout — mobile responsive | 3h | +1h |
| Testy — tight estimates | 12h | +2h |
| **Łączne ryzyko overflow** | | **+6-9h (960-1,440 PLN netto)** |

**Verdykt:** Najgorszy realistyczny scenariusz to 76h → 85h. Nic nie ma potencjału do podwojenia budżetu.

---

## 8. Podsumowanie kosztów

**Stawka:** 160 PLN/h netto (od 2026-02)

| Etap | Godziny | Koszt netto | Koszt brutto (23% VAT) |
|------|---------|-------------|------------------------|
| Etap 1: Backend + Admin | 27h | 4,320 PLN | 5,313.60 PLN |
| Etap 2: Wizard Redesign + Cart UI | 31h | 4,960 PLN | 6,100.80 PLN |
| Etap 3: Notyfikacje, Panel Klienta, GDPR | 18h | 2,880 PLN | 3,542.40 PLN |
| **RAZEM** | **76h** | **12,160 PLN** | **14,956.80 PLN** |

Worst case (overflow): 76h → 85h = 13,600 PLN netto / 16,728 PLN brutto.

### Opcje

**Opcja A: Wszystkie 3 etapy (REKOMENDOWANE)** — 76h / 12,160 PLN netto
- Kompletna funkcjonalność: admin config + wizard + cart + notyfikacje + panel + GDPR

**Opcja B: Etap 1 + 2** — 58h / 9,280 PLN netto
- Backend + wizard działający, ale stare notyfikacje (single service) i panel klienta bez update

**Opcja C: Tylko Etap 1** — 27h / 4,320 PLN netto
- Backend + admin config gotowe. Wizard nadal single-service. Fundament pod Etap 2.

---

## 9. Porównanie ze starą wyceną

| Aspekt | Stara wycena | Nowa wycena |
|--------|-------------|-------------|
| Godziny | 74h (2 etapy) | 76h (3 etapy) |
| Koszt | 7,400 PLN (stara stawka) | 12,160 PLN netto (160 PLN/h) |
| Scope | Generic multi-select + upsell stats | Main+addon, cart, e-commerce layout, promocje, GDPR, enhanced panel |
| Złożoność | DB + backend + basic UI | DB + backend + cart + 2-kolumnowy layout + promotional items + GDPR |

---

## 10. Plan wdrożenia (po akceptacji wyceny)

1. Utworzyć dokumentację client-facing w ClickUp (subtask pod "Wycena - Q1 2026")
2. Po akceptacji klienta → feature branch `feature/multi-service-booking`
3. Etap 1 → Etap 2 → Etap 3 (sekwencyjnie, nie równolegle)
4. Każdy etap: PR do develop → staging → testy → release
5. Release → main → production (z feature flag)

---

## 11. Deliverables

### A. Dokumentacja wewnętrzna (internal)
- `docs/estimations/multi-service-booking/wycena-szczegolowa.md` — ten dokument
- `docs/estimations/multi-service-booking/architecture-analysis.md` — analiza coupling points

### B. Dokumentacja klienta (client-facing)
- ClickUp subtask: "[WYCENA] System Rezerwacji Wielu Usług" — wg formatu CLICKUP_TASK_GUIDELINES.md

### C. Implementacja (po akceptacji)
- Kod + testy + migracje
- Dokumentacja techniczna w `docs/features/`
