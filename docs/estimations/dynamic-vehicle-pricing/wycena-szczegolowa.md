# Analiza + Wycena: Dynamiczne Ceny wg Typu Pojazdu

**Data:** 2026-02-02
**Taski ClickUp:** [86c78xnh9] (skonsolidowany, Opcja A + B)
**Stawka:** 160 PLN/h netto (196,80 PLN/h brutto, 23% VAT)
**Research:** 67+ zrodel rynkowych, analiza kodu (8 modeli, 6 migracji, BookingController 745 linii, booking-wizard.js 1620 linii)

---

## LOGIKA FEATURE

```
1. Admin: przypisuje modele aut -> typy pojazdow (juz istnieje)
2. Admin: ustawia mnoznik per typ pojazdu (np. SUV = 1.45x) -- NOWE
3. Strona produktu: klient wybiera typ pojazdu
   -> cena = base_price x multiplier (dynamicznie, AJAX/Alpine)
   -> jesli user ma pojazd w profilu -> auto-preselect
4. Klient klika "Zarezerwuj" -> obliczona cena + vehicle_type_id do sesji
5. Wizard: cena z mnoznikiem przeplywaz przez wszystkie kroki
6. Confirm: cena zapisana na appointment (snapshot)
```

**Przyklad:** "Komplet" = 500 PLN base. Klient z SUV (1.45x) widzi 725 PLN na stronie produktu -> klika "Zarezerwuj" -> 725 PLN w wizardzie -> 725 PLN na appointment.

---

## OBECNY STAN KODU

| Element | Stan | Plik |
|---------|------|------|
| 5 typow pojazdow w bazie | Istnieje | `VehicleTypeSeeder.php` |
| VehicleType model (name, slug, sort_order) | Istnieje, **brak multiplier** | `app/Models/VehicleType.php` |
| UserVehicle z `is_default` | Istnieje | `app/Models/UserVehicle.php` |
| User -> vehicles() relationship | Istnieje | `app/Models/User.php:377-388` |
| Strona produktu (service detail) | Istnieje, **brak vehicle selector** | `resources/views/services/show.blade.php` |
| Service price display component | Istnieje, statyczna cena | `components/ios/service-details.blade.php` |
| Service card "Od X zl" | Istnieje | `components/ios/service-card.blade.php:147-154` |
| Booking wizard step 3 (vehicle) | Istnieje, **nie wplywa na cene** | `booking-wizard/steps/vehicle-location.blade.php` |
| BookingController confirm() | Istnieje, **nie zapisuje ceny** | `BookingController.php:504-693` |
| Appointment model | Istnieje, **brak kolumny price** | `app/Models/Appointment.php` |
| booking-wizard.js price display | Istnieje, flat `state.service.price` | `resources/js/booking-wizard.js:577,669` |
| Filament VehicleTypeResource | **Istnieje** (brak multiplier field) | `app/Filament/Resources/VehicleTypeResource.php` |
| Schema.org price on service page | Istnieje, statyczne | `ServiceController.php:53-95` |

---

## ZNALEZIONE PROBLEMY I NIEDOSZACOWANIA

### PROBLEM #1 (KRYTYCZNY): Brak ceny na tabeli appointments

Tabela `appointments` NIE MA kolumny `price`. Cena = `$appointment->service->price` w momencie wyswietlania.

**Z dynamic pricing:**
- Klient rezerwuje SUV "Komplet" za 725 PLN (500 x 1.45)
- Admin zmienia cene na 600 PLN
- Stara rezerwacja pokazuje 870 PLN (600 x 1.45) zamiast 725 PLN -- **BLAD**

**Konieczne (prerequisite):** Migration dodajaca `price_at_booking`, `vehicle_type_multiplier_at_booking` do appointments + backfill + update confirm() + update review/notifications.

**Wplyw:** +3-4h obowiazkowe w KAZDEJ opcji.

### PROBLEM #2 (WYSOKI): Stara wycena A niedoszacowana

Wariant A: 2,000 PLN = 13h. Po analizie kodu realna praca to **31h = 4,960 PLN** (z price storage, testami, i cross-cutting price flow). Szczegoly w sekcji "Zakres prac".

### PROBLEM #3 (WYSOKI): Stara wycena B -- range 3,200-4,600 jest zbyt szeroki

Range 1,400 PLN (20-29h) nie daje klientowi jasnej informacji. Po analizie: Opcja B to realnie **42h = 6,720 PLN**.

### PROBLEM #4 (SREDNI): Step 3 wizarda -- duplikacja vehicle type

