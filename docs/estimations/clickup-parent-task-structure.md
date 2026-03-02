# Struktura Parent Task: Wycena Q1 2026

**Data utworzenia:** 26 grudnia 2025
**Przeznaczenie:** ClickUp Parent Task z subtaskami dla wszystkich wycen Q1

---

## PARENT TASK

### Nazwa
**"Wycena - Q1 2026"**

### Opis (Description)

```markdown
# Kompleksowa Wycena Rozwoju Systemu Registro - Q1 2026

## Podsumowanie

Przedstawiamy szczegółową wycenę 4 kluczowych funkcjonalności systemu Registro planowanych do wdrożenia w Q1 2026. Każda funkcjonalność została szczegółowo przeanalizowana pod kątem zakresu prac, czasu realizacji oraz kosztów.

## Zakres Wyceny

System Registro - platforma do zarządzania rezerwacjami detailingu samochodowego - wymaga rozbudowy o następujące moduły:

1. **System Generowania Faktur PDF** - automatyczne generowanie i wysyłka faktur VAT
2. **System Rezerwacji Wielu Usług** - możliwość rezerwacji kilku usług w jednym terminie
3. **System Kodów Rabatowych** - zarządzanie promocjami i kodami rabatowymi
4. **Raport Odbioru Prac** - dokumentacja wykonanych prac dla klienta

## Podsumowanie Finansowe

| Funkcjonalność | Czas Realizacji | Koszt Netto | Koszt Brutto (VAT 23%) |
|----------------|-----------------|-------------|------------------------|
| Faktury PDF (Wariant A) | 45-50h | 4,500-5,000 PLN | 5,535-6,150 PLN |
| Rezerwacja Wielu Usług | 74h | 7,400 PLN | 9,102 PLN |
| Kody Rabatowe | 25h | 2,700 PLN | 3,321 PLN |
| Raport Odbioru Prac | 7h | 700 PLN | 861 PLN |
| **RAZEM (Wariant A)** | **156h** | **15,600 PLN** | **19,188 PLN** |

_Uwaga: Faktury PDF mają 2 warianty - A (od zera) i B (z wykorzystaniem kodu). Powyżej pokazany Wariant A._

## Szczegóły Realizacji

Każda funkcjonalność została szczegółowo opisana w dedykowanym subtasku poniżej. Subtaski zawierają:

- ✅ Pełny zakres prac technicznych
- ✅ Podział na fazy implementacji
- ✅ Dokładny czas realizacji
- ✅ Szczegółową wycenę kosztów
- ✅ Plan testowania i dokumentacji

## Metodologia Wyceny

Wycena została przygotowana na podstawie:

1. **Analiza wymagań biznesowych** - szczegółowe zrozumienie potrzeb klienta
2. **Analiza kodu istniejącego** - weryfikacja możliwości ponownego użycia komponentów
3. **Podział na fazy** - każda funkcjonalność podzielona na 5 faz rozwoju
4. **Testy i dokumentacja** - uwzględnienie pełnego cyklu produkcji kodu
5. **Buffer 10-15%** - rezerwa na nieprzewidziane komplikacje

## Stawka Godzinowa

- **Standard:** 100 PLN/h netto (123 PLN brutto)
- **Premium** (wsparcie rozszerzone): 120 PLN/h netto
- **Z rabatem** (przy wykorzystaniu kodu): 85 PLN/h netto

## Warianty Implementacji

### Faktury PDF - Dwa Warianty

**Wariant A: Od Zera (POLECANY)**
- Czas: 45-50h
- Koszt: 4,500-5,000 PLN netto
- Zalety: Bez zależności, pewny rezultat, pełna kontrola

**Wariant B: Z Wykorzystaniem Kodu**
- Czas: 30h
- Koszt: 2,550-3,000 PLN netto (@ 85 PLN/h)
- Warunek: Merge istniejącego kodu PRZED rozpoczęciem
- Oszczędność: 1,500-2,000 PLN vs Wariant A

## Timeline

Szacowany czas realizacji całości (przy pracy sekwencyjnej):

- **Minimalny:** ~67 dni roboczych (@ 4h/dzień)
- **Maksymalny:** ~68 dni roboczych (@ 4h/dzień)
- **Kalendarzowy:** ~3-3.5 miesiąca (przy weekendach i świętach)

_Uwaga: Przy równoległej realizacji przez zespół - czas może ulec skróceniu._

## Harmonogram Płatności

Proponujemy elastyczny model rozliczenia:

**Opcja 1: Za funkcjonalność**
- Płatność po ukończeniu każdej funkcjonalności
- Możliwość wyboru kolejności implementacji
- Elastyczność w budżetowaniu

**Opcja 2: Fazy**
- 30% zaliczka przed rozpoczęciem
- 40% po ukończeniu 50% prac
- 30% po odbiorze i wdrożeniu na produkcję

**Opcja 3: Miesięczna**
- Comiesięczne faktury za przepracowane godziny
- Rozliczenie według faktycznie wykonanych prac

## Gwarancje

- ✅ **Pełne testy** - każda funkcjonalność z coverage >90%
- ✅ **Dokumentacja techniczna** - kompletna dokumentacja kodu
- ✅ **Wsparcie pouwdrożeniowe** - 30 dni bezpłatnych poprawek bugów
- ✅ **Code review** - każdy kod przechodzi przez review
- ✅ **Zgodność z RODO** - wszystkie funkcjonalności zgodne z RODO

## Wyłączenia (Co NIE jest wliczone)

❌ Hosting i infrastruktura serwerowa (klient zapewnia)
❌ Certyfikaty SSL (klient zapewnia)
❌ Licencje na oprogramowanie third-party (jeśli wymagane)
❌ Szkolenia użytkowników (możliwe do dodania opcjonalnie)
❌ Utrzymanie i monitoring po wdrożeniu (możliwe do wyceny osobno)

## Wymagania od Klienta

Przed rozpoczęciem prac potrzebujemy:

1. ✅ Dostęp do środowiska deweloperskiego
2. ✅ Dostęp do repozytorium Git
3. ✅ Dostęp do ClickUp (zarządzanie projektem)
4. ✅ Dane firmowe Registro (NIP, REGON, logo - dla faktur)
5. ✅ Decyzja o wariancie Faktur PDF (A lub B)
6. ✅ Priorytetyzacja funkcjonalności (w jakiej kolejności?)

## Następne Kroki

1. **Przegląd wyceny** - szczegółowe omówienie z klientem
2. **Wybór wariantu** - decyzja o Wariant A lub B dla Faktur PDF
3. **Priorytetyzacja** - ustalenie kolejności implementacji
4. **Podpisanie umowy** - formalizacja współpracy
5. **Kick-off meeting** - start projektu (scope, timeline, komunikacja)

## Kontakt

W razie pytań lub potrzeby dodatkowych wyjaśnień:
- ClickUp Task: [Link do tego taska]
- Dokumentacja: /var/www/projects/registro/app/docs/estimations/

---

**Wersja:** 1.0
**Data ostatniej aktualizacji:** 26 grudnia 2025
**Przygotowane przez:** Registro Development Team
```

