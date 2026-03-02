# ClickUp Task Guidelines - Wyceny

**Data utworzenia:** 2025-12-26
**Ostatnia aktualizacja:** 2026-02-03
**Cel:** Zasady tworzenia tasków z wycenami w ClickUp

---

## Zasada Nadrzedna

**Task w ClickUp = kompletna wycena do podjecia decyzji.**

Klient powinien moc przeczytac task i:
- Wiedziec CO trzeba zbudowac (scope)
- Widziec ILE to kosztuje (godziny + PLN, opcje)
- Rozumiec CO dostaje w kazdej opcji (breakdown)
- Wiedziec jakie sa RYZYKA
- Podjac DECYZJE (A / B / razem z innym feature)
- Wiedziec co MUSI PRZYGOTOWAC ze swojej strony

**Task NIE jest:**
- Materialem edukacyjnym/sprzedazowym
- Dokumentacja techniczna (klasy, migracje, ściezki plikow)
- Miejscem na "pierdololo" o branzy/regulacjach — klient wie czego potrzebuje

---

## Ton i Styl

### Bezposredni, rzeczowy, implementacyjny

**DOBRZE:**
```markdown
## Co trzeba zbudowac

System fakturowania zintegrowany z Fakturownia.pl:

1. Automatyczne wystawianie faktur po zakonczeniu wizyty
2. Reczne wystawianie faktur z panelu admina
3. Wysylanie faktur B2B do KSeF
```

**ZLE — sprzedazowe pierdololo:**
```markdown
## Problem Biznesowy

Wiekszosc klientow car detailing to osoby fizyczne (bez NIP).
Osoby fizyczne sa wylaczone z obowiazkowego KSeF — faktury B2C
mozna wystawiac tradycyjnie. To znaczaco upraszcza implementacje
i pozwala skupic sie na tym co najwazniejsze...
```

**ZLE — tech jargon:**
```markdown
## Problem

System nie wspiera many-to-many relationship miedzy appointments i services.
Trzeba stworzyc pivot table appointment_services.
```

### Zasady tonu:
- NIE edukuj klienta o branzy/regulacjach — on wie
- NIE sprzedawaj — klient juz chce wycene, podaj fakty
- NIE upiększaj — zero marketingowego jezyka
- Kontekst biznesowy TYLKO tam gdzie wplywa na scope/koszty (np. "B2C = bez KSeF" bo wplywa na zakres prac)
- Szczegoly techniczne w breakdownie godzin — opisuj zadania zwiezle, zrozumiale

---

## Obowiazkowa Struktura Taska

### 1. Naglowek — co i za ile

```markdown
# [Nazwa Feature]

**Stawka:** 160 PLN/h netto (196,80 PLN/h brutto)
**Deadline:** [jesli jest]
**[Istotny kontekst]:** [1-2 zdania jesli wplywa na scope]
```

### 2. Co trzeba zbudowac

Lista funkcjonalnosci — CO klient dostaje, nie JAK to zrobisz.
Punkty numerowane, kazdy bold.

```markdown
## Co trzeba zbudowac

1. **Automatyczne wystawianie faktur** po zakonczeniu wizyty
2. **Reczne wystawianie faktur** z panelu admina
3. **Walidacja NIP** (algorytm checksumowy)
```

Jesli sa warianty zachowania (np. B2B vs B2C), dodaj krotka podsekcje:

```markdown
### Routing B2B / B2C

- **B2B (z NIP):** Faktura VAT -> automatycznie do KSeF
- **B2C (bez NIP):** Faktura tradycyjna (PDF) — bez KSeF
```

### 3. Opcje i koszty — tabelka przeglad

Kazda opcja w osobnej mini-tabelce. Polecana oznaczona.

```markdown
## Opcje i koszty

### Opcja A: Podstawowa integracja ⭐ POLECANE

| | |
|---|---|
| **Koszt netto** | **9 760 PLN** |
| **Koszt brutto** | **12 004,80 PLN** |
| **Godziny** | ~61h |
| **Czas realizacji** | ~4-5 tygodni |

### Opcja B: Pelna integracja

| | |
|---|---|
| **Koszt netto** | **13 120 PLN** |
| **Koszt brutto** | **16 137,60 PLN** |
| **Godziny** | ~82h |
| **Czas realizacji** | ~6-7 tygodni |
```