Vehicle type wybierany na stronie produktu. Step 3 wizarda TEZ pyta o vehicle type. Po zmianie:
- Step 3 powinien pokazywac PRE-SELECTED vehicle type z sesji
- Klient moze zmienic (ale cena sie przelicza)
- Step 3 skupia sie na: potwierdzenie vehicle type + wybor konkretnego auta z profilu + lokalizacja

### PROBLEM #5 (SREDNI): Zaokraglanie cen

199 PLN x 1.45 = 288.55 PLN -- brzydkie. Trzeba zdecydowac o zaokraglaniu (do pelnych PLN, do 5 PLN, brak).

### PROBLEM #6 (NISKI): Schema.org structured data

`ServiceController.php:53-95` generuje Schema.org z cena. Z dynamic pricing: `priceRange` zamiast fixed `price`. Lub `minPrice` z najnizszego mnoznika.

---

## RESEARCH -- KLUCZOWE USTALENIA (67+ zrodel)

### Model cenowy branzowy

| Podejscie | % firm |
|-----------|--------|
| Stala cena per usluga+pojazd | 60% |
| Mnoznik z override'ami | 25% |
| Tylko mnoznik globalny | 15% |

Klient wybral mnoznik globalny -- prostsze, wystarczajace dla jego modelu biznesowego.

### Typowe mnozniki (rynkowe)

| Kategoria | Range rynkowy |
|-----------|---------------|
| Male (city car) | 1.00x (base) |
| Male/Srednie (sedan) | 1.10-1.15x |
| SUV/Crossover | 1.25-1.35x |
| Duzy SUV/Van | 1.40-1.50x |
| Dostawcze | 1.50-1.80x |

**1.45x dla SUV -- w normie rynkowej** (gorna granica, odpowiednia dla duzych SUV/Van).

### UX best practices

- Kafelki > dropdown na stronie produktu (wyzszy engagement)
- NIE pokazuj mnoznika klientowi -- tylko koncowa cene
- Pre-select vehicle z profilu -> wyzsza konwersja
- "Od X PLN" na kartach uslug -- standard branzowy
- Mobile (70% rezerwacji): sticky price footer

### Cena historyczna -- standard branzowy

Bookeo, Acuity, Square: cena zapisana w momencie rezerwacji. Zmiana cennika nie wplywa na istniejace bookings. `price_at_booking` = standard.

---

## ZAKRES PRAC -- SCALONA WYCENA Z OPCJAMI (REWIZJA v2)

> **UWAGA:** Wycena v1 (16h/24h) byla niedoszacowana. Po szczegolowej analizie kodu
> (BookingController 745 linii, booking-wizard.js 1620 linii, step 3 = 1117 linii)
> i zidentyfikowaniu fundamentalnej zmiany architektury przeplywu cen -- ponizej realistyczna wycena.

### Dlaczego wycena v1 byla za niska -- glowne przyczyny

| Problem | Estymacja v1 | Realistycznie | Powod |
|---------|-------------|---------------|-------|
| Testowanie (Opcja A) | 1h | 4h | 12+ plikow, obliczenia cenowe, booking flow, migracje |
| booking-wizard.js (1620 linii) | 1h | 2h | Tight state coupling, `state.service.price` w wielu miejscach |
| Step 3 pre-select + recalculation (1117 linii) | 1h | 3h | Google Maps, Alpine.js, bottom sheet + nowy AJAX/recalc |
| BookingController session flow (745 linii) | 2h | 3h | Session across 5 steps, server-side recalculation security |
| BookingController confirm() | 1h | 2h | Server-side validation ceny, edge cases |
| Review step (3x hardcoded `$service->price`) | 1h | 1h | 3 odwolania + breakdown ceny |
| Notifications (DB-stored templates) | 1h | 2h | EmailService templates w bazie + kod PHP |
| Backfill (edge cases: deleted services) | 1h | 1h | Soft-deleted services, null handling |
| Brak bufora na integracje | 0h | +15% | 12+ plikow, 3 warstwy (PHP + JS + Blade) |

### Wspolna baza (obowiazkowa w obu opcjach)

