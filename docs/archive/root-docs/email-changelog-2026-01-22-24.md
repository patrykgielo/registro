# EMAIL DO KLIENTA - Aktualizacja systemu Paradocks

---

**Temat:** Aktualizacja systemu Paradocks - podsumowanie zmian (22-24.01.2026)

---

Dzień dobry,

Chciałbym poinformować o zmianach wprowadzonych w systemie Paradocks w ostatnich dniach. Poniżej znajdzie Pan/Pani podsumowanie najważniejszych nowości i ulepszeń.

---

## CO NOWEGO

### 1. Kreator rezerwacji - pełna polska wersja

Cały proces rezerwacji jest teraz w języku polskim:
- Wszystkie kroki: Usługa → Termin → Szczegóły → Kontakt → Podsumowanie
- Przyciski: "Dalej", "Potwierdź rezerwację", "Zmień usługę"
- Komunikaty i podpowiedzi

Dodatkowo naprawiono problem z nawigacją - przycisk powrotu na kroku wyboru terminu działał nieprawidłowo.

### 2. Dynamiczne menu ze stron CMS

Teraz ma Pan/Pani pełną kontrolę nad menu strony bezpośrednio z panelu administracyjnego:

**Jak to działa:**
- Przy edycji dowolnej strony pojawi się nowa sekcja "Menu"
- Można włączyć/wyłączyć widoczność strony w menu
- Można ustawić kolejność wyświetlania
- Można wybrać gdzie ma się wyświetlać: nagłówek, stopka lub oba

Menu aktualizuje się automatycznie po zapisaniu zmian.

### 3. Zarządzanie logo

Nowa zakładka "Wygląd" w Ustawieniach systemowych pozwala na:
- Upload własnego logo dla nagłówka strony
- Upload własnego logo dla stopki (może być inne niż w nagłówku)
- Ustawienie tekstu alternatywnego dla dostępności

Obsługiwane formaty: SVG, PNG, WebP (max 1MB).

### 4. Lepsza typografia na stronach CMS

Wszystkie strony utworzone w CMS (Pages, Posts, Portfolio, Promotions) mają teraz:
- Płynne skalowanie nagłówków na różnych urządzeniach
- Poprawne polskie cudzysłowy „..."
- Automatyczne dzielenie wyrazów
- Lepszą czytelność tekstów

System automatycznie dostosowuje kolory tekstu do tła - na ciemnych sekcjach tekst jest biały, linki niebieskie (zgodnie z Państwa kolorystyką).

---

## POPRAWKI

- Naprawiono wyświetlanie logo (błąd 500 który wystąpił chwilowo)
- Naprawiono efekty najechania na ikony i linki w stopce
- Zwiększono rozmiar czcionki linków w stopce dla lepszej czytelności
- Przyciski testowe (Email/SMS) w ustawieniach są teraz widoczne tylko w odpowiednich zakładkach

---

## BEZPIECZEŃSTWO

Wprowadzono dodatkowe zabezpieczenia przy uploadzie plików:
- Automatyczna weryfikacja formatów graficznych
- Ochrona przed potencjalnie niebezpiecznymi plikami SVG
- Walidacja integralności plików

---

## CO WARTO ZROBIĆ

**1. Skonfigurować menu stron:**
- Panel admin → Strony → wybierz stronę → sekcja "Menu"
- Włącz "Pokaż w menu" dla stron które mają być widoczne
- Ustaw kolejność (mniejsza liczba = wyżej w menu)

**2. Dodać własne logo (opcjonalnie):**
- Panel admin → Ustawienia → Wygląd
- Upload logo dla nagłówka i/lub stopki

**3. Sprawdzić strony z ciemnym tłem:**
- Zweryfikować czy tekst jest czytelny
- Kolory powinny być: tekst biały, linki niebieskie

---

W razie pytań pozostaję do dyspozycji.

Z poważaniem,
[Podpis]

---

**Linki:**
- Strona produkcyjna: https://paradocks.pl
- Panel administracyjny: https://paradocks.pl/admin