### 4. Roznice A vs B — tabela porownawcza

Jasna tabelka: co jest w A, co jest w B.

```markdown
## Roznice A vs B

| Funkcja | A | B |
|---------|---|---|
| Auto + reczne fakturowanie | ✅ | ✅ |
| KSeF automatycznie | ✅ | ✅ |
| Faktury korygujace | ❌ | ✅ |
| Wlasny email z faktura | ❌ | ✅ |
```

### 5. Szczegolowy breakdown godzin

Najwazniejsza sekcja wyceny. Trzy czesci:
- **Wspolna baza** — obowiazkowa w obu opcjach
- **Opcja A — dodatkowe zadania**
- **Opcja B — dodatkowe zadania**
- **QA, bugfixing, bufor**

Kazde zadanie w tabelce: numer, nazwa, godziny.

Poziom opisu zadan: **zwiezle ale konkretne** — klient rozumie CO robi kazdy punkt, nie widzi nazw klas/plików.

```markdown
## Szczegolowy breakdown godzin

### Wspolna baza — 25,5h

| # | Zadanie | h |
|---|---------|---|
| 1 | Tabela faktur w bazie (snapshot danych kupujacego) | 2 |
| 2 | Pole stawki VAT na uslugach | 0,5 |
| 3 | Walidacja NIP (algorytm checksumowy) | 1,5 |
| 4 | Klient API Fakturowni (retry, logowanie, obsluga bledow) | 3 |
| 5 | Booking flow: checkbox "Chce fakture" + pola NIP/firma | 3 |

### Opcja A — dodatkowe — 12h

| # | Zadanie | h |
|---|---------|---|
| A1 | Panel admina: lista faktur + podglad + PDF | 3 |
| A2 | Testy automatyczne | 5 |

### QA, bugfixing, bufor

| Kategoria | h |
|-----------|---|
| Manual QA (9 scenariuszy) | 8 |
| Bug fixing po QA | 5 |
| Contingency 15% | 6 (A) / 9 (B) |
| Wsparcie klienta | 4 |
```

### 6. Podsumowanie kosztow — tabela zbiorcza

Wszystko w jednej tabelce porownawczej + rekomendacja.

```markdown
## Podsumowanie kosztow

| | Opcja A | Opcja B |
|---|---|---|
| Dev | 38h | 56h |
| QA + bugfixing | 13h | 13h |
| Contingency | 6h | 9h |
| Wsparcie | 4h | 4h |
| **Lacznie** | **61h** | **82h** |
| **Netto** | **9 760 PLN** | **13 120 PLN** |
| **Brutto** | **12 004,80 PLN** | **16 137,60 PLN** |

### Rekomendacja

**Opcja A** — [krotkie uzasadnienie dlaczego, 2-4 punkty]
```

### 7. Synergia z innymi feature'ami (opcjonalne)

Jesli feature wspoldzieli prace z innym taskiem — pokaz oszczednosc.

```markdown
## Synergia z [Inny Feature]

Oba wymagaja [wspolny element]. Razem: **oszczednosc ~3h (480 PLN)**.

- Opcja A razem: **58h = 9 280 PLN netto**
- Opcja B razem: **79h = 12 640 PLN netto**
```

### 8. Ryzyka — tabela

```markdown
## Ryzyka

| Ryzyko | Prawdop. | Wplyw | Mitygacja |
|--------|----------|-------|-----------|
| API niedostepne | Niskie | Wysoki | Retry 3x, kolejka |
| Regresja booking | Srednie | Sredni | Testy, manual QA |
```

### 9. Do przygotowania (klient)

Co klient MUSI zrobic ze swojej strony zeby prace mogly ruszyc.

```markdown
## Do przygotowania (klient)

1. Uzupelnic dane sprzedawcy w Fakturownia.pl
2. Zalozyc konto testowe
3. Podac subdmomene i wygenerowac token API
```

### 10. Pytania przed startem

Rzeczy do ustalenia PRZED implementacja — konkretne, decyzyjne.

