# [WYCENA] Rezerwacja bez zakładania konta (Guest Checkout)

**Data:** 2026-02-01
**Status:** Wycena wewnętrzna (technical) — do wyciągnięcia wersji client-facing

---

## 1. Analiza obecnej architektury

### 1.1. Booking Wizard — 5-krokowy flow

Obecny flow rezerwacji (`BookingController`):
1. Wybór usługi
2. Wybór daty/godziny (kalendarz)
3. Pojazd i lokalizacja
4. Dane kontaktowe (pre-fill z profilu auth user)
5. Podsumowanie i potwierdzenie

**CRITICAL:** Cały flow wymaga `$this->middleware('auth')` (BookingController:35).

### 1.2. Punkty sprzężenia z auth — 44 miejsca

| Komponent | Coupling points | Złożoność |
|-----------|----------------|-----------|
| BookingController | 15 miejsc | KRYTYCZNA |
| AppointmentController | 5 miejsc | WYSOKA |
| Routes (web.php) | 14 chronionych tras | KRYTYCZNA |
| Appointment Model | 4 miejsca | WYSOKA |
| Database Schema | 2 constrainty | KRYTYCZNA |
| Views (Blade) | 3 miejsca | NISKA |
| Notifications | 1 miejsce | ŚREDNIA |

### 1.3. Kluczowe blokery

1. **Database:** `customer_id` w `appointments` jest NOT NULL + CASCADE DELETE (migration `2025_10_06_190503`)
2. **Controller:** `auth()` middleware na wszystkich 14 trasach booking (web.php:109)
3. **Transaction block:** 11 operacji user-dependent w `confirm()` (linie 598-662)
4. **Idempotency check:** duplikaty sprawdzane po `customer_id` (linia 529)
5. **Ownership verification:** `$appointment->customer_id !== auth()->id()` (linia 712)

### 1.4. Co już istnieje (groundwork)

- Pola kontaktowe na `appointments`: `first_name`, `last_name`, `email`, `phone` (nullable) — snapshot danych (migration `2025_12_12_171038`)
- Event-driven architecture: `AppointmentCreated`, `AppointmentCancelled` etc.
- Session-based booking data storage (już używane w wizardzie)
- Rate limiting na endpointach booking (30 req/min step store, 10 req/min confirm)

---

## 2. Research — kluczowe ustalenia (50+ źródeł)

### 2.1. Główny problem: stracone zamówienia = stracony przychód

| Fakt | Liczba | Źródło |
|------|--------|--------|
| Porzucenia z powodu obowiązkowej rejestracji | **24-26%** | Baymard Institute (1,026 respondentów) |
| Porzucenia bo "zapomniałem hasła" | **19%** | Baymard Institute |
| Wzrost konwersji po dodaniu guest checkout | **57%** | Automate.io |
| Redukcja porzuceń | **30%** | średnia z wielu źródeł |
| Serwisy które przerywają checkout za wcześnie | **42%** | Baymard Institute |
| Guest checkout users którzy MAJĄ konto | **72%** | Adweek / Shift4Shop |
| Porzucenia booking flow (usługi) | **40-75%** | hospitality/service industry |
| Odzyskiwalne zamówienia (email recovery) | **15-30%** | multi-channel recovery data |

**Kluczowy insight:** Co 4. klient który CHCE zarezerwować — odchodzi, bo musi się zarejestrować.

### 2.2. Scenariusz: klient z kontem który zapomniał się zalogować

**72% ludzi wybierających guest checkout to POWRACAJĄCY klienci** (Adweek).

Dlaczego nie logują się:
- Zapomniałem hasła (19% — Baymard)
- Inna przeglądarka / telefon (30% czyści cookies miesięcznie)
- "Jestem tu żeby zarezerwować, nie żeby zarządzać kontem"

**Rozwiązanie: Email-First Pattern**
```
Klient wpisuje email w kroku kontaktowym
    ↓
System sprawdza: czy ten email ma konto?
    ↓
TAK → "Masz konto! Zaloguj się (magic link) LUB kontynuuj bez logowania"
NIE → Kontynuuj bez przeszkód
```

Wyniki email-first:
- **10-12%** wzrost konwersji (Crazy Egg, Timothy Sykes)
- **41%** redukcja porzuceń checkout (MojoAuth)
- **50%** wyższa konwersja niż tradycyjny guest (Shop Pay / Shopify)

### 2.3. Mobile: problem jest jeszcze większy

| Urządzenie | Abandonment rate |
|------------|-----------------|
| Mobile | **85.65%** |
| Tablet | **80.74%** |
| Desktop | **73.07%** |