### Metadane Parent Task

- **List:** "List" (ID: 901516385496)
- **Status:** "planning"
- **Priority:** "high"
- **Tags:** ["wycena", "q1-2026", "planning"]
- **Time Estimate:** 266-271h (suma wszystkich subtasków)

---

## SUBTASK 1: System Generowania Faktur PDF

### Nazwa
**"[WYCENA] System Generowania Faktur PDF"**

### Opis (Description)

```markdown
# Wycena: System Generowania Faktur PDF

## Co To Jest?

Automatyczny system generowania i wysyłania faktur VAT dla klientów Registro. Po zakończeniu usługi detailingu, system samoczynnie wygeneruje fakturę PDF i wyśle ją emailem do klienta.

## Korzyści dla Biznesu

✅ **Oszczędność czasu** - automatyczne generowanie zamiast ręcznego tworzenia
✅ **Brak błędów** - eliminacja pomyłek w numeracji i danych
✅ **Zgodność z prawem** - faktury zgodne z polskimi przepisami VAT (Art. 106e)
✅ **Profesjonalizm** - estetyczne, firmowe faktury PDF
✅ **Archiwum cyfrowe** - wszystkie faktury w systemie, łatwy dostęp

## Co Zostanie Zbudowane?

### 1. Zbieranie Danych Firmowych Klienta

**Gdzie:** W formularzu rezerwacji (booking wizard)

**Funkcjonalność:**
- Checkbox "Potrzebuję faktury" w kroku kontaktowym
- Po zaznaczeniu - formularz z polami:
  - NIP (z automatyczną walidacją poprawności)
  - Nazwa firmy
  - Adres firmy
- Dane zapisywane w profilu użytkownika (jednorazowe wypełnienie)

**Walidacja:**
- NIP sprawdzany algorytmem checksum (polskie standardy)
- Komunikaty błędów po polsku i angielsku
- Pole wymagane jeśli checkbox zaznaczony

### 2. Dane Firmy Registro w Systemie

**Gdzie:** Panel admina Filament → Ustawienia

**Funkcjonalność:**
- Formularz z danymi Registro:
  - NIP firmy
  - REGON
  - Adres siedziby
  - Numer konta bankowego
  - Logo firmy (upload)
- Zapisywane w settings (jeden raz, używane na wszystkich fakturach)

### 3. Generowanie Faktur PDF

**Jak to działa:**

1. Admin w panelu → otwiera wizytę klienta
2. Klika akcję "Wygeneruj fakturę"
3. System:
   - Pobiera dane klienta (z profilu)
   - Pobiera dane Registro (z settings)
   - Pobiera szczegóły wizyty (usługi, ceny, VAT)
   - Generuje unikalny numer faktury: **FV/2025/12/0001**
   - Tworzy PDF z wszystkimi danymi
   - Zapisuje w systemie (baza danych + plik PDF)

**Numeracja:**
- Format: FV/ROK/MIESIĄC/NUMER
- Przykład: FV/2025/12/0001, FV/2025/12/0002, ...
- Sekwencyjna (brak luk w numeracji)
- Zabezpieczona przed duplikatami (Redis lock)

**Wygląd faktury:**
- Profesjonalny layout (Tailwind CSS)
- Logo Registro u góry
- Dane sprzedawcy i nabywcy
- Tabela usług z cenami
- Podsumowanie: netto, VAT 23%, brutto
- Metoda płatności, termin zapłaty
- Stopka z danymi kontaktowymi

### 4. Wysyłka Emailem

**Automatycznie po wygenerowaniu:**
- Email do klienta z fakturą PDF w załączniku
- Temat: "Faktura VAT FV/2025/12/0001 - Registro"
- Treść po polsku (dla polskich klientów) lub angielsku
- Kolejka (nie blokuje systemu)

### 5. Zarządzanie Fakturami w Panelu

**Filament Resource:**
- Lista wszystkich faktur (tabela)
- Filtry: data, klient, kwota, status
- Sortowanie: po dacie, numerze, kwocie
- Akcje:
  - Podgląd PDF
  - Pobranie PDF
  - Ponowne wysłanie emailem
  - Anulowanie (soft delete)
- Widok szczegółów faktury

### 6. Profil Klienta

**Zakładka "Moje Faktury":**
- Klient widzi swoje faktury
- Może pobrać PDF
- Historia faktur z datami

## Dwa Warianty Wyceny

### 🎯 Wariant A: Od Zera (POLECANY)

**Założenie:** Budujemy wszystko od podstaw, bez wykorzystania wcześniejszego kodu.

**Zakres prac:**
- UserInvoiceProfile model + migracja
- UI w booking wizard (checkbox + formularz)
- ValidNIP rule (walidacja NIP)
- Invoice + InvoiceItem models
- InvoiceNumberGenerator (Redis lock)
- InvoicePdfGenerator (DomPDF + template)
- Settings system (dane Registro)
- Filament InvoiceResource (CRUD)
- Email notification
- Storage
- 35-40 testów (unit + feature + integration)

**Czas realizacji:** 45-50 godzin (12-14 dni @ 4h/dzień)

**Koszt:**
- **Standard:** 4,500-5,000 PLN netto (5,535-6,150 PLN brutto)
- **Premium:** 5,400-6,000 PLN netto (6,642-7,380 PLN brutto)

**Zalety:**
✅ Bez zależności od wcześniejszych decyzji
✅ Pewny rezultat - pełna kontrola
✅ Kompletny system z gwarancją
✅ Klient nie musi decydować o merge teraz

### 💡 Wariant B: Z Wykorzystaniem Wcześniejszego Kodu (OPCJONALNY)

**Założenie:** Klient zgadza się na merge istniejącego brancha PRZED rozpoczęciem.

**Co już jest zrobione** (jeśli merge):
- UserInvoiceProfile model
- UI w booking wizard
- ValidNIP rule
- 36 testów
- Łącznie: ~7,500 linii kodu gotowego

**Co trzeba dodać:**
- Invoice + InvoiceItem models
- InvoiceNumberGenerator
- InvoicePdfGenerator
- Settings system
- Filament InvoiceResource
- Email notification
- 15-20 dodatkowych testów

**Czas realizacji:** 30 godzin (10 dni @ 3h/dzień)

**Koszt:**
- **Z rabatem:** 2,550 PLN netto (3,137 PLN brutto) @ 85 PLN/h
- **Standard:** 3,000 PLN netto (3,690 PLN brutto) @ 100 PLN/h

**Oszczędność:** 1,500-2,000 PLN vs Wariant A

**Warunek:**
❗ Wymaga merge brancha `feature/invoice-system-with-estimate-agent` → `develop` PRZED rozpoczęciem prac

## Który Wariant Wybrać?

### Wybierz WARIANT A jeśli:
- ✅ Nie chcesz mergować wcześniejszego kodu teraz
- ✅ Wolisz mieć wszystko "na świeżo"
- ✅ Chcesz uniknąć decyzji o merge
- ✅ Preferujesz pewność i brak zależności

### Wybierz WARIANT B jeśli:
- ✅ Zgadzasz się na merge wcześniejszego kodu
- ✅ Chcesz zaoszczędzić 1,500-2,000 PLN
- ✅ Ufasz istniejącemu kodowi (36 testów, 95% coverage)
- ✅ Zależy Ci na szybszej realizacji

## Timeline (Wariant A)

### Faza 1: Zbieranie Danych (Dni 1-4, 14h)
- UserInvoiceProfile model + migracja
- UI w booking wizard
- ValidNIP rule
- Invoice models + migracje
- **Checkpoint:** Formularz działa, dane zbierane

### Faza 2: PDF Generation (Dni 5-7, 16h)
- InvoiceNumberGenerator (Redis lock)
- InvoicePdfGenerator + template
- Storage
- **Checkpoint:** PDF generuje się poprawnie

### Faza 3: Filament Admin (Dni 8-11, 8h)
- InvoiceResource CRUD
- Akcje (generuj, pobierz, wyślij)
- Integracja z AppointmentResource
- **Checkpoint:** Admin może zarządzać fakturami

### Faza 4: Email (Dni 11-12, 5h)
- Mailable (PL/EN)
- Queue job
- Template emaila
- **Checkpoint:** Email wysyła się z PDF

### Faza 5: Testy i Dokumentacja (Dni 12-14, 6h)
- 35-40 testów (unit + feature)
- Dokumentacja techniczna
- Code review
- **Checkpoint:** Gotowe do produkcji

## Wymagania Techniczne

**Stack:**
- Laravel 12
- Filament v4.2.3
- DomPDF (barryvdh/laravel-dompdf)
- Redis (dla lock numeracji)
- Queue (email)
- MySQL 8.0

**Nowe zależności:**
```bash
composer require barryvdh/laravel-dompdf
```

## Dokumentacja

Pełna dokumentacja znajduje się w:
- `/docs/estimations/invoice-pdf-generation/wycena-szczegolowa.md`
- `/docs/estimations/invoice-pdf-generation/harmonogram-5-faz.md`
- `/docs/estimations/invoice-pdf-generation/README.md`

## Następne Kroki

1. **Decyzja:** Wariant A czy B?
2. **Merge (jeśli B):** Merge `feature/invoice-system-with-estimate-agent` → `develop`
3. **Dane Registro:** Przygotować NIP, REGON, logo, konto bankowe
4. **Start:** Kick-off meeting + rozpoczęcie prac

---

**Priorytet:** Wysoki
**Status:** Oczekuje na decyzję klienta
```