```markdown
## Pytania przed startem

1. **Ceny uslug** = netto czy brutto?
2. **Email z faktura** — Fakturownia czy system?
3. **Faktura proforma** — czy potrzebna?
```

### 11. Plan realizacji

Krotka lista krokow — bez tech checkpointow. Klient chce wiedziec KIEDY, nie JAK.

```markdown
## Plan realizacji

1. Akceptacja wyceny -> start prac
2. Konfiguracja konta testowego
3. Implementacja (migracje, serwisy, API)
4. Panel admin
5. QA + bugfixing
6. Deploy staging -> weryfikacja
7. Release -> produkcja
```

### 12. Decyzja — checkboxy na koniec

```markdown
## Decyzja

- [ ] **Opcja A** — 9 760 PLN netto (MVP, ~4-5 tyg.)
- [ ] **Opcja B** — 13 120 PLN netto (pelna, ~6-7 tyg.)
- [ ] **Realizacja razem z [Inny Feature]** (oszczednosc X PLN)

**Priorytet:** Wysoki
**Status:** Oczekuje na decyzje klienta
```

---

## Czego NIE umieszczac w tasku

### 1. Sprzedazowe / edukacyjne tresci

```markdown
# USUN:
Wiekszosc klientow car detailing to osoby fizyczne (bez NIP).
Osoby fizyczne sa wylaczone z obowiazkowego KSeF — faktury B2C
mozna wystawiac tradycyjnie (PDF/email). To znaczaco upraszcza...

# ZOSTAW (jesli wplywa na scope):
- B2C (bez NIP): Faktura tradycyjna — bez KSeF
- B2B (z NIP): Faktura VAT -> KSeF
```

Roznica: **1 linia faktu vs akapit edukacji.** Klient wie czym jest KSeF — podaj fakt, nie wyklad.

### 2. Stack techniczny / dependencje

```markdown
# USUN:
Stack: Laravel 12, Filament v4, DomPDF
Nowe zaleznosci: composer require abb/fakturownia
```

### 3. Struktura bazy / nazwy klas

```markdown
# USUN:
Tabela: invoices — id, fakturownia_id, gov_status, buyer_name...
Model: App\Models\Invoice z relacjami belongsTo(Appointment)
```

### 4. Sciezki plikow

```markdown
# USUN:
Pelna dokumentacja: /docs/estimations/fakturownia-integration/
Plan: /home/patrick/.claude/plans/floating-skipping-badger.md
```

### 5. Pseudokod / algorytmy

```markdown
# USUN:
if (buyer_company && nip) { gov_save_and_send = true; }
```

### 6. Timeline z tech checkpointami

```markdown
# USUN:
Faza 1: Backend (Dni 1-10, 40h) — Migration, Model, API, Tests
Checkpoint: API dziala, testy przechodza

# ZASTAP:
1. Implementacja (migracje, serwisy, API)
2. Panel admin
3. QA + bugfixing
```

---

## Breakdown godzin — poziom szczegolowosci

Najwazniejsza sekcja wyceny. Klient widzi za co placi.

### Dobry poziom (implementacyjny, bez kodu):

```markdown
| 8 | Walidacja NIP (algorytm checksumowy) | 1,5 |
| 9 | Klient API Fakturowni (retry 3x, logowanie, obsluga bledow) | 3 |
| 13 | Booking flow: checkbox "Chce fakture" + warunkowe pola NIP/firma/adres | 3 |
```

### Zly poziom (za techniczny):

```markdown
| 8 | NipValidator: checksum (algorytm wagowy 6,5,7,2,3,4,5,6,7) + Laravel Rule | 1,5 |
| 9 | FakturowniaClient wrapper (config, abb/fakturownia, retry 3x, logging) | 3 |
```

### Zly poziom (za ogolny):

```markdown
| 8 | Walidacja | 1,5 |
| 9 | Integracja | 3 |
```

**Zasada:** Klient rozumie CO robi kazdy punkt i dlaczego tyle trwa, ale nie widzi nazw klas/pakietow.

---

## Proces Tworzenia Wyceny

### KROK 1: Research + Analiza Techniczna

- Pelna analiza w `docs/estimations/[nazwa]/analiza-techniczna.md`
- Research API, dependencje, stan kodu, brakujace elementy
- Agent: `web-research-specialist` + `laravel-senior-architect`

