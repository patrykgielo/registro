# Wycena: System Karty Stałego Klienta

**Data:** 2026-02-03
**Task ClickUp:** [86c78c52b](https://app.clickup.com/t/86c78c52b)
**Wersja:** 2.0 (zaktualizowana po nowych wymaganiach klienta)

---

## 1. Co trzeba zbudować

System lojalnościowy z automatycznymi poziomami rabatowymi, kartami dla klientów i pełną integracją z procesem rezerwacji.

**Główne elementy:**
- Automatyczne poziomy karty (np. Brązowa → Srebrna → Złota)
- Rabat naliczany automatycznie przy rezerwacji
- Profil klienta z postępem do następnego poziomu
- Panel admina do zarządzania progami i kartami
- Grafika karty do wydruku

---

## 2. Co otrzyma klient

### Dla właściciela firmy:

**Panel administracyjny:**
- Konfigurator poziomów karty — sam ustalasz progi wydatków i wysokość rabatów (np. po 500 PLN = 5%, po 2000 PLN = 10%, po 5000 PLN = 15%)
- Lista wszystkich kart klientów — widzisz kto ma jaką kartę, ile wydał, jaki ma rabat
- Podgląd klienta — pełna historia: ile wydał, kiedy awansował na wyższy poziom, ile mu brakuje do następnego
- Statystyki programu — ilu klientów uczestniczy, ile rabatów udzielono (opcja B)

**Automatyzacja:**
- System sam aktywuje kartę po pierwszej wizycie klienta
- System sam podnosi poziom karty gdy klient przekroczy próg wydatków
- System sam nalicza rabat przy każdej rezerwacji (bez Twojej ingerencji)
- Powiadomienia email do klientów: "Gratulacje! Twoja karta awansowała na poziom Gold"

**Karty fizyczne:**
- Gotowa grafika karty do wydruku (z imieniem klienta, kodem, poziomem)
- Każdy poziom ma inny design (np. brązowy/srebrny/złoty)
- Kod QR na karcie (opcja B) — klient skanuje i widzi swój profil

---

### Dla klientów końcowych:

**Przy rezerwacji online:**
- Zalogowany klient od razu widzi swój rabat — nie musi nic wpisywać
- Wyraźna informacja "Oszczędzasz 45 PLN dzięki Karcie Gold (15%)"
- Cena przed i po rabacie widoczna w podsumowaniu
- Goście mogą wpisać kod z karty fizycznej

**W profilu klienta:**
- Widzi swój aktualny poziom karty (np. "Karta Silver")
- Widzi ile wydał i ile brakuje do następnego poziomu
- Pasek postępu pokazujący jak blisko jest awansu
- Może pobrać/wydrukować swoją kartę

**Komunikacja:**
- Email po aktywacji karty: "Witaj w programie! Masz 5% rabatu na wszystkie usługi"
- Email po awansie: "Gratulacje! Twój rabat wzrósł do 10%"
- (Opcja B) Powiadomienia o zbliżeniu do progu: "Jeszcze 200 PLN i zdobywasz Kartę Gold!"

---

### Podsumowanie korzyści:

| Dla właściciela | Dla klientów |
|-----------------|--------------|
| Klienci wracają częściej | Czują się docenieni |
| Wyższe średnie koszyki | Motywacja do wydawania więcej |
| Zero ręcznej pracy | Automatyczny rabat bez wpisywania kodów |
| Pełna kontrola nad progami | Przejrzysty postęp do następnego poziomu |
| Profesjonalne karty do druku | Fizyczna karta = prestiż |

---

## 3. Opcje i koszty

| | Opcja A: MVP | Opcja B: Full |
|---|---|---|
| Dev | 50h | 67h |
| QA + bugfixing | 11h | 13h |
| Contingency 15% | 8h | 10h |
| Wsparcie | 3h | 3h |
| **Łącznie** | **72h** | **93h** |
| **Netto** | **11 520 PLN** | **14 880 PLN** |
| **Brutto** | **14 169.60 PLN** | **18 302.40 PLN** |

Stawka: 160 PLN/h netto + 23% VAT

---

## 4. Różnice Opcja A vs B

| Funkcja | Opcja A (MVP) | Opcja B (Full) |
|---------|---------------|----------------|
| Automatyczne poziomy i rabaty | ✅ | ✅ |
| Rabat widoczny przy rezerwacji | ✅ | ✅ |
| Panel admina z konfiguratorem | ✅ | ✅ |
| Profil klienta z postępem | ✅ | ✅ (rozbudowany) |
| Grafika karty do druku | ✅ (prosta) | ✅ (PDF z QR) |
| Powiadomienia email | ✅ (podstawowe) | ✅ (pełne + milestones) |
| Kod QR na karcie | ❌ | ✅ |
| Śledzenie wydanych kart fizycznych | ❌ | ✅ |
| Ochrona przed spadkiem poziomu (3 mies.) | ❌ | ✅ |
| Statystyki programu w panelu | ❌ | ✅ |
| Eksport danych | ❌ | ✅ |

---

## 5. Szczegółowy breakdown godzin

### Wspólna baza — 30h

| # | Zadanie | h |
|---|---------|---|
| 1 | Baza danych: tabela poziomów karty | 1 |
| 2 | Baza danych: tabela kart klientów | 1 |
| 3 | Baza danych: historia zmian poziomów | 0.5 |
| 4 | Baza danych: pola rabatowe na rezerwacjach | 1 |
| 5 | Logika: modele danych | 3 |
| 6 | Logika: rozszerzenie klienta i rezerwacji | 1.5 |
| 7 | Logika: obliczanie wydatków (ostatnie 12 miesięcy) | 3 |
| 8 | Logika: auto-aktywacja karty po pierwszej wizycie | 1.5 |
| 9 | Logika: auto-awans/degradacja poziomu | 2 |
| 10 | Logika: generowanie i walidacja kodów | 2 |
| 11 | **Rezerwacja: pełny UX rabatu lojalnościowego** | **7.5** |
| | ↳ Auto-wykrywanie karty przy rezerwacji | (0.5) |
| | ↳ Auto-naliczanie rabatu (bez wpisywania kodu) | (1) |
| | ↳ Badge poziomu karty (widoczny cały czas) | (1.5) |
| | ↳ Osobna linia rabatu w podsumowaniu ceny | (1) |
| | ↳ Banner "Oszczędzasz X PLN" | (1) |
| | ↳ Zachowanie rabatu przez cały proces | (0.5) |
| | ↳ Aktualizacja ceny gdy zmienia się koszyk | (1) |
| | ↳ Pole kodu (backup dla gości) | (1) |
| 12 | Rezerwacja: zapis rabatu na wizycie | 1.5 |
| 13 | Automatyzacja: po wizycie → nalicz wydatki, sprawdź poziom | 2 |
| 14 | Automatyzacja: miesięczne sprawdzanie poziomów | 1.5 |
| 15 | Konfiguracja systemu | 0.5 |

### Opcja A: MVP — 20h dodatkowe

| # | Zadanie | h |
|---|---------|---|
| A1 | Admin: zarządzanie poziomami (progi, rabaty, kolory) | 2.5 |
| A2 | Admin: lista kart, podgląd, przyznawanie | 3 |
| A3 | Admin: zakładka lojalnościowa w profilu klienta | 2 |
| A4 | Admin: kolumny rabatowe w rezerwacjach | 1 |
| A5 | Profil klienta: sekcja lojalnościowa (badge, progress, kod) | 3.5 |
| A6 | Grafika karty: prosta wersja HTML do druku | 2 |
| A7 | Powiadomienia: aktywacja karty, zmiana poziomu | 2 |
| A8 | Testy automatyczne | 5 |

### Opcja B: Full — 37h dodatkowe

| # | Zadanie | h |
|---|---------|---|
| B1 | Admin: rozbudowane zarządzanie poziomami + drag & drop | 3 |
| B2 | Admin: pełne zarządzanie kartami, bulk, statystyki, eksport | 4 |
| B3 | Admin: pełna zakładka (wykres wydatków, historia) | 3 |
| B4 | Admin: badge lojalnościowy przy rezerwacjach | 1.5 |
| B5 | Profil klienta: pełny dashboard (progress, historia, milestones) | 5 |
| B6 | PDF karty: profesjonalny design per poziom, z QR | 4 |
| B7 | Kod QR: link do profilu klienta | 1.5 |
| B8 | Śledzenie kart fizycznych: daty wydania, 6-mies. polityka | 2 |
| B9 | Powiadomienia: pełny zestaw (milestones 50/75/90%, ostrzeżenie o degradacji) | 3 |
| B10 | Ochrona poziomu: 3-miesięczny okres ochronny | 2 |
| B11 | Widget statystyk w panelu admina | 2 |
| B12 | Testy kompletne | 7 |

---

## 6. Synergie z innymi funkcjonalnościami

| Funkcjonalność | Synergia |
|----------------|----------|
| **Fakturownia** | Rabat automatycznie na fakturze ("Rabat Karta Gold -45 PLN") |
| **Dynamic Vehicle Pricing** | Wspólne pole ceny końcowej — oszczędność ~2h |
| **Multi-Service Booking** | Rabat na cały koszyk (wiele usług) |

---

## 7. Ryzyka

| Ryzyko | Prawdop. | Wpływ | Jak zapobiec |
|--------|----------|-------|--------------|
| Błędy w procesie rezerwacji | Średnie | Średni | Testy automatyczne, QA manualne |
| Błędne obliczanie poziomu | Średnie | Wysoki | Testy jednostkowe na edge cases |
| Wolne ładowanie (dużo wizyt) | Niskie | Średni | Indeksy w bazie, cache |
| Konflikt z cenami pojazdów | Niskie | Średni | Rabat lojalnościowy po cenie pojazdu |
| Problemy z generowaniem PDF | Średnie | Niski | Fallback na HTML |

---

## 8. Do przygotowania przez klienta

- [ ] Lista poziomów karty (nazwy, kolory)
- [ ] Progi wydatków dla każdego poziomu
- [ ] Procent rabatu dla każdego poziomu
- [ ] Regulamin programu lojalnościowego (wymagany prawnie — RODO)
- [ ] Logo/grafika do kart (opcjonalnie)

---

## 9. Pytania do klienta

1. ~~Czy oprócz karty stałego klienta mają być też osobne kody promocyjne?~~ → **Odpowiedź: Nie, tylko karta**
2. ~~Kiedy aktywuje się karta — od razu po rejestracji czy po pierwszej wizycie?~~ → **Odpowiedź: Po pierwszej wizycie**
3. ~~Czy grafika karty ma być tylko online czy też do druku?~~ → **Odpowiedź: Oba**
4. ~~Ceny są netto czy brutto?~~ → **Odpowiedź: Brutto**
5. Ile poziomów karty planujecie? (sugerowane: 3-4)
6. Jakie nazwy poziomów? (np. Brązowa/Srebrna/Złota lub Start/Plus/Premium)

---

## 10. Plan realizacji

1. Akceptacja wyceny przez klienta
2. Wybór opcji (A lub B)
3. Przekazanie danych (poziomy, progi, regulamin)
4. Implementacja bazy i logiki (1-2 tygodnie)
5. Integracja z rezerwacją + admin panel (1 tydzień)
6. Testy i QA (3-5 dni)
7. Wdrożenie na produkcję
8. Wsparcie po wdrożeniu (3h w cenie)

---

## 11. Decyzja

**Opcja A (MVP)** — 72h / 11 520 PLN netto / 14 169.60 PLN brutto
- Pełna funkcjonalność lojalnościowa
- Podstawowe powiadomienia
- Prosta grafika karty

**Opcja B (Full)** — 93h / 14 880 PLN netto / 18 302.40 PLN brutto
- Wszystko z opcji A
- Profesjonalne karty PDF z QR
- Rozbudowane powiadomienia (milestones)
- Ochrona przed degradacją
- Statystyki programu
- Eksport danych

---

## 12. Porównanie ze starą wyceną

| Aspekt | Stara wycena | Nowa wycena |
|--------|--------------|-------------|
| Zakres | Proste kody rabatowe (LATO2025, VIP50) | Pełny system lojalnościowy |
| Aktywacja | Ręczne wpisanie kodu | Automatyczna po pierwszej wizycie |
| Poziomy | Brak | Wielopoziomowa progresja |
| Karty fizyczne | Brak | Tak (z grafiką per poziom) |
| Profil klienta | Brak | Pełny dashboard z postępem |
| Godziny | 25h | 72-93h |
| Koszt | 2 700 PLN | 11 520 - 14 880 PLN |

**Wniosek:** To zupełnie inna funkcjonalność niż pierwotnie wyceniona. Nowe wymagania klienta wymagają pełnego systemu lojalnościowego, nie prostych kodów rabatowych.