| # | Zadanie | v1 | v2 | Uzasadnienie zmiany |
|---|---------|----|----|---------------------|
| 1 | Migration: `multiplier` (decimal 4,2) do `vehicle_types` | 1h | 1h | -- |
| 2 | Migration: `price_at_booking`, `vehicle_type_id_at_booking`, `vehicle_multiplier_at_booking` do `appointments` | 1h | 1h | -- |
| 3 | Backfill: istniejace appointments -> cene z service.price | 1h | 1h | Edge cases: soft-deleted services, null service_id |
| 4 | VehicleType model: multiplier fillable/casts | 1h | 1h | -- |
| 5 | Service model: `priceForVehicleType()` + rounding strategy | 1h | 1h | -- |
| 6 | Appointment model: price fields fillable/casts | 1h | 1h | -- |
| 7 | BookingController: vehicle_type_id + calculated_price do sesji + przekazanie do views | 2h | 3h | 745 linii, session across 5 steps, edge cases (brak vehicle type, deep link) |
| 8 | BookingController confirm(): price_at_booking + server-side recalculation (security) | 1h | 2h | Nie ufamy client price, rekalkulacja + walidacja spojnosci |
| 9 | Step 3 wizarda: pre-select + zmiana vehicle type + AJAX recalculation ceny | 1h | 3h | 1117 linii Blade+Alpine+Maps, saveProgress integration, price display |
| 10 | booking-wizard.js: `state.calculatedPrice` zamiast `state.service.price` | 1h | 2h | 1620 linii, tight state coupling, navigation back/forward edge cases |
| 11 | Review step: obliczona cena + breakdown (base x multiplier) | 1h | 1h | 3x hardcoded `$service->price`, dodanie breakdown |
| 12 | Notifications: price w emailach + update DB template | 1h | 2h | EmailServiceChannel + DB-stored templates (TemplateKey) |
| 13 | Service card "Od X PLN": dynamiczny min(multiplier) | 1h | 1h | Uwaga na N+1, potrzeba cache |
| 14 | Schema.org: priceRange | 1h | 1h | -- |
| **Wspolna baza** | | **14h** | **21h** | **+7h** |

### Opcja A: MVP -- Dropdown (29h / 4,640 PLN)

| # | Zadanie (ponad baze) | v1 | v2 | Uzasadnienie |
|---|----------------------|----|----|-------------|
| A1 | Service detail page: dropdown + Alpine.js price update + form CTA modification | 2h | 3h | Nowy component, AJAX/client-side calc, CTA z vehicle_type_id |
| A2 | Auto-preselect z profilu usera (auth + default vehicle) | 1h | 1h | -- |
| A3 | Filament: multiplier field + walidacja (>0, format) | 1h | 2h | Walidacja, preview cen |
| A4 | Testy: migration, pricing calc, booking flow, session persistence, edge cases | 1h | 4h | **Glowna korekta** -- 12+ plikow, dane finansowe |
| **Opcja A lacznie** | Baza + A-specific | **19h** | **31h = 4,960 PLN** | **+12h (+63%)** |

**Co klient dostaje:**
- Dropdown wyboru pojazdu na stronie uslugi
- Dynamiczna aktualizacja ceny po wyborze
- Auto-preselect jesli user ma pojazd w profilu
- Cena zapisana na rezerwacji (nie zmienia sie po zmianie cennika)
- Integracja z wizardem rezerwacji -- cena przeplywaz przez wszystkie kroki
- Admin ustawia mnozniki w panelu
- Pelne testy automatyczne

**Ograniczenia vs Opcja B:**
- Prosty dropdown zamiast wizualnych kafelkow
- Brak sticky footer na mobile
- Brak oznaczenia "Najpopularniejsze"
- Brak animacji przy zmianie ceny
- Brak "auto-fill" proponowanych cen w adminie

### Opcja B: Pelna wersja -- Kafelki + UX Polish (42h / 6,720 PLN)

| # | Zadanie (ponad baze) | v1 | v2 | Uzasadnienie |
|---|----------------------|----|----|-------------|
| B1 | Kafelki vehicle type z ikonami + cenami, Alpine.js animowany price update | 4h | 5h | Pelny component z ikonami per vehicle type, responsive grid |
| B2 | Auto-preselect z profilu + visual highlight | 1h | 1h | -- |
| B3 | Badge "Najpopularniejsze" (query stats) | 2h | 2h | -- |
| B4 | Sticky price footer na mobile | 2h | 2h | iOS-style bottom bar, scroll detection, z-index management |
| B5 | Animacja zmiany ceny (smooth transition) | 1h | 1h | -- |
| B6 | Filament: VehicleTypeResource + auto-fill suggestion + preview | 2h | 3h | Pelny resource, preview obliczonych cen per service |
| B7 | Mobile validation + touch targets | 1h | 2h | 44px touch targets, bottom sheet UX na mobile |
| B8 | Testy: pelne (migration, pricing, kafelki, mobile, animations, booking flow) | 2h | 5h | **Glowna korekta** -- Opcja A testy + kafelki + mobile + animacje |
| **Opcja B lacznie** | Baza + B-specific | **27h** | **42h = 6,720 PLN** | **+15h (+56%)** |