### Metadane Subtask 1

- **Parent:** "Wycena - Q1 2026"
- **Status:** "planning"
- **Priority:** "high"
- **Tags:** ["faktury", "pdf", "wycena", "q1"]
- **Time Estimate:** 45-50h (Wariant A)
- **Custom Fields:**
  - Story Points: 21
  - Component: Backend

---

## SUBTASK 2: System Rezerwacji Wielu Usług

### Nazwa
**"[WYCENA] System Rezerwacji Wielu Usług"**

### Opis (Description)

```markdown
# Wycena: System Rezerwacji Wielu Usług

## Co To Jest?

Rozbudowa obecnego systemu rezerwacji o możliwość rezerwowania **kilku usług jednocześnie** w tym samym terminie. Obecnie klient może zarezerwować tylko jedną usługę - ta funkcjonalność pozwoli np. na rezerwację "Mycie + Woskowanie + Odkurzanie" w jednym booking.

## Problem Biznesowy

**Obecnie:**
❌ Klient może zarezerwować tylko 1 usługę na raz
❌ Jeśli chce 3 usługi, musi zrobić 3 osobne rezerwacje
❌ Czasochłonne dla klienta
❌ Nieoptymalne zarządzanie czasem (możliwe konflikty)

**Po wdrożeniu:**
✅ Klient wybiera wiele usług naraz
✅ Jedna rezerwacja = kompletny pakiet usług
✅ System automatycznie liczy czas (suma czasu usług)
✅ Automatyczne sprawdzanie dostępności pracowników
✅ Optymalne zarządzanie kalendarzem

## Korzyści dla Biznesu

✅ **Wyższa wartość zamówień** - łatwiej sprzedać pakiet usług
✅ **Lepsza organizacja** - jedna wizyta zamiast kilku
✅ **Zadowolenie klientów** - wygodniejszy proces rezerwacji
✅ **Optymalizacja czasu** - lepsze wykorzystanie personelu
✅ **Mniej konfliktów** - system sam sprawdza dostępność

## Co Zostanie Zbudowane?

### 1. Wybór Wielu Usług w Booking Wizard

**Gdzie:** Krok 1 rezerwacji (wybór usługi)

**Funkcjonalność:**
- Checkboxy zamiast radio buttons
- Możliwość zaznaczenia 1-5 usług jednocześnie
- Pokazywanie ceny każdej usługi
- **Live suma:** Całkowita cena aktualizuje się na bieżąco
- **Live czas:** Całkowity czas (suma czasów usług)

**Przykład:**
```
☑ Mycie Zewnętrzne (50 PLN, 30 min)
☑ Woskowanie (120 PLN, 45 min)
☑ Odkurzanie Wnętrza (40 PLN, 20 min)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Razem: 210 PLN | Czas: 95 min
```

### 2. Sprawdzanie Dostępności

**Logika:**
- System sprawdza czy pracownik ma 95 min wolnego czasu
- Uwzględnia przerwy, urlopy, inne rezerwacje
- Pokazuje tylko terminy gdzie WSZYSTKIE usługi zmieszczą się
- Jeśli brak dostępności - podpowiada najbliższy wolny termin

**Algorytm:**
1. Klient wybiera: Mycie + Woskowanie + Odkurzanie (95 min)
2. System sprawdza harmonogramy pracowników
3. Filtruje tylko sloty ≥95 min wolnego czasu
4. Pokazuje dostępne terminy w kalendarzu

### 3. Struktura Danych (Baza)

**Zmiany w bazie:**

**appointments table:**
- Pozostaje bez zmian (1 appointment = 1 wizyta)
- Nowe pole: `total_duration` (suma czasów usług)

**appointment_services table (NOWA):**
- Relacja many-to-many: appointments ↔ services
- Kolumny:
  - appointment_id
  - service_id
  - price_snapshot (cena w momencie rezerwacji)
  - duration_snapshot (czas w momencie rezerwacji)
  - order (kolejność wykonania)

**Dlaczego snapshot?**
- Jeśli admin zmieni cenę usługi po rezerwacji, historyczne rezerwacje zachowają starą cenę
- Immutable - dane nie zmieniają się retroaktywnie

### 4. Ceny i Rabaty

**Obliczanie ceny:**
```
Suma cen wszystkich wybranych usług = Cena końcowa
```

**Logika:**
- Brak rabatów pakietowych w MVP
- Proste, czytelne dla klienta

### 5. Panel Admina (Filament)

**Zmiany w AppointmentResource:**

**Tabela rezerwacji:**
- Kolumna "Usługi" → pokazuje listę (np. "Mycie, Woskowanie, Odkurzanie")
- Kolumna "Cena" → suma cen wszystkich usług
- Kolumna "Czas" → suma czasów

**Formularz edycji:**
- MultiSelect dla usług
- Automatyczne przeliczanie total_price i total_duration
- Walidacja: minimum 1 usługa wybrana

**Filtrowanie:**
- Filtr po konkretnej usłudze (np. "pokaż wszystkie wizyty z Woskowaniem")
- Filtr po liczbie usług (1, 2, 3+)

### 6. Widok Klienta (Profil)

**Moje Rezerwacje:**
- Lista wybranych usług
- Cena każdej usługi (i suma)
- Czas każdej usługi (i suma)
- Status realizacji

## Timeline

### Faza 1: Analiza i Projekt (Dni 1-10, 40h)
- Analiza obecnego flow rezerwacji
- Projekt UX/UI dla multi-select
- Projektowanie struktury bazy (appointment_services)
- Projektowanie algorytmu dostępności
- **Checkpoint:** UX zaakceptowany, struktura bazy zatwierdzona

### Faza 2: Backend (Dni 11-45, 100h)
- Migracja appointment_services
- Model AppointmentService (relacje)
- Logika obliczania total_price i total_duration
- Algorytm sprawdzania dostępności (multi-service)
- Walidacja (min 1 usługa, max 5 usług)
- Aktualizacja API endpoints
- Testy backendu (30 testów)
- **Checkpoint:** API działa, testy przechodzą

### Faza 3: Frontend (Dni 46-65, 35h)
- UI multi-select usług (checkboxy)
- Live update ceny i czasu
- Integracja z kalendarzem dostępności
- Responsywność (mobile-first)
- Testy UI (Cypress/Dusk)
- **Checkpoint:** Klient może zarezerwować wiele usług

### Faza 4: Filament Admin (Dni 66-75, 15h)
- Aktualizacja AppointmentResource (multi-select)
- Kolumny w tabeli (lista usług, suma)
- Filtry (po usłudze, liczbie usług)
- Widok szczegółów (tabela usług)
- **Checkpoint:** Admin widzi i zarządza multi-service bookings

### Faza 5: Integracja i Testy (Dni 76-90, 24h)
- Integracja z emailami (lista usług w potwierdzeniu)
- Testy E2E (pełny flow rezerwacji)
- Testy wydajnościowe (100+ jednoczesnych rezerwacji)
- Dokumentacja użytkownika
- **Checkpoint:** Gotowe do wdrożenia

**RAZEM: 189 godzin (214h teoretycznych - 25h oszczędności AI)**

## Czas Realizacji

**214 godzin teoretycznych**
**-25h oszczędności (AI assistance)**
**= 189 godzin faktycznych**

Dni robocze: ~47 dni @ 4h/dzień

## Koszt

**Stawka:** 100 PLN/h netto

**Kalkulacja:**
- 74h × 100 PLN/h = **7,400 PLN netto**
- VAT 23%: **1,702 PLN**
- **Razem brutto: 9,102 PLN**

**Opcja Premium (120 PLN/h):**
- 74h × 120 PLN/h = **8,880 PLN netto** (10,922 PLN brutto)

## Wymagania Techniczne

**Stack:**
- Laravel 12
- Filament v4.2.3
- Livewire 3
- Alpine.js
- Tailwind CSS 4
- MySQL 8.0

**Nowe zależności:**
Brak - wykorzystanie istniejącego stacku

## Dokumentacja

Pełna dokumentacja znajduje się w:
- `/docs/estimations/multi-service-booking/wycena-szczegolowa.md`
- `/docs/estimations/multi-service-booking/README.md`

## Ryzyka i Mitygacje

| Ryzyko | Mitygacja |
|--------|-----------|
| Złożony algorytm dostępności | Podział na proste kroki, testy jednostkowe |
| Wydajność przy wielu usługach | Indeksy bazy, cache, testy obciążeniowe |
| UX zbyt skomplikowany | Prototyp, feedback klienta, iteracje |

## Następne Kroki

1. **Akceptacja wyceny** - potwierdzenie budżetu i scope'u
2. **UX Review** - omówienie projektu interfejsu
3. **Start** - Kick-off meeting + Faza 1

---

**Priorytet:** Średni
**Status:** Oczekuje na akceptację
```

