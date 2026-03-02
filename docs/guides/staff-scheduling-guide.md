# Przewodnik: System Harmonogramów Pracowników (Option B)

Kompletny przewodnik po nowym systemie zarządzania dostępnością pracowników opartym na kalendarzu.

## 📋 Spis treści

1. [Przegląd systemu](#przegląd-systemu)
2. [Architektura](#architektura)
3. [Jak to działa](#jak-to-działa)
4. [Interfejs administracyjny](#interfejs-administracyjny)
5. [Przykłady użycia](#przykłady-użycia)
6. [FAQ](#faq)

## Przegląd systemu

### Problem ze starym systemem

Stary system używał prostego modelu `day_of_week` (0-6), który miał poważne ograniczenia:

❌ **Problemy:**
- Niemożliwość zaznaczenia POJEDYNCZEGO dnia wolnego (wizyta u lekarza, choroba)
- Brak obsługi urlopów (zakres dat)
- Brak wyjątków od harmonogramu
- Redundancja danych (40 rekordów dla 1 pracownika: 8 usług × 5 dni)
- Odznaczenie wtorku blokowało WSZYSTKIE wtorki na zawsze

### Nowe rozwiązanie (Option B)

✅ **Zalety:**
- **Kalendarz** zamiast tylko dni tygodnia
- **Wyjątki** - pojedyncze dni (np. 2025-12-24 wolne)
- **Urlopy** - zakresy dat (np. 2025-07-01 do 2025-07-14)
- **Elastyczność** - harmonogramy z datami obowiązywania
- **Deduplikacja** - jeden harmonogram Pn-Pt zamiast 40 rekordów

## Architektura

### 4 Tabele Bazy Danych

```
staff_schedules (Harmonogramy bazowe)
├── user_id             - Pracownik
├── day_of_week         - Dzień tygodnia (0-6)
├── start_time          - Od godziny
├── end_time            - Do godziny
├── effective_from      - Obowiązuje od (nullable)
├── effective_until     - Obowiązuje do (nullable)
└── is_active           - Aktywny

staff_date_exceptions (Wyjątki od harmonogramu)
├── user_id             - Pracownik
├── exception_date      - Data wyjątku
├── exception_type      - unavailable | available
├── start_time          - Od godziny (nullable - cały dzień)
├── end_time            - Do godziny (nullable - cały dzień)
└── reason              - Powód

staff_vacation_periods (Okresy urlopowe)
├── user_id             - Pracownik
├── start_date          - Data rozpoczęcia
├── end_date            - Data zakończenia
├── reason              - Powód
└── is_approved         - Czy zatwierdzony

service_staff (Pivot: Pracownik ↔ Usługi)
├── service_id          - ID usługi
└── user_id             - ID pracownika
```

### Priorytet sprawdzania dostępności

System sprawdza dostępność w tej kolejności:

1. **URLOP** (najwyższy priorytet)
   - Jeśli pracownik jest na urlopie → NIEDOSTĘPNY

2. **WYJĄTEK**
   - Jeśli istnieje wyjątek na ten dzień → zastosuj wyjątek
   - Typy: `unavailable` (nie pracuje) lub `available` (pracuje mimo że normalnie nie)

3. **HARMONOGRAM BAZOWY** (najniższy priorytet)
   - Sprawdź czy pracownik ma harmonogram na ten dzień tygodnia
   - Sprawdź `effective_from` i `effective_until`
   - Sprawdź czy `is_active = true`

## Jak to działa

### Scenariusz 1: Zwykły dzień pracy

**Dane:**
- Jan ma harmonogram: Poniedziałek 9:00-17:00
- Data: 2025-12-08 (poniedziałek) 10:00

**Sprawdzanie:**
1. ❌ Urlop? NIE
2. ❌ Wyjątek? NIE
3. ✅ Harmonogram bazowy? TAK → **DOSTĘPNY**

### Scenariusz 2: Dzień wolny (wyjątek)

**Dane:**
- Jan ma harmonogram: Poniedziałek 9:00-17:00
- Jan ma wyjątek: 2025-12-24 (wtorek) - unavailable - "Wigilia"
- Data: 2025-12-24 10:00

**Sprawdzanie:**
1. ❌ Urlop? NIE
2. ✅ Wyjątek? TAK (unavailable) → **NIEDOSTĘPNY**

### Scenariusz 3: Urlop

**Dane:**
- Jan ma harmonogram: Poniedziałek-Piątek 9:00-17:00
- Jan ma urlop: 2025-07-01 do 2025-07-14
- Data: 2025-07-05 10:00

**Sprawdzanie:**
1. ✅ Urlop? TAK → **NIEDOSTĘPNY** (najwyższy priorytet, blokuje wszystko)

### Scenariusz 4: Praca w normalnie wolny dzień

**Dane:**
- Jan NIE MA harmonogramu na sobotę
- Jan ma wyjątek: 2025-12-21 (sobota) - available 10:00-14:00 - "Sobota pracująca"
- Data: 2025-12-21 11:00

**Sprawdzanie:**
1. ❌ Urlop? NIE
2. ✅ Wyjątek? TAK (available 10:00-14:00) → **DOSTĘPNY**

## Interfejs administracyjny

### 1. Harmonogramy Bazowe (`/admin/staff-schedules`)

**Dodaj nowy harmonogram:**
1. Kliknij "Nowy Harmonogram bazowy"
2. Wybierz pracownika
3. Wybierz dzień tygodnia (Poniedziałek, Wtorek, ...)
4. Ustaw godziny (Od - Do)
5. **Opcjonalnie:** Ustaw daty obowiązywania
6. Zapisz

**Przykład:** Jan pracuje Pn-Pt 9:00-17:00
- Dodaj 5 harmonogramów (po jednym na każdy dzień)
- Wszystkie z tymi samymi godzinami

**Bulk Actions:**
- Aktywuj/Dezaktywuj zaznaczone - wyłącz harmonogramy bez usuwania

### 2. Wyjątki (`/admin/staff-date-exceptions`)

**Dodaj wyjątek:**
1. Kliknij "Nowy Wyjątek"
2. Wybierz pracownika
3. Wybierz datę
4. Wybierz typ:
   - **Niedostępny** - dzień wolny, choroba, wizyta
   - **Dostępny** - pracuje w normalnie wolny dzień
5. **Opcjonalnie:** Ustaw godziny (zostaw puste = cały dzień)
6. **Opcjonalnie:** Dodaj powód
7. Zapisz

**Przykłady:**
- Wizyta u lekarza: 2025-12-15, Niedostępny, 14:00-16:00
- Wigilia: 2025-12-24, Niedostępny, cały dzień
- Sobota pracująca: 2025-12-21, Dostępny, 10:00-14:00

### 3. Urlopy (`/admin/staff-vacation-periods`)

**Dodaj urlop:**
1. Kliknij "Nowy Urlop"
2. Wybierz pracownika
3. Wybierz daty (od - do)
4. **Opcjonalnie:** Dodaj powód ("Urlop wypoczynkowy")
5. Ustaw czy zatwierdzony
6. Zapisz

**Zatwierdzanie:**
- Akcja "Zatwierdź" przy pojedynczym urlopie
- Bulk Action "Zatwierdź zaznaczone" dla wielu
- Tylko zatwierdzone urlopy blokują dostępność

### 4. W edycji pracownika (`/admin/employees/{id}/edit`)

**Zakładki:**

**a) Usługi**
- Przypisz usługi, które pracownik może wykonywać
- Kliknij "Przypisz usługę"
- Wybierz z listy, zapisz

**b) Harmonogramy**
- Wszystkie harmonogramy bazowe tego pracownika
- Dodaj/edytuj/usuń inline
- Szybki przegląd: Pn-Pt 9:00-17:00

**c) Wyjątki**
- Wszystkie wyjątki tego pracownika
- Sortowane po dacie (najnowsze pierwsze)
- Badge: zielony (Dostępny) / czerwony (Niedostępny)

**d) Urlopy**
- Wszystkie urlopy tego pracownika
- Pokaż długość w dniach
- Status: Zaplanowany / Trwa / Zakończony
- Akcja "Zatwierdź" bezpośrednio

## Przykłady użycia

### Przykład 1: Nowy pracownik Jan

**Krok 1: Przypisz usługi**
1. Edytuj pracownika Jan
2. Zakładka "Usługi" → Przypisz usługę
3. Wybierz: "Detailing wewnętrzny", "Korekta lakieru"

**Krok 2: Ustaw harmonogram bazowy**
1. Zakładka "Harmonogramy" → Dodaj harmonogram
2. Poniedziałek 9:00-17:00 → Zapisz
3. Powtórz dla Wt, Śr, Cz, Pt

Lub przez `/admin/staff-schedules`:
- Nowy harmonogram × 5 (każdy dzień osobno)

**Krok 3: Dodaj pierwszy urlop**
1. Zakładka "Urlopy" → Dodaj urlop
2. 2025-07-01 do 2025-07-14
3. Powód: "Urlop wypoczynkowy"
4. Zatwierdź: TAK

**Rezultat:**
- Jan pracuje Pn-Pt 9:00-17:00
- Jan może wykonywać 2 usługi
- Jan niedostępny w lipcu 2025 (2 tygodnie)

### Przykład 2: Choroba Janka

**Problem:** Janek zachorował 2025-12-10 (wtorek)

**Rozwiązanie:**
1. `/admin/staff-date-exceptions` → Nowy wyjątek
2. Pracownik: Janek
3. Data: 2025-12-10
4. Typ: Niedostępny
5. Powód: "Choroba - grypa"
6. Zapisz

**Rezultat:**
- Tylko ten JEDEN wtorek zablokowany
- Wszystkie inne wtorki bez zmian
- Klienci nie zobaczą tego dnia w kalendarzu

### Przykład 3: Sobota pracująca przed świętami

**Problem:** 21 grudnia (sobota) wyjątkowo pracujemy 10:00-14:00

**Rozwiązanie:**
1. `/admin/staff-date-exceptions` → Nowy wyjątek
2. Pracownik: (wszyscy którzy będą pracować)
3. Data: 2025-12-21
4. Typ: **Dostępny** ← WAŻNE!
5. Od godziny: 10:00
6. Do godziny: 14:00
7. Zapisz

**Rezultat:**
- Sobota 21.12 dostępna dla klientów
- Tylko godziny 10:00-14:00
- Normalne soboty dalej niedostępne

### Przykład 4: Zmiana harmonogramu od przyszłego miesiąca

**Problem:** Od stycznia 2026 Jan przechodzi na Pn-Cz (bez piątków)

**Rozwiązanie Option A - Nowe harmonogramy z datami:**
1. Obecne harmonogramy (Pn-Pt):
   - Edytuj każdy
   - Ustaw "Obowiązuje do": 2025-12-31
2. Nowe harmonogramy (Pn-Cz):
   - Dodaj 4 nowe (bez piątku)
   - Ustaw "Obowiązuje od": 2026-01-01

**Rozwiązanie Option B - Dezaktywacja + nowe:**
1. Obecny harmonogram piątków:
   - Edytuj
   - Wyłącz "Aktywny"
2. Lub po prostu usuń piątki

**Rezultat:**
- Od stycznia 2026 Jan nie pracuje w piątki
- Stare dane zachowane (audyt)

## FAQ

### Q: Czy mogę usunąć stare harmonogramy?
A: TAK, ale lepiej:
- Ustaw `is_active = false` (soft disable)
- LUB ustaw `effective_until` (historyczne)
- Zachowujesz historię dla audytu

### Q: Co się stanie jeśli wyjątek koliduje z urlopem?
A: **Urlop ma NAJWYŻSZY priorytet** - pracownik będzie niedostępny niezależnie od wyjątków.

### Q: Czy mogę mieć różne godziny w ten sam dzień?
A: TAK - możesz mieć wiele harmonogramów na ten sam dzień z różnymi godzinami (np. 9-12 i 14-17 z przerwą obiadową).

### Q: Jak zaznaczyć urlop niezatwierdzony?
A: Dodaj urlop z `is_approved = false`. System NIE zablokuje dostępności dopóki nie zatwierdzisz.

### Q: Co z starymi danymi?
A: **Automatyczna migracja:**
- 40 starych rekordów → deduplikowane harmonogramy
- Przypisania usług przeniesione do pivot table
- ZERO strat danych
- Stara tabela `service_availabilities` dalej istnieje (backup)

### Q: Czy mogę wrócić do starego systemu?
A: Technicznie TAK (rollback migracji), ale NOWY system jest o wiele lepszy. Stary Resource (`/admin/service-availabilities`) dalej działa dla kompatybilności.

### Q: Jak sprawdzić dostępność w kodzie?
A: Użyj `StaffScheduleService`:

```php
use App\Services\StaffScheduleService;

$staffScheduleService = app(StaffScheduleService::class);
$isAvailable = $staffScheduleService->isStaffAvailable($user, $dateTime);
```

### Q: Gdzie jest logika sprawdzania?
A: `app/Services/StaffScheduleService.php`
- Metoda: `isStaffAvailable()`
- Priorytet: Vacation → Exception → Base Schedule
- Integracja z `AppointmentService`

## Techniczne

### Modele

```php
StaffSchedule::forUser($userId)
    ->forDay($dayOfWeek)
    ->active()
    ->effectiveOn($date)
    ->get();

StaffDateException::forUser($userId)
    ->onDate($date)
    ->unavailable() // or ->available()
    ->get();

StaffVacationPeriod::forUser($userId)
    ->approved()
    ->includesDate($date)
    ->exists();

$user->services; // BelongsToMany
$service->staff; // BelongsToMany
```

### Service Methods

```php
// Check if staff available at specific date/time
$staffScheduleService->isStaffAvailable(User $staff, Carbon $dateTime): bool

// Check if staff can perform service
$staffScheduleService->canPerformService(User $staff, int $serviceId): bool

// Get available time slots
$staffScheduleService->getAvailableSlots(User $staff, Carbon $date, int $duration): array

// Get available staff for service
$staffScheduleService->getAvailableStaffForService(int $serviceId, Carbon $dateTime): Collection
```

## Podsumowanie

✅ **Zalety nowego systemu:**
- Kalendarz zamiast tylko dni tygodnia
- Wyjątki na pojedyncze dni
- Urlopy z zatwierdzaniem
- Elastyczne harmonogramy z datami
- Deduplikacja danych (90% mniej rekordów)
- Intuicyjny interfejs polski

🎯 **Najlepsze praktyki:**
1. Ustaw bazowe harmonogramy (Pn-Pt 9-17)
2. Przypisz usługi które pracownik wykonuje
3. Dodawaj wyjątki tylko gdy potrzebne (choroba, wizyta)
4. Urlopy zatwierdzaj po akceptacji
5. Używaj dat obowiązywania dla zmian harmonogramu

📖 **Zobacz też:**
- [CLAUDE.md](../../CLAUDE.md) - Konfiguracja projektu
- [Database Schema](../architecture/database-schema.md) - Struktura bazy
- [Staff Availability (OLD)](./staff-availability.md) - Stary system (deprecated)