**Co klient dostaje (ponad Opcja A):**
- Wizualne kafelki z ikonami pojazdow i cenami
- Oznaczenie "Najpopularniejsze" na najczestszym vehicle type
- Animacja smooth transition przy zmianie ceny
- Sticky price footer na mobile (zawsze widoczna cena)
- Weryfikacja walidacji na mobile + touch targets 44px
- Auto-fill suggested prices w panelu admina
- Pelne testy automatyczne (rozszerzone o UI components)

---

## PODSUMOWANIE KOSZTOW (REWIZJA v2 -- tylko development, BEZ QA/contingency)

> **UWAGA:** To jest TYLKO koszt development. Pelna wycena z QA, bug fixing, contingency i wsparciem klienta -- patrz sekcja "PODSUMOWANIE KOSZTOW v3 (FINALNA)" nizej.

| Opcja | Godziny DEV | Netto (tylko dev) |
|-------|-------------|-------------------|
| Opcja A: MVP (dropdown) | 31h | 4,960 PLN |
| Opcja B: Pelna (kafelki) | 42h | 6,720 PLN |

---

## RYZYKA (wliczone w contingency 15% w v3)

Ponizsze ryzyka sa pokryte przez bufor contingency w wycenie v3:

| Ryzyko | Prawdop. | Wplyw |
|--------|----------|-------|
| booking-wizard.js -- regresja w istniejacym flow | SREDNIE | WYSOKI |
| Session persistence -- edge cases (timeout, back button, refresh) | SREDNIE | SREDNI |
| Step 3 -> product page -> step 3 desync | NISKIE | SREDNI |
| Zaokraglanie cen (brzydkie kwoty) | NISKIE | NISKI |
| Mobile responsive (kafelki, Opcja B) | SREDNIE | SREDNI |
| Email template rendering z nowym variable | NISKIE | NISKI |

---

## QA, MANUAL TESTING, CONTINGENCY

> **UWAGA:** Poprzednie wersje wyceny (v1, v2) NIE zawieraly tych pozycji.
> Ponizej obowiazkowe elementy dla profesjonalnej wyceny.

### 1. Manual QA -- przejscie pelnego flow przez developera

Przed oddaniem klientowi -- developer musi sam przejsc CALY flow:

| Scenariusz testowy | Czas |
|-------------------|------|
| Strona produktu: wybor kazdego z 5 typow pojazdow, weryfikacja ceny | 1h |
| Booking wizard: pelny flow (step 1->5) z vehicle type z product page | 1h |
| Booking wizard: zmiana vehicle type w step 3, weryfikacja przeliczenia ceny | 1h |
| Panel admin: ustawienie mnoznikow, weryfikacja ze ceny sie przeliczaja | 1h |
| Powiadomienia email: weryfikacja ceny w mailu potwierdzajacym | 1h |
| Edge cases: brak vehicle type, deep link do wizarda, sesja wygasla, back button | 1h |
| Mobile: pelny flow na telefonie (responsive, touch targets, sticky footer B) | 1h |
| **Manual QA lacznie** | **7h** |

### 2. Bug fixing po QA

Podczas manual QA ZAWSZE znajduja sie bledy. Przy 12+ zmodyfikowanych plikach i cross-cutting price flow -- statystycznie:

| Element | Czas |
|---------|------|
| Bug fixing po manual QA (estymacja: 3-5 bugow) | 3h |
| Re-test po naprawach | 1h |
| **Bug fixing lacznie** | **4h** |

### 3. Contingency -- rynkowy bufor ryzyka

Standard branzowy: **15-20%** na nieoczekiwane problemy.

Przy 31h (Opcja A): 15% = 5h
Przy 42h (Opcja B): 15% = 7h (zaokraglone w gore)

| Element | Opcja A | Opcja B |
|---------|---------|---------|
| Contingency 15% | 5h | 7h |

Contingency pokrywa: niespodziewane konflikty kodu, regresje w istniejacych funkcjach, problemy z migracja na produkcji, edge cases nieuwzglednione w planowaniu.

### 4. Wsparcie podczas weryfikacji klienta

Po oddaniu feature -- klient testuje sam: panel admin, panel klienta, dane, pelny flow jako user. Feedback -> poprawki:

| Element | Czas |
|---------|------|
| Adresowanie feedbacku klienta po weryfikacji (estymacja: 2-4 uwagi) | 3h |
| **Wsparcie klienta lacznie** | **3h** |

---

## PODSUMOWANIE KOSZTOW (REWIZJA v3 -- FINALNA)

### Opcja A: MVP -- Dropdown

