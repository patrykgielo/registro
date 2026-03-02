# Zarządzanie Dostępnością Pracowników

> ⚠️ **UWAGA: TEN DOKUMENT JEST PRZESTARZAŁY**
>
> **Data Wycofania:** 2025-12-12
>
> System **ServiceAvailability** (Dostępności) został **USUNIĘTY** z aplikacji.
>
> **Przyczyna:** Był to dead code, który tworzył kombinatoryczną eksplozję konfiguracji (350 ustawień dla 5 pracowników × 10 usług × 7 dni). System rezerwacji **NIE UŻYWAŁ** tego modelu od 2025-11-19.
>
> **✅ NOWY SYSTEM:**
> - Przeczytaj: **[Przewodnik Harmonogramów Pracowników](staff-scheduling-guide.md)**
> - Zarządzanie przez zakładki: **Harmonogramy / Wyjątki / Urlopy**
> - Redukcja konfiguracji: **350 → 30 kliknięć (91% mniej)**
>
> **Dokumentacja poniżej zachowana wyłącznie dla celów historycznych.**
>
> ---

## Przegląd (PRZESTARZAŁY SYSTEM)

System dostępności pracowników (Staff Availability) określa, **kiedy pracownicy mogą wykonywać konkretne usługi**. Dostępność jest podstawą kalendarza rezerwacji - bez ustawionych dostępności, klienci nie będą widzieć wolnych terminów.

## Dostęp do Panelu

Panel zarządzania dostępnością znajduje się w:
- **Menu Admin:** Harmonogramy → Dostępności Pracowników
- **URL:** `/admin/service-availabilities`

Alternatywnie, dostępności można zarządzać bezpośrednio przy edycji pracownika:
- **Menu Admin:** Zarządzanie Użytkownikami → Pracownicy → [Wybierz pracownika] → Zakładka "Dostępności"

## Podstawowe Pojęcia

### Czym jest Dostępność?

Dostępność to rekord definiujący:
- **Pracownik** - kto może wykonać usługę
- **Usługa** - jaką usługę może wykonać
- **Dzień tygodnia** - w który dzień (powtarza się co tydzień)
- **Godziny** - od kiedy do kiedy (np. 9:00-17:00)

**Przykład:**
```
Pracownik: Jan Kowalski
Usługa: Mycie Podstawowe
Dzień: Poniedziałek
Godziny: 09:00 - 17:00
```

Oznacza to: "Jan może robić Mycie Podstawowe w każdy poniedziałek od 9:00 do 17:00"

### Dlaczego to Ważne?

**Bez dostępności = brak wolnych terminów w kalendarzu!**

System rezerwacji (`AppointmentService`) wyszukuje wolne terminy tylko wśród pracowników, którzy mają ustawione dostępności dla danej usługi.

## Podstawowe Operacje

### Dodawanie Pojedynczej Dostępności

1. Przejdź do **Dostępności Pracowników** → Utwórz
2. Wybierz:
   - **Pracownik** z listy (tylko role 'staff')
   - **Usługa** którą pracownik może wykonywać
   - **Dzień tygodnia** (Pn-Nd)
   - **Godziny** rozpoczęcia i zakończenia
3. Kliknij **Utwórz**

**Walidacja:**
- System automatycznie wykryje nakładające się okna czasowe
- Godzina zakończenia musi być późniejsza niż rozpoczęcia
- Nie można ustawić tej samej dostępności dwukrotnie

### Edycja Dostępności

1. Znajdź dostępność na liście
2. Kliknij ikonę **Edytuj** (ołówek)
3. Zmień dane
4. Kliknij **Zapisz**

### Usuwanie Dostępności

**Pojedynczo:**
- Kliknij ikonę **Usuń** (kosz) przy dostępności

**Masowo:**
- Zaznacz wiele dostępności (checkbox)
- Kliknij **Usuń zaznaczone** u góry listy

## Zaawansowane Funkcje

### Ustaw Standardowy Harmonogram

**Zastosowanie:** Szybkie ustawienie harmonogramu dla wielu pracowników naraz (np. wszyscy pracują Pn-Pt 9-17)