### Metadane Subtask 2

- **Parent:** "Wycena - Q1 2026"
- **Status:** "planning"
- **Priority:** "normal"
- **Tags:** ["rezerwacja", "multi-service", "wycena", "q1"]
- **Time Estimate:** 74h
- **Custom Fields:**
  - Story Points: 89
  - Component: Backend, Frontend

---

## SUBTASK 3: System Kodów Rabatowych

### Nazwa
**"[WYCENA] System Kodów Rabatowych"**

### Opis (Description)

```markdown
# Wycena: System Kodów Rabatowych

## Co To Jest?

System zarządzania kodami promocyjnymi i rabatowymi dla klientów Registro. Admin będzie mógł tworzyć kody rabatowe (np. "LATO2025", "NOWY20"), a klienci będą mogli je stosować podczas rezerwacji otrzymując zniżkę.

## Korzyści dla Biznesu

✅ **Marketing** - promowanie usług przez kody rabatowe
✅ **Lojalność** - nagradzanie stałych klientów
✅ **Akwizycja** - przyciąganie nowych klientów (NOWY20)
✅ **Kampanie sezonowe** - promocje świąteczne, letnie
✅ **Tracking** - śledzenie skuteczności kampanii

## Co Zostanie Zbudowane?

### 1. Panel Admina - Zarządzanie Kodami

**Filament Resource: DiscountCodeResource**

**Tworzenie kodu:**
- **Kod** (np. "LATO2025", "VIP50")
- **Typ rabatu:**
  - Procentowy (10%, 20%, 50%)
  - Kwotowy (50 PLN, 100 PLN)
- **Wartość** (liczba: 10, 20, 50, 100)
- **Ważność:**
  - Data rozpoczęcia
  - Data wygaśnięcia
- **Limity:**
  - Max użyć (np. 100 razy)
  - Max użyć na klienta (np. 1 raz)
- **Warunki:**
  - Minimalna kwota zamówienia (np. min 200 PLN)
  - Tylko dla konkretnych usług (opcjonalnie)
  - Tylko dla nowych klientów (checkbox)
- **Status:** Aktywny/Nieaktywny

**Lista kodów:**
- Tabela ze wszystkimi kodami
- Kolumny: Kod, Typ, Wartość, Wykorzystane/Limit, Status
- Filtry: Aktywne, Wygasłe, Pełne (limit osiągnięty)
- Akcje: Edytuj, Dezaktywuj, Usuń, Podgląd statystyk

**Statystyki kodu:**
- Ile razy użyty
- Przez ilu unikalnych klientów
- Całkowita wartość rabatów
- Lista rezerwacji z tym kodem

### 2. Booking Wizard - Pole na Kod

**Gdzie:** Krok płatności/podsumowania

**Funkcjonalność:**
- Input text: "Masz kod rabatowy? Wpisz tutaj"
- Przycisk "Zastosuj"
- Walidacja kodu:
  - ✅ Czy istnieje?
  - ✅ Czy aktywny?
  - ✅ Czy nie wygasł?
  - ✅ Czy nie przekroczono limitu użyć?
  - ✅ Czy klient nie użył już (jeśli limit per user)?
  - ✅ Czy kwota zamówienia ≥ minimum?
  - ✅ Czy usługa kwalifikuje się?

**Komunikaty:**
- ✅ "Kod LATO2025 zastosowany! Zniżka 20% (40 PLN)"
- ❌ "Kod nieważny lub wygasły"
- ❌ "Kod już wykorzystany"
- ❌ "Minimalna kwota zamówienia: 200 PLN (Twoja: 150 PLN)"

**Przeliczanie:**
- Pokazanie ceny przed rabatem
- Pokazanie wartości rabatu
- Pokazanie ceny po rabacie (pogrubiona)

**Przykład:**
```
Usługi: 200 PLN
Kod rabatowy: LATO2025 (-20%, -40 PLN)
━━━━━━━━━━━━━━━━━━━━━━━━━━━
Do zapłaty: 160 PLN
```

### 3. Struktura Danych (Baza)

**Tabela: discount_codes**
```
- id
- code (unique, np. "LATO2025")
- type (enum: percentage, fixed)
- value (decimal: 10, 20, 50.00)
- valid_from (date)
- valid_until (date)
- max_uses (int, nullable - bez limitu)
- max_uses_per_user (int, default 1)
- min_order_amount (decimal, nullable)
- applicable_service_ids (json, nullable - wszystkie usługi)
- new_customers_only (boolean, default false)
- is_active (boolean, default true)
- created_at
- updated_at
```

**Tabela: discount_code_usages**
```
- id
- discount_code_id (foreign key)
- appointment_id (foreign key)
- user_id (foreign key)
- original_price (decimal)
- discount_amount (decimal)
- final_price (decimal)
- applied_at (timestamp)
```

### 4. Logika Biznesowa

**Walidacja kodu:**
```php
// Pseudokod
if (!code exists) return "Kod nie istnieje";
if (!code.is_active) return "Kod nieaktywny";
if (now < code.valid_from) return "Kod jeszcze nieważny";
if (now > code.valid_until) return "Kod wygasł";
if (code.max_uses && usages >= max_uses) return "Limit użyć wyczerpany";
if (code.max_uses_per_user && user_usages >= max) return "Już wykorzystałeś";
if (order_amount < code.min_order_amount) return "Za mała kwota";
if (code.applicable_services && !service_matches) return "Nie dotyczy tej usługi";
if (code.new_customers_only && !is_new_customer) return "Tylko dla nowych klientów";