- **82% klientów** usług bookingowych używa telefonu (Zippia)
- Wpisywanie hasła na klawiaturze ekranowej = ogromna frustracja
- **1 sekunda** opóźnienia = **-20%** konwersji mobile (Think with Google)

### 2.4. Rekomendowana architektura DB

**Nullable foreign key pattern** (standard branżowy):
- `customer_id` → nullable, `onDelete('set null')`
- Dane gościa w istniejących polach snapshot (`first_name`, `last_name`, `email`, `phone`)
- Dodatkowe pole `guest_token` (UUID) do zarządzania rezerwacją przez magic link
- Pole `is_guest` (boolean) dla łatwego filtrowania

### 2.5. Powiadomienia dla gości

Laravel On-Demand Notifications: `Notification::route('mail', $email)->notify(...)` — nie wymaga User modelu.

### 2.6. Bezpieczeństwo (multi-layer)

1. **reCAPTCHA v3** lub **Cloudflare Turnstile** (niewidoczny, score-based)
2. **Honeypot** (spatie/laravel-honeypot — 90%+ spam block, zero UX impact)
3. **Rate limiting** (5 req/min per IP na endpoint confirm)
4. **Disposable email detection** (blokada tymczasowych maili)
5. **Email verification** (link potwierdzający przed aktywacją rezerwacji)

### 2.7. GDPR/RODO

- Potwierdzenia rezerwacji = transactional emails → **nie wymagają opt-in** (legitimate interest)
- Dane gości: retencja 7 lat (polskie prawo podatkowe), anonimizacja cancelled po 1 roku
- Sesyjne cookie (CSRF, Laravel session) = strictly necessary → **bez consent**

### 2.8. Lazy Registration (tworzenie kont)

Best practice: oferować założenie konta DOPIERO na ekranie potwierdzenia (po rezerwacji).
- Użytkownik już jest zaangażowany (sunk cost effect — Arkes & Blumer, 1985)
- Wystarczy podać hasło (dane już mamy z formularza)
- Framing: "Zapisz dane do szybszej rezerwacji" (nie "Zarejestruj się")
- **42% serwisów** prosi o konto za wcześnie (Baymard) — my tego unikamy

---

## 3. Zakres prac — podział na etapy

### Etap 1: Core Guest Checkout (MVP)

**Co klient dostaje:**
- Rezerwacja bez logowania — klient wchodzi na stronę i rezerwuje od razu
- Email-first pattern: system rozpoznaje klientów z kontem i oferuje szybkie logowanie (magic link)
- Email potwierdzający z linkiem do zarządzania rezerwacją
- Panel admina widzi rezerwacje gości obok zwykłych

**Scenariusze użytkownika:**

| Scenariusz | Co się dzieje |
|------------|---------------|
| Nowy klient (bez konta) | Rezerwuje bez przeszkód → opcja konta po rezerwacji |
| Klient z kontem, zalogowany | Flow bez zmian, jak dziś |
| Klient z kontem, niezalogowany | Wpisuje email → sugestia "Masz konto, zaloguj się jednym kliknięciem" LUB kontynuuj jako gość → rezerwacja podpina się do konta po emailu |

**Prace techniczne:**

| Zadanie | Godziny |
|---------|---------|
| Migration: `customer_id` nullable + `guest_token` + `is_guest` | 2h |
| Refactor routes: booking routes bez middleware auth | 2h |
| Refactor BookingController: obsługa guest flow (warunkowy auth) | 8h |
| Email-first: account detection (AJAX check email → "masz konto?") | 2h |
| Inline AJAX login modal (Alpine.js overlay, BEZ redirect na /login) | 4h |
| → AJAX login endpoint + CSRF token refresh + session safety | |
| → Auto-fill booking form z danych usera po zalogowaniu | |
| → Magic link jako alternatywa hasła (email one-click login) | |
| GuestBookingService: logika tworzenia rezerwacji gościa | 4h |
| Guest notifications: On-Demand email/SMS (bez User modelu) | 4h |
| Magic link: token-based booking management (cancel/view) | 4h |
| Security: reCAPTCHA v3 + honeypot + rate limiting + disposable email | 5h |
| Email verification: link potwierdzający dla gości | 3h |
| Filament: oznaczenie gości w AppointmentResource | 3h |
| Testy (unit + feature): 25-30 testów | 6h |
| **Suma Etap 1** | **47h** |