**Jak użyć:**
1. Kliknij przycisk **Ustaw standardowy harmonogram** (na górze listy)
2. Wypełnij formularz:
   - **Pracownicy** - wybierz wielu pracowników (multiselect)
   - **Dni tygodnia** - zaznacz które dni (domyślnie Pn-Pt)
   - **Godziny pracy** - od kiedy do kiedy
   - **Usługi** - wybierz które usługi pracownicy będą mogli wykonywać
   - **Usuń istniejące** - opcjonalnie usuń stare dostępności przed dodaniem nowych
3. Kliknij **Ustaw harmonogram**

**Przykład użycia:**
```
Pracownicy: Jan Kowalski, Anna Nowak, Piotr Wiśniewski
Dni: Poniedziałek, Wtorek, Środa, Czwartek, Piątek
Godziny: 09:00 - 17:00
Usługi: Mycie Podstawowe, Mycie Premium, Detailing Wnętrza
```

System utworzy:
- 3 pracowników × 5 dni × 3 usługi = **45 dostępności** jednym kliknięciem

### Kopiuj Harmonogram

**Zastosowanie:** Skopiuj harmonogram jednego pracownika na innych (np. nowy pracownik ma taki sam harmonogram jak istniejący)

**Jak użyć:**
1. Kliknij przycisk **Kopiuj harmonogram** (na górze listy)
2. Wypełnij formularz:
   - **Pracownik źródłowy** - z kogo kopiować
   - **Pracownicy docelowi** - komu skopiować (multiselect)
   - **Kopiuj dla wszystkich usług** - wszystkie usługi lub wybrane
   - **Usuń istniejące** - opcjonalnie usuń stare dostępności docelowych pracowników
3. Kliknij **Kopiuj harmonogram**

**Przykład użycia:**
```
Źródło: Jan Kowalski (ma 15 dostępności)
Cel: Nowy Pracownik (ma 0 dostępności)
Opcje: Wszystkie usługi, Usuń istniejące
```

System skopiuje wszystkie 15 dostępności z Jana na Nowego Pracownika.

## Filtrowanie i Wyszukiwanie

### Dostępne Filtry

- **Pracownik** - pokaż dostępności konkretnego pracownika
- **Usługa** - pokaż dostępności dla konkretnej usługi
- **Dzień tygodnia** - pokaż dostępności dla konkretnego dnia

### Wyszukiwanie

- Wpisz nazwisko pracownika lub nazwę usługi w pole wyszukiwania
- System wyszuka w czasie rzeczywistym

### Sortowanie

Kliknij nagłówek kolumny aby sortować:
- Pracownik (alfabetycznie)
- Usługa (alfabetycznie)
- Dzień (numerycznie: Pn=1, Nd=0)
- Godziny (chronologicznie)

## Zarządzanie przez Pracownika

Alternatywnie, możesz zarządzać dostępnościami bezpośrednio w profilu pracownika:

1. Przejdź do **Pracownicy** → Wybierz pracownika → Kliknij **Edytuj**
2. Przejdź do zakładki **Dostępności** (na górze formularza)
3. Zobaczysz tabelę dostępności tylko tego pracownika
4. Kliknij **Dodaj dostępność** aby utworzyć nową
5. Użyj ikon **Edytuj** i **Usuń** przy każdej dostępności

**Zalety tego podejścia:**
- Widzisz wszystkie dane pracownika w jednym miejscu
- Pole "Pracownik" jest automatycznie uzupełnione
- Łatwiej zarządzać harmonogramem konkretnej osoby

## Najczęstsze Scenariusze

### Dodanie Nowego Pracownika

**Problem:** Nowy pracownik nie ma żadnych dostępności → brak terminów w kalendarzu

**Rozwiązanie 1 - Kopiowanie:**
1. Użyj **Kopiuj harmonogram**
2. Skopiuj harmonogram z podobnego pracownika

**Rozwiązanie 2 - Standardowy harmonogram:**
1. Użyj **Ustaw standardowy harmonogram**
2. Wybierz nowego pracownika + standardowe dni/godziny/usługi

**Rozwiązanie 3 - Ręczne:**
1. Edytuj pracownika → zakładka **Dostępności**
2. Dodaj dostępności ręcznie

### Zmiana Godzin Pracy

