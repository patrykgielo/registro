# Changelog dla Klienta: 22-24 Stycznia 2026

**Okres:** 22-24 stycznia 2026
**Wersje:** v4.15.0 → v4.20.2 (6 release'ów)
**Środowisko:** Produkcja (paradocks.pl)

---

## PODSUMOWANIE WYKONAWCZE

W ciągu ostatnich 2 dni wdrożono **6 wersji** aplikacji z następującymi głównymi zmianami:

1. **Booking Wizard** - pełna lokalizacja PL + poprawki UX
2. **CMS Menu** - dynamiczne menu zarządzane z panelu admina
3. **Logo Management** - nowa zakładka "Wygląd" z uploadem logo
4. **System Settings UX** - kontekstowe przyciski + per-tab walidacja
5. **Typography Premium** - system typografii CMS z WCAG 2.2 AA
6. **Security Hardening** - zabezpieczenie uploadu plików (SVG XSS, path traversal)

---

## SZCZEGÓŁOWE ZMIANY PO WERSJACH

---

## v4.15.0 (22.01.2026)

### NOWE FUNKCJONALNOŚCI

#### Przycisk "Zmień usługę" w Booking Wizard
- Na kroku 2 (wybór terminu) pojawił się nowy przycisk "Zmień usługę"
- Umożliwia powrót do wyboru usługi bez problemu z pętlą
- **Poprzednio:** Przycisk "Wróć" powodował nieskończoną pętlę

#### Walidacja pojazdu na froncie
- Formularz pojazdu (krok 3) teraz sprawdza wymagane pola przed wysłaniem
- Typ pojazdu, marka i model są wymagane
- Wizualne podświetlenie pól z błędami

### LOKALIZACJA (Polski)

Cały kreator rezerwacji jest teraz w języku polskim (~34 stringów):

| Element | Poprzednio (EN) | Teraz (PL) |
|---------|-----------------|------------|
| Przycisk powrotu | "Back to Home" | "Strona główna" |
| Tytuł | "Book Your Service" | "Zarezerwuj usługę" |
| Krok 1 | "Service" | "Usługa" |
| Krok 2 | "Date & Time" | "Termin" |
| Krok 3 | "Details" | "Szczegóły" |
| Krok 4 | "Contact" | "Kontakt" |
| Krok 5 | "Review" | "Podsumowanie" |
| Przycisk dalej | "Continue" | "Dalej" |
| Przycisk potwierdź | "Confirm Booking" | "Potwierdź rezerwację" |

### NAPRAWY

- **Nieskończona pętla "Wróć"** - naprawiono problem z przyciskiem wstecz na kroku 2
- **Walidacja pojazdu** - marka i model są teraz wymagane (wcześniej można było pominąć)

---

## v4.16.0 (22.01.2026)

### NOWE FUNKCJONALNOŚCI

#### Dynamiczne Menu CMS
Pełna kontrola nad menu strony z panelu administracyjnego:

| Pole | Opis |
|------|------|
| **Pokaż w menu** | Checkbox włączający stronę w menu |
| **Kolejność** | Numer określający pozycję (mniejszy = wyżej) |
| **Etykieta menu** | Alternatywna nazwa w menu (opcjonalna) |
| **Lokalizacja** | Header / Footer / Oba |

#### Gdzie widoczne dynamiczne menu:
- **Desktop navigation** - górne menu
- **Mobile drawer** - menu wysuwane
- **Footer** - linki w stopce

### ZMIANY

- **Usunięto hardcoded** "Strona główna" i "Usługi" - teraz wszystko z CMS
- **Tab bar (mobile)** - uproszczony, tylko ikony auth

### MIGRACJA

Wymagane uruchomienie migracji:
```bash
php artisan migrate
```

---

## v4.17.0 (22.01.2026)

### NOWE FUNKCJONALNOŚCI

#### Zakładka "Wygląd" w Ustawieniach
Nowa sekcja w System Settings z zarządzaniem logo:

| Pole | Opis |
|------|------|
| **Logo nagłówka** | Upload logo dla górnej nawigacji (SVG/PNG/WebP) |
| **Logo stopki** | Upload logo dla stopki (SVG/PNG/WebP) |
| **Tekst alternatywny** | Alt text dla dostępności |

- Max rozmiar pliku: 1MB
- Automatyczny fallback na domyślne logo gdy brak uploadu

### NAPRAWY

#### Stylowanie stopki
- **Hover ikon kontaktu** - naprawiono efekt najechania na ikony
- **Hover linków** - naprawiono kolor linków przy najechaniu

### ULEPSZENIA

- **Większa czcionka linków** - z 14px na 16px (lepsza czytelność)
- **Usunięto zbędny tekst** - logoAlt nie wyświetla się obok logo (redundancja)

---

## v4.18.0 (23.01.2026)

### NOWE FUNKCJONALNOŚCI

#### Per-Tab Walidacja w Ustawieniach
- Każda zakładka waliduje tylko swoje pola
- **Poprzednio:** Zapisanie jednej zakładki walidowało wszystkie 42+ pola

### NAPRAWY

#### Kontekstowe przyciski testowe
- **Problem:** Przyciski "Testuj połączenie" (Email/SMS) były widoczne na WSZYSTKICH zakładkach
- **Teraz:** Widoczne tylko w odpowiednich zakładkach (Email / SMS)

#### Polskie tłumaczenia
- "Test Email Connection" → "Testuj połączenie"
- Modalne z polskimi opisami

### ULEPSZENIA

- **Refaktoring kodu** - z 150+ linii do 30 linii (łatwiejsze utrzymanie)

---

## v4.20.0 (24.01.2026)

### NOWE FUNKCJONALNOŚCI

#### Premium System Typografii CMS
Wszystkie strony CMS (Pages, Posts, Portfolio, Promotions) używają teraz:

| Funkcja | Opis |
|---------|------|
| **Fluid Typography** | Nagłówki skalują się płynnie z rozmiarem ekranu |
| **Polskie cudzysłowy** | „..." zamiast "..." |
| **Auto-dzielenie wyrazów** | Lepszy wygląd tekstów |
| **Zapobieganie sierocom** | Ostatnie słowo nie zostaje samo |

### NAPRAWY

#### Detekcja ciemnego tła
- **Problem:** Kolory klienta (#00323B, #000000) nie były rozpoznawane jako ciemne
- **Efekt:** Tekst był czarny na ciemnym tle (nieczytelny)
- **Teraz:** Automatyczne wykrywanie ciemnych kolorów według WCAG

### ULEPSZENIA

#### Zgodność z WCAG 2.2 AA
- Line-height: 1.7 (wymóg: ≥ 1.5) ✓
- Kontrast: 7.5:1 (wymóg: 4.5:1) ✓
- Odstępy paragrafów: 1.5em ✓

#### Kolory klienta na ciemnym tle
- Tekst: #FFFFFF (biały)
- Linki: #0AB1EA (niebieski klienta)
- Obramowanie cytatów: #0AB1EA

---

## v4.20.2 (24.01.2026) - HOTFIX

### NAPRAWY

#### Krytyczny błąd 500 przy wyświetlaniu logo
- **Problem:** Strona crashowała z błędem TypeError przy próbie wyświetlenia logo
- **Przyczyna:** Nieprawidłowy format zapisu pliku w bazie
- **Rozwiązanie:** Bezpieczna ekstrakcja ścieżki pliku

### ZABEZPIECZENIA (Security)

#### Ochrona przed atakami SVG XSS
- SVG może zawierać złośliwy JavaScript
- **Teraz:** Automatyczna sanityzacja SVG usuwająca:
  - Skrypty `<script>`
  - Event handlers (onclick, onerror, etc.)
  - Zewnętrzne odwołania

#### Ochrona przed Path Traversal
- Blokowanie prób dostępu do plików systemowych (`../`)
- Blokowanie ścieżek absolutnych (`/etc/passwd`)

#### Walidacja Magic Bytes
- Weryfikacja czy plik jest rzeczywiście obrazem (nie tylko po rozszerzeniu)

---

## STATYSTYKI RELEASE'ÓW

| Wersja | Data | PRów | Plików zmienionych |
|--------|------|------|-------------------|
| v4.15.0 | 22.01 | 4 | 12 |
| v4.16.0 | 22.01 | 4 | 10 |
| v4.17.0 | 22.01 | 4 | 12 |
| v4.18.0 | 23.01 | 3 | 7 |
| v4.20.0 | 24.01 | 4 | 11 |
| v4.20.2 | 24.01 | 5 | 7 |
| **RAZEM** | - | **24** | **59** |

---

## WYMAGANE AKCJE PO STRONIE KLIENTA

### 1. Menu CMS (v4.16.0)
Aby strony pojawiły się w menu:
1. Panel admin → Strony → Edytuj stronę
2. Sekcja "Menu" → włącz "Pokaż w menu"
3. Ustaw kolejność (10, 20, 30...)
4. Wybierz lokalizację (Header / Footer / Oba)

### 2. Logo (v4.17.0)
Aby ustawić własne logo:
1. Panel admin → Ustawienia → Wygląd
2. Upload logo nagłówka (zalecane: SVG)
3. Upload logo stopki (opcjonalne, może być to samo)
4. Zapisz

### 3. Weryfikacja treści CMS (v4.20.0)
Zalecana weryfikacja stron z ciemnym tłem:
- Sprawdzić czy tekst jest czytelny (biały)
- Sprawdzić czy linki są widoczne (niebieski)

---

## KONTAKT

W razie pytań lub problemów:
- Email: [kontakt techniczny]
- Staging: https://srv1203357.hstgr.cloud (do testów)
- Produkcja: https://paradocks.pl