### KROK 2: Wycena Szczegolowa

- Pelna wycena w `docs/estimations/[nazwa]/wycena-szczegolowa.md`
- Godziny per zadanie, opcje, QA, contingency, ryzyka
- Agent: `commercial-estimate-specialist` lub recznie

### KROK 3: Task ClickUp

- Przeniesc wycene do opisu taska
- Usunac nazwy klas, sciezki plikow, pseudokod
- Przetlumaczyc zadania na jezyk zrozumialy (zachowac godziny)
- Dodac: ryzyka, przygotowanie klienta, pytania, decyzje
- Struktura wg sekcji 1-12 powyzej

### KROK 4: Weryfikacja

- [ ] Czy klient rozumie CO trzeba zbudowac?
- [ ] Czy widzi ILE to kosztuje w kazdej opcji?
- [ ] Czy breakdown godzin jest konkretny ale bez tech jargonu?
- [ ] Czy roznice A vs B sa jasne w tabelce?
- [ ] Czy sa ryzyka z mitygacja?
- [ ] Czy klient wie co musi przygotowac?
- [ ] Czy pytania przed startem sa decyzyjne?
- [ ] Czy sekcja "Decyzja" na koncu daje jasne opcje?
- [ ] Czy usunalem WSZELKIE sciezki plikow, nazwy klas, pseudokod?
- [ ] Czy nie ma sprzedazowego / edukacyjnego "pierdololo"?

---

## Lessons Learned

### Blad #1: Sprzedazowe pierdololo (2026-02)

**Problem:** Opis taska zaczynal sie od edukacji o KSeF: "Wiekszosc klientow car detailing to osoby fizyczne. Osoby fizyczne sa wylaczone z obowiazkowego KSeF..."

**Feedback klienta:** "Nie jestem tutaj po to zeby sprzedawac jakies pierdololo klientowi. Skupiamy sie na wycenach, informacjach technicznych, a jezeli biznesowo chcemy cos powiedziec to tylko dookola implementacji."

**Lesson:** Klient wie czego potrzebuje. Nie edukuj go. Kontekst biznesowy TYLKO jesli wplywa na scope/koszty — i wtedy 1 linia faktu, nie akapit.

### Blad #2: Tech Jargon (2025-12)

**Problem:** "backward compatibility", "appointment_items table", "Redis lock"

**Lesson:** Klient placi za efekt. Breakdown ma byc konkretny ale w jezyku zrozumialym.

### Blad #3: Internal References (2025-12)

**Problem:** Linki do `/docs/estimations/...`, `/home/patrick/.claude/plans/...`

**Lesson:** Klient nie ma dostepu do repo. Task jest self-contained.

### Blad #4: Dual Pricing (2025-12)

**Problem:** Internal estimate (189h / 18,900 PLN) vs client estimate (64h / 6,400 PLN) w jednym tasku.

**Lesson:** ClickUp = TYLKO cena klienta. Internal w `/docs/`.

### Blad #5: Timeline z checkpointami (2025-12)

**Problem:** "Checkpoint: API dziala, testy przechodza"

**Lesson:** Klient chce wiedziec KIEDY. Wystarczy "QA + bugfixing" bez tech detali.

---

## Referencyjny Task — Fakturownia (2026-02)

**Task:** [86c7ynhf5](https://app.clickup.com/t/86c7ynhf5)

Ten task jest wzorcowy. Zawiera:
- Zwiezly scope (7 punktow + routing B2B/B2C)
- Dwie opcje w tabelkach z kosztami
- Tabele porownawcza A vs B
- Szczegolowy breakdown godzin (wspolna baza + per opcja + QA)
- Podsumowanie kosztow w jednej tabelce
- Synergie z innym feature (oszczednosc)
- Ryzyka z mitygacja
- Co klient musi przygotowac
- Pytania przed startem
- Plan realizacji (bez tech checkpointow)
- Checkboxy decyzji na koncu

Uzyj tego taska jako wzoru dla przyszlych wycen.

---

**Autor:** Claude (na prosbe Patryka)
**Zastosowanie:** Wszystkie przyszle wyceny w ClickUp