| Kategoria | Godziny |
|-----------|---------|
| Wspolna baza (14 zadan) | 21h |
| A-specific (4 zadania) | 10h |
| Manual QA | 7h |
| Bug fixing po QA | 4h |
| Contingency 15% | 5h |
| Wsparcie weryfikacji klienta | 3h |
| **OPCJA A LACZNIE** | **50h = 8,000 PLN netto** |

### Opcja B: Pelna -- Kafelki + UX Polish

| Kategoria | Godziny |
|-----------|---------|
| Wspolna baza (14 zadan) | 21h |
| B-specific (8 zadan) | 21h |
| Manual QA | 7h |
| Bug fixing po QA | 4h |
| Contingency 15% | 7h |
| Wsparcie weryfikacji klienta | 3h |
| **OPCJA B LACZNIE** | **63h = 10,080 PLN netto** |

### Tabela porownawcza WSZYSTKICH wersji

| | Stara (ClickUp) | v1 | v2 | **v3 (finalna)** |
|---|----------------|----|----|-----------------|
| Opcja A | 2,000 PLN (13h) | 2,560 PLN (16h) | 4,960 PLN (31h) | **8,000 PLN (50h)** |
| Opcja B | 3,200-4,600 PLN | 3,840 PLN (24h) | 6,720 PLN (42h) | **10,080 PLN (63h)** |

### Co dodaje v3 vs v2?

| Nowa pozycja | Opcja A | Opcja B | Uzasadnienie |
|-------------|---------|---------|-------------|
| Manual QA (pelny flow) | +7h | +7h | Developer musi sam przejsc CALY flow przed oddaniem |
| Bug fixing po QA | +4h | +4h | Statystycznie 3-5 bugow przy 12+ plikach |
| Contingency 15% | +5h | +7h | Rynkowy standard zabezpieczenia wykonawcy |
| Wsparcie weryfikacji klienta | +3h | +3h | Feedback klienta -> poprawki |
| **Suma dodana** | **+19h** | **+21h** | |

### Brutto (23% VAT)

| Opcja | Netto | Brutto |
|-------|-------|--------|
| **Opcja A: MVP** | 8,000 PLN | 9,840 PLN |
| **Opcja B: Pelna** | 10,080 PLN | 12,398.40 PLN |

**Notatka o synergii z multi-service booking:**
Jesli oba taski realizowane RAZEM: oszczednosc ~5-6h na wspolnym refactorze. Laczna oszczednosc: ~800-960 PLN netto.

---

## PLIKI DO MODYFIKACJI

### Nowe pliki:
- `database/migrations/xxxx_add_multiplier_to_vehicle_types.php`
- `database/migrations/xxxx_add_price_fields_to_appointments.php`
- `database/migrations/xxxx_backfill_appointment_prices.php`
- `resources/views/components/vehicle-type-selector.blade.php` (Opcja B: kafelki)
- `tests/Feature/DynamicPricingTest.php`

### Modyfikowane pliki:
- `app/Models/VehicleType.php` -- dodac multiplier
- `app/Models/Service.php` -- metoda priceForVehicleType()
- `app/Models/Appointment.php` -- dodac price fields
- `app/Http/Controllers/ServiceController.php` -- vehicle types do widoku
- `app/Http/Controllers/BookingController.php` -- price flow + confirm()
- `app/Filament/Resources/VehicleTypeResource.php` -- multiplier field
- `resources/views/services/show.blade.php` -- vehicle selector
- `resources/views/components/ios/service-details.blade.php` -- dynamic price
- `resources/views/components/ios/service-card.blade.php` -- "Od X PLN"
- `resources/js/booking-wizard.js` -- price z mnoznikiem
- `resources/views/booking-wizard/steps/vehicle-location.blade.php` -- pre-select
- `resources/views/booking-wizard/steps/review.blade.php` -- obliczona cena
- `app/Notifications/AppointmentCreatedNotification.php` -- cena z appointment

---

## PLAN REALIZACJI

1. Po akceptacji wyceny -> `feature/dynamic-vehicle-pricing`
2. Release -> staging -> production

---

## OTWARTE PYTANIA (do ustalenia z klientem PRZED implementacja, nie przed wycena)

- Mnozniki dla WSZYSTKICH 5 typow pojazdow (klient podal tylko SUV=1.45x)
- Zaokraglanie cen: do pelnych PLN, do 5 PLN, czy bez?
- Mnoznik na addony z multi-service: tak/nie/selektywnie?
- Czy `price_from` na service nadal ma sens? (rekomendacja: zastapic dynamicznym "Od" z min multiplier)