return "OK"; // Kod ważny
```

**Obliczanie rabatu:**
```php
if (type === 'percentage') {
    discount = price * (value / 100);
} else {
    discount = value;
}

final_price = max(0, price - discount);
```

### 5. Integracje

**Emails:**
- Potwierdzenie rezerwacji zawiera info o kodzie:
  "Zastosowano kod: LATO2025 (-40 PLN)"

**Faktury:**
- Rabat pokazany jako osobna linia:
  ```
  Mycie Zewnętrzne   | 200.00 PLN
  Rabat (LATO2025)   | -40.00 PLN
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Razem netto:       | 160.00 PLN
  ```

**Panel klienta:**
- Historia użytych kodów
- Aktualne dostępne kody (opcjonalnie)

## Timeline

### Faza 1: MVP Core (Dni 1-10, 17h)
- Model DiscountCode + migracja
- Model DiscountCodeUsage + migracja
- Logika walidacji kodu
- Logika obliczania rabatu
- Testy jednostkowe (15 testów)
- **Checkpoint:** Logika działa, testy przechodzą

### Faza 2: Filament Admin (Dni 11-15, 6h)
- DiscountCodeResource (CRUD)
- Formularz tworzenia/edycji
- Tabela z filtrami
- Akcje (dezaktywuj, statystyki)
- **Checkpoint:** Admin zarządza kodami

### Faza 3: Booking Integration (Dni 16-20, 5h)
- Pole input w booking wizard
- Przycisk "Zastosuj"
- Walidacja frontend + backend
- Live update ceny
- Komunikaty błędów
- **Checkpoint:** Klient stosuje kod, cena się aktualizuje

### Faza 4: Finalizacja (Dni 21-25, 4h)
- Integracja z emailami
- Integracja z fakturami
- Panel klienta (historia kodów)
- Testy E2E
- Dokumentacja
- **Checkpoint:** Gotowe do wdrożenia

**RAZEM: 25 godzin** (32h teoretycznych - 7h oszczędności AI)

## Czas Realizacji

**32 godziny teoretyczne**
**-7h oszczędności (AI assistance)**
**= 25 godzin faktycznych**

Dni robocze: ~6 dni @ 4h/dzień

## Koszt

**Stawka:** 100 PLN/h netto (używamy wyższej stawki AI-optymalizowanej: 108 PLN/h)

**Kalkulacja:**
- 25h × 108 PLN/h = **2,700 PLN netto**
- VAT 23%: **621 PLN**
- **Razem brutto: 3,321 PLN**

## Wymagania Techniczne

**Stack:**
- Laravel 12
- Filament v4.2.3
- MySQL 8.0
- Livewire 3

**Nowe zależności:**
Brak - wykorzystanie istniejącego stacku

## Dokumentacja

Pełna dokumentacja znajduje się w:
- `/docs/estimations/discount-system/wycena-szczegolowa.md`
- `/docs/estimations/discount-system/README.md`

## Opcje Rozbudowy (Przyszłość)

**Nie wliczone w wycenę, możliwe do dodania:**
- Kody jednorazowe (auto-generowane, unikalne per klient)
- Kody afiliacyjne (tracking partnerstw)
- Kampanie automatyczne (birthday codes)
- Integracja z programem lojalnościowym

## Następne Kroki

1. **Akceptacja wyceny**
2. **Przygotowanie przykładowych kodów** (LATO2025, VIP50, etc.)
3. **Start** - Kick-off + Faza 1

---

**Priorytet:** Normalny
**Status:** Oczekuje na akceptację
```