**Scenariusz:** Pracownik zmienia godziny z 9-17 na 10-18

**Rozwiązanie:**
1. Użyj filtra **Pracownik** aby znaleźć wszystkie jego dostępności
2. Zaznacz wszystkie (checkbox)
3. Usuń zaznaczone
4. Użyj **Ustaw standardowy harmonogram** z nowymi godzinami

### Urlop / Nieobecność

**Uwaga:** System obecnie nie wspiera jednorazowych nieobecności (urlop, święta).

**Tymczasowe rozwiązanie:**
- Usuń dostępności dla pracownika na czas urlopu
- Po powrocie dodaj je ponownie (użyj wcześniej zapisanego screenshota)

**TODO:** W przyszłości można dodać system wyjątków/nieobecności.

### Pracownik Wykonuje Tylko Wybrane Usługi

**Scenariusz:** Jan robi tylko Mycie Podstawowe i Premium, nie robi Detailingu

**Rozwiązanie:**
1. Dodaj dostępności tylko dla tych 2 usług
2. System automatycznie wyklucz Jana z rezerwacji Detailingu

## Debugowanie Problemów

### Brak Wolnych Terminów w Kalendarzu

**Przyczyny:**
1. Pracownicy nie mają dostępności dla tej usługi
2. Dostępności są ustawione na złe dni tygodnia
3. Dostępności są ustawione na złe godziny
4. Wszystkie terminy są już zarezerwowane

**Jak sprawdzić:**
1. Przejdź do **Dostępności Pracowników**
2. Użyj filtra **Usługa** → wybierz usługę której brakuje
3. Sprawdź czy są jakiekolwiek wyniki
4. Sprawdź czy dni i godziny są poprawne

### Nakładające się Okna Czasowe

**Problem:** System nie pozwala zapisać dostępności

**Przyczyna:** Już istnieje dostępność dla tego pracownika/usługi/dnia która nakłada się czasowo

**Przykład konfliktu:**
```
Istniejąca: Jan, Mycie, Poniedziałek, 09:00-17:00
Próba dodania: Jan, Mycie, Poniedziałek, 12:00-18:00  ❌ Nakładka 12:00-17:00
```

**Rozwiązanie:**
1. Usuń starą dostępność
2. Dodaj nową z poprawnymi godzinami
3. Lub podziel na nieprzekrywające się przedziały

### Pracownik Ma Dostępności ale Nie Pojawia Się w Kalendarzu

**Przyczyny:**
1. Pracownik nie ma roli 'staff'
2. Dostępności są dla innych usług niż wybrana
3. Cache przeglądarki (odśwież stronę Ctrl+F5)

**Jak sprawdzić:**
1. Edytuj pracownika → sprawdź czy ma rolę "Pracownik (Staff)"
2. Sprawdź zakładkę **Dostępności** - czy są dla właściwej usługi?

## Komendy CLI

### Sprawdzenie Pracowników Bez Dostępności

```bash
docker compose exec app php artisan staff:ensure-availability --check
```

Wyświetli listę pracowników którzy nie mają żadnych dostępności.

### Automatyczne Utworzenie Domyślnych Dostępności

```bash
docker compose exec app php artisan staff:ensure-availability --fix
```

Utworzy domyślne dostępności (Pn-Pt, 9:00-17:00, wszystkie usługi) dla pracowników którzy nie mają żadnych.

## API Reference

System dostępności wykorzystuje:
- **Model:** `App\Models\ServiceAvailability`
- **Resource:** `App\Filament\Resources\ServiceAvailabilityResource`
- **RelationManager:** `App\Filament\Resources\EmployeeResource\RelationManagers\ServiceAvailabilitiesRelationManager`
- **Service:** `App\Services\AppointmentService` (metody `getAvailableTimeSlots`, `checkStaffAvailability`)

## Zobacz Również

- [Booking System](../features/booking-system/README.md) - Jak system rezerwacji używa dostępności
- [ADR-004: Automatic Staff Assignment](../decisions/ADR-004-automatic-staff-assignment.md) - Decyzja architektoniczna o automatycznym przypisywaniu pracowników
- [Database Schema](../architecture/database-schema.md#service_availabilities) - Struktura tabeli `service_availabilities`