**Architektura inline login (BEZ redirect):**
- Klient wpisuje email w step 4 → AJAX check `POST /ajax/check-email`
- Jeśli email ma konto → wyświetl overlay/modal: "Zaloguj się (hasło lub magic link)"
- Login via `POST /ajax/login` → JSON response z nowym CSRF tokenem + danymi usera
- Po zalogowaniu: auto-fill formularza danymi z profilu, modal się zamyka
- Booking data w sesji **przeżywa** session regeneration (Laravel migruje dane)
- CSRF token odświeżany dynamicznie w DOM (meta tag + hidden inputs)
- Klient NIE opuszcza strony rezerwacji w żadnym momencie

**Pliki do modyfikacji:**
- `database/migrations/xxxx_make_customer_id_nullable.php` (NEW)
- `routes/web.php` (MODIFIED)
- `app/Http/Controllers/BookingController.php` (MODIFIED — heaviest)
- `app/Http/Controllers/AjaxAuthController.php` (NEW — AJAX login + email check)
- `app/Models/Appointment.php` (MODIFIED)
- `app/Services/GuestBookingService.php` (NEW)
- `app/Notifications/GuestBookingConfirmation.php` (NEW)
- `app/Notifications/GuestBookingCancellation.php` (NEW)
- `app/Http/Controllers/GuestBookingController.php` (NEW — magic link handling)
- `resources/views/booking-wizard/steps/contact.blade.php` (MODIFIED — email-first + login modal)
- `resources/views/components/booking-login-modal.blade.php` (NEW — Alpine.js overlay)
- `resources/views/guest/booking-management.blade.php` (NEW)
- `app/Filament/Resources/AppointmentResource.php` (MODIFIED)
- `config/recaptcha.php` (NEW)
- `tests/Feature/GuestBookingTest.php` (NEW)
- `tests/Feature/AjaxLoginTest.php` (NEW)

### Etap 2: Lazy Registration + Zestawienie korzyści konta + UX Polish

**Co klient dostaje:**
- Po rezerwacji: propozycja "Zapisz dane do szybszego rezerwowania" (opcjonalne konto)
- Zestawienie korzyści konta — co zyskuje zakładając konto (na stronie potwierdzenia + w emailu)
- Jeśli gość założy konto — wszystkie jego rezerwacje automatycznie się podepną
- Formularz rezerwacji dostosowany do gości (bez readonly email, z walidacją inline)

**Zestawienie korzyści konta (UX research — 80+ źródeł):**

Format: inline checklist z ikonami (NIE tabelka porównawcza — tabelki słabo działają na mobile i sugerują dwie równorzędne opcje). Pozytywny framing ("zyskujesz") zamiast negatywnego ("tracisz").

Timing: POST-PURCHASE (strona potwierdzenia + email). Pokazanie porównania PRZED rezerwacją obniża konwersję o 19-34% (Baymard Institute). Po rezerwacji 15-22% gości zakłada konto (Creative Market case study).

Treść:
```
Z KONTEM ZYSKUJESZ:
✓ Przypomnienia SMS o nadchodzącej wizycie
✓ Rezerwacja ponowna w 10 sekund z historii
✓ Karta stałego klienta — zniżki na usługi
✓ Informacje o promocjach i nowościach
✓ Zapisane auta w profilu — bez wpisywania za każdym razem
✓ Pełna historia rezerwacji w panelu "Moje rezerwacje"
```

Miejsca wyświetlania:
1. Strona potwierdzenia rezerwacji (`confirmation.blade.php`) — checklist + formularz "podaj hasło"
2. Email potwierdzający (`GuestBookingConfirmation`) — sekcja korzyści + magic link do założenia konta

Mobile: pionowa lista (bottom sheet po 3s na stronie potwierdzenia). Touch target min 44px.

**Prace techniczne:**

| Zadanie | Godziny |
|---------|---------|
| GuestConversionService: konwersja gość → user | 4h |
| UI: propozycja konta + zestawienie korzyści na ekranie potwierdzenia | 3h |
| Komponent Blade: lista korzyści konta z ikonami (desktop + mobile bottom sheet) | 1.5h |
| Sekcja korzyści w emailu potwierdzającym (GuestBookingConfirmation) | 0.5h |
| Integracja z confirmation.blade.php + responsive styling | 1h |
| Auto-link: podpinanie starych rezerwacji gościa pod nowy user | 3h |
| UX contact step: warunkowe UI (guest vs auth) | 3h |
| Testy: 10-15 testów | 3h |
| **Suma Etap 2** | **19h** |

**Pliki do modyfikacji:**
- `app/Services/GuestConversionService.php` (NEW)
- `resources/views/booking-wizard/confirmation.blade.php` (MODIFIED)
- `resources/views/components/account-benefits-checklist.blade.php` (NEW — reusable component)
- `resources/views/booking-wizard/steps/contact.blade.php` (MODIFIED)
- `app/Http/Controllers/GuestRegistrationController.php` (NEW)
- `tests/Feature/GuestConversionTest.php` (NEW)