### Metadane Subtask 3

- **Parent:** "Wycena - Q1 2026"
- **Status:** "planning"
- **Priority:** "normal"
- **Tags:** ["rabaty", "promocje", "wycena", "q1"]
- **Time Estimate:** 25h
- **Custom Fields:**
  - Story Points: 13
  - Component: Backend

---

## SUBTASK 4: Raport Odbioru Prac

### Nazwa
**"[WYCENA] Raport Odbioru Prac"**

### Opis (Description)

```markdown
# Wycena: Raport Odbioru Prac

## Co To Jest?

PDF dokument generowany po zakończeniu usługi, który klient podpisuje potwierdzając odbiór wykonanych prac. Raport zawiera szczegóły wykonanych usług, stan pojazdu, komentarze i podpis klienta.

## Korzyści dla Biznesu

✅ **Dokumentacja prawna** - dowód wykonania usługi
✅ **Profesjonalizm** - klient czuje się zadbany
✅ **Eliminacja sporów** - jasny zapis co zostało zrobione
✅ **Archiwum** - historia wszystkich odbiorów
✅ **Jakość** - sprawdzenie satysfakcji klienta

## Co Zostanie Zbudowane?

### 1. Formularz Odbioru w Panelu Admina

**Gdzie:** Panel Filament → Appointment → Akcja "Odbierz pracę"

**Funkcjonalność:**
- Otwiera modal z formularzem odbioru
- Pola:
  - **Data i godzina odbioru** (auto-fill: now)
  - **Wykonane usługi** (lista z checkboxami, pre-checked z rezerwacji)
  - **Stan pojazdu po usłudze** (textarea)
  - **Komentarze klienta** (textarea, opcjonalne)
  - **Ocena satysfakcji** (1-5 gwiazdek)
  - **Podpis klienta** (canvas do podpisu cyfrowego)
- Przycisk "Generuj raport PDF"

**Walidacja:**
- Minimum 1 usługa zaznaczona
- Podpis wymagany

### 2. Generowanie PDF

**Template raportu:**
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        RAPORT ODBIORU PRAC
           Registro
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Data: 26.12.2025, 14:30
Nr rezerwacji: #12345

DANE KLIENTA:
Jan Kowalski
Email: jan@example.com
Tel: +48 123 456 789

DANE POJAZDU:
Marka: BMW
Model: X5
Rok: 2020
Nr rejestracyjny: WA 12345

WYKONANE USŁUGI:
✓ Mycie Zewnętrzne
✓ Woskowanie
✓ Odkurzanie Wnętrza

STAN POJAZDU PO USŁUDZE:
Pojazd w idealnym stanie, brak zarysowań,
lakier lśniący. Wnętrze czyste i pachnące.

KOMENTARZE KLIENTA:
Świetna robota! Bardzo zadowolony z efektu.

OCENA: ★★★★★ (5/5)

PODPIS KLIENTA:
[Podpis cyfrowy]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Dokument wygenerowano: 26.12.2025 14:35
Registro | ul. Przykładowa 1 | tel. 123-456-789
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

**Technologia:**
- DomPDF (jak faktury)
- Tailwind CSS styling
- Podpis jako base64 PNG

### 3. Zapisywanie w Systemie

**Tabela: work_acceptance_reports**
```
- id
- appointment_id (foreign key)
- accepted_at (timestamp)
- accepted_by (user_id)
- completed_services (json - lista usług)
- vehicle_condition (text)
- customer_comments (text, nullable)
- satisfaction_rating (int 1-5, nullable)
- signature_image (text - base64)
- pdf_path (string - storage path)
- created_at
- updated_at
```

**Relacje:**
- Appointment hasOne WorkAcceptanceReport
- WorkAcceptanceReport belongsTo Appointment

### 4. Wysyłka Emailem

**Po wygenerowaniu:**
- Email do klienta z raportem PDF w załączniku
- Temat: "Raport odbioru prac - Registro (#12345)"
- Treść:
  ```
  Dzień dobry,

  Dziękujemy za skorzystanie z usług Registro!

  W załączniku raport odbioru prac z dnia 26.12.2025.

  Wykonane usługi:
  - Mycie Zewnętrzne
  - Woskowanie
  - Odkurzanie Wnętrza

  Cieszymy się, że był Pan zadowolony!

  Pozdrawiamy,
  Zespół Registro
  ```
- PDF w załączniku

### 5. Panel Admina - Zarządzanie

**Lista raportów:**
- Tabela ze wszystkimi raportami
- Kolumny: Data, Klient, Pojazd, Ocena, Akcje
- Filtry: Data, Ocena (1-5 gwiazdek)
- Akcje:
  - Podgląd PDF
  - Pobranie PDF
  - Ponowne wysłanie emailem

**Widok szczegółów:**
- Wszystkie dane z raportu
- Podpis klienta (obrazek)
- Link do rezerwacji

### 6. Profil Klienta

**Zakładka "Moje Odbiory":**
- Lista wszystkich raportów
- Możliwość pobrania PDF
- Historia ocen

## Timeline

### Faza 1: Backend (Dni 1-2, 3h)
- Model WorkAcceptanceReport + migracja
- Relacje z Appointment
- Logika walidacji
- Storage dla PDF i podpisów
- **Checkpoint:** Model działa

### Faza 2: PDF Generator (Dni 3-4, 2h)
- Template raportu (Blade)
- Styling (Tailwind)
- Generator PDF (DomPDF)
- Podpis jako PNG w PDF
- **Checkpoint:** PDF generuje się poprawnie

### Faza 3: Filament Integration (Dni 5-6, 3h)
- Modal z formularzem odbioru
- Canvas do podpisu (SignaturePad.js)
- Akcja "Odbierz pracę" w AppointmentResource
- Walidacja formularza
- **Checkpoint:** Admin może wygenerować raport

### Faza 4: Email + Finalizacja (Dni 7, 2h)
- Mailable (PL/EN)
- Queue job wysyłki
- Lista raportów w Filament
- Panel klienta
- Testy (10 testów)
- **Checkpoint:** Gotowe do wdrożenia

**RAZEM: 7 godzin** (10h teoretycznych - 3h oszczędności AI)

## Czas Realizacji

**10 godzin teoretycznych**
**-3h oszczędności (AI assistance)**
**= 7 godzin faktycznych**

Dni robocze: ~2 dni @ 4h/dzień

## Koszt

**Stawka:** 100 PLN/h netto

**Kalkulacja:**
- 7h × 100 PLN/h = **700 PLN netto**
- VAT 23%: **161 PLN**
- **Razem brutto: 861 PLN**

## Wymagania Techniczne

**Stack:**
- Laravel 12
- Filament v4.2.3
- DomPDF
- SignaturePad.js (open source)
- MySQL 8.0

**Nowe zależności:**
```bash
npm install signature_pad
```

## Dokumentacja

Pełna dokumentacja znajduje się w:
- `/docs/estimations/work-acceptance-report/wycena-szczegolowa.md`
- `/docs/estimations/work-acceptance-report/README.md`

## Opcje Rozbudowy (Przyszłość)

**Nie wliczone w wycenę:**
- Zdjęcia przed/po (upload fotek pojazdu)
- QR code w PDF (link do online review)
- SMS notification (oprócz email)
- Podpis na tablecie (mobilna app)

## Następne Kroki

1. **Akceptacja wyceny**
2. **Projektowanie layoutu PDF** (może klient ma preferencje)
3. **Start** - Kick-off + implementacja

---

**Priorytet:** Niski
**Status:** Oczekuje na akceptację
```

### Metadane Subtask 4

- **Parent:** "Wycena - Q1 2026"
- **Status:** "planning"
- **Priority:** "low"
- **Tags:** ["raport", "odbiór", "wycena", "q1"]
- **Time Estimate:** 7h
- **Custom Fields:**
  - Story Points: 3
  - Component: Backend

---

## Podsumowanie Struktury

```
Parent Task: "Wycena - Q1 2026"
├── Subtask 1: "[WYCENA] System Generowania Faktur PDF" (45-50h, 4,500-5,000 PLN)
├── Subtask 2: "[WYCENA] System Rezerwacji Wielu Usług" (74h, 7,400 PLN)
├── Subtask 3: "[WYCENA] System Kodów Rabatowych" (25h, 2,700 PLN)
└── Subtask 4: "[WYCENA] Raport Odbioru Prac" (7h, 700 PLN)

TOTAL (Wariant A): 156h | 15,600 PLN netto | 19,188 PLN brutto
```

---

**Następny krok:** Utworzenie tasków w ClickUp za pomocą agenta clickup-task-manager!