---

## 4. Podsumowanie kosztów

**Stawka:** 160 PLN/h netto (od 2026-02)

| Etap | Godziny | Koszt netto | Koszt brutto (23% VAT) |
|------|---------|-------------|------------------------|
| Etap 1: Core Guest Checkout + Email-First + Inline Login | 47h | 7,520 PLN | 9,249.60 PLN |
| Etap 2: Lazy Registration + Zestawienie korzyści konta + UX | 19h | 3,040 PLN | 3,739.20 PLN |
| **RAZEM (Etap 1+2)** | **66h** | **10,560 PLN** | **12,988.80 PLN** |

### Rekomendacja

**Opcja A: Etap 1 + 2** (POLECANE)
- 66h / 10,560 PLN netto
- Guest checkout + email-first + inline AJAX login + lazy registration + zestawienie korzyści konta

**Opcja B: Tylko Etap 1** (MVP)
- 47h / 7,520 PLN netto
- Guest checkout + email-first + inline login, bez konwersji gość→konto

---

## 5. ROI / Uzasadnienie biznesowe

**Główny KPI: dodatkowe złożone zamówienia = dodatkowy przychód klienta**

### Scenariusz konserwatywny (do weryfikacji z klientem)

| Metryka | Wartość |
|---------|--------|
| Rezerwacje miesięcznie (obecne) | 100 |
| Stracone z powodu rejestracji (26%) | ~26/mies. |
| Średnia wartość rezerwacji | 200-400 PLN |
| **Stracony przychód miesięcznie** | **5,200 - 10,400 PLN** |
| **Stracony przychód rocznie** | **62,400 - 124,800 PLN** |

### Po wdrożeniu

| Metryka | Wartość |
|---------|--------|
| Odzyskane rezerwacje (konserwatywnie 50% z 26) | +13/mies. |
| Dodatkowy przychód miesięcznie | 2,600 - 5,200 PLN |
| Dodatkowy przychód rocznie | 31,200 - 62,400 PLN |
| Koszt wdrożenia (Etap 1+2) | 6,300 PLN |
| **Payback** | **1-2 miesiące** |

### Scenariusz optymistyczny (branżowe średnie)

| Metryka | Wartość |
|---------|--------|
| Wzrost konwersji (57% — Automate.io) | z 100 na 157/mies. |
| Dodatkowy przychód miesięcznie | 11,400 - 22,800 PLN |
| Dodatkowy przychód rocznie | 136,800 - 273,600 PLN |

---

## 6. Ryzyka i mitygacje

| Ryzyko | Prawdop. | Wpływ | Mitygacja |
|--------|----------|-------|-----------|
| Spam/bot bookings | Średnie | Wysoki | 5-warstwowe zabezpieczenia (reCAPTCHA + honeypot + rate limit + disposable email + email verification) |
| Duplikaty rezerwacji gości | Niskie | Średni | Idempotency check po email + date + service |
| Literówka w email gościa | Średnie | Wysoki | Obowiązkowa weryfikacja emaila (confirmation link) |
| RODO naruszenie | Niskie | Bardzo wysoki | Transactional emails bez opt-in (legal basis: legitimate interest), auto-retencja |
| Regresja istniejącego flow auth | Niskie | Wysoki | Feature flag, 30+ testów, zachowanie pełnego auth flow |

---

## 6a. Weryfikacja wyceny — bottlenecki (analiza kodu)

**Data analizy:** 2026-02-02
**Przeczytane pliki:** BookingController.php (745 linii), AppServiceProvider.php (event listenery), Appointment.php, UserConsent.php, User.php (SMS consent), routes/web.php

### Bottleneck #1: Event listenery CRASHUJĄ dla gości (KRYTYCZNE)

**Ryzyko: WYSOKIE | Estymacja pokryta w: "Guest notifications: 4h"**

W `AppServiceProvider.php` są 3 event listenery które zakładają że customer istnieje:

```php
// Linia 150-154 — AppointmentCreated
$event->appointment->customer->notify(
    new AppointmentCreatedNotification($event->appointment, 'customer')
);

// Linia 158-163 — AppointmentRescheduled
$event->appointment->customer->notify(...);

// Linia 169-173 — AppointmentCancelled
$event->appointment->customer->notify(...);
```

Gdy `customer_id` jest null (guest), `$appointment->customer` zwraca null → **fatal TypeError**.

Event `AppointmentCreated` odpala się AUTOMATYCZNIE z modelu (`$dispatchesEvents` w Appointment.php:43-45).

**Dobra wiadomość:** SMS helper (linia 253) JUŻ używa nullsafe: `$appointment->phone ?? $appointment->customer?->phone`. SMS nie crashnie.

**Wymagane:** Refaktor 3 istniejących listenerów + dodanie guest notification path. Mieści się w 4h, ALE musi być EXPLICIT w planie wdrożenia.

### Bottleneck #2: Transaction block w confirm() — GDPR consent

**Ryzyko: ŚREDNIE | Estymacja: "Refactor BookingController: 8h"**

Transaction block (linie 597-662) zawiera logikę GDPR:

| Operacja | Linia | Guest handling |
|----------|-------|---------------|
| Profile update (`$user->update(...)`) | 599-621 | SKIP |
| `Appointment::create` z `customer_id` | 624-649 | `customer_id => null` |
| SMS consent (`$user->hasSmsConsent()`) | 651-656 | SKIP (brak User modelu) |
| `UserConsent::recordConsent($user, ...)` | 659 | SKIP lub alternatywa |

**Decyzja wymagana od klienta PRZED implementacją:** Czy goście potrzebują audit trail consent RODO? Jeśli nie (SMS consent dotyczy tylko registered users, regulamin = legitimate interest) → 0 dodatkowej pracy. Jeśli tak → +2h (migration + model).

### Bottleneck #3: Inline AJAX Login — session regeneration

**Ryzyko: ŚREDNIE | Estymacja: 4h**

Edge cases:
- CSRF mismatch po zalogowaniu (frontend musi odświeżyć tokeny)
- Race condition: Alpine.js auto-save POST ze starym tokenem podczas logowania
- Duplikaty event listeners przy wielokrotnym otwieraniu/zamykaniu modalu

**Wpływ:** +1h max na debugowanie edge cases.

### Bottleneck #4: Email verification (custom flow)

**Ryzyko: NISKIE-ŚREDNIE | Estymacja: 3h (w ramach security 5h)**

Laravel `MustVerifyEmail` wymaga User modelu. Dla gości: signed URL + status `pending_verification` → `pending`. Laravel signed routes robią 80% pracy.

### Bottleneck #5: Testy — tight estimate

**Ryzyko: NISKIE-ŚREDNIE | Estymacja: 6h na 25-30 testów**

~12 min/test. Guest flow wymaga osobnych setup/teardown (brak User factory). Realne: 6-8h.

### Podsumowanie ryzyk

| Bottleneck | Estymacja | Max overflow |
|------------|-----------|-------------|
| Event listenery (3 crashują) | w 4h notifications | +0h jeśli świadomy |
| confirm() + GDPR consent | 8h | +2h jeśli klient chce audit trail gości |
| Inline AJAX login | 4h | +1h max |
| Email verification | 3h (w 5h) | +0h |
| Testy | 6h | +1-2h |
| **Łączne ryzyko overflow** | | **+2-4h (320-640 PLN netto)** |

**Verdykt:** Najgorszy realistyczny scenariusz to 66h → 70h. Nic nie ma potencjału do podwojenia budżetu.

---

## 7. Future-proofing

Architektura uwzględnia przyszłe rozszerzenia:
- **Multi-product orders** (upsell): guest checkout gotowy na wiele usług w jednej rezerwacji
- **Kody rabatowe**: kompatybilne z guest flow (bez wymagania user)
- **System faktur**: dane gościa (NIP) mogą być zebrane w booking wizard
- **Program lojalnościowy**: lazy registration tworzy naturalny funnel

---

## 8. Plan wdrożenia (po akceptacji wyceny)

1. Utworzyć dokumentację client-facing w ClickUp (subtask pod "Wycena - Q1 2026")
2. Po akceptacji klienta → feature branch `feature/guest-checkout`
3. Etap 1: Core MVP → PR do develop → staging → testy
4. Etap 2: Lazy registration → PR do develop → staging → testy
5. Release → main → production (z feature flag na pierwszych 10% ruchu)
6. Monitoring 1 tydzień → full rollout

---

## 9. Deliverables

### A. Dokumentacja wewnętrzna (internal)
- `docs/estimations/guest-checkout/wycena-szczegolowa.md` — ten dokument
- `docs/estimations/guest-checkout/architecture-analysis.md` — analiza coupling points

### B. Dokumentacja klienta (client-facing)
- ClickUp subtask: "[WYCENA] Rezerwacja bez zakładania konta" — wg formatu CLICKUP_TASK_GUIDELINES.md

### C. Implementacja (po akceptacji)
- Kod + testy + migracje
- Dokumentacja techniczna w `docs/features/`
