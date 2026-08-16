# Aktualizacja zależności — 2026-08-16

**Wynik:** `composer audit --locked` z **35 zgłoszeń w 11 pakietach** do **zera**.
**Zakres:** 32 pakiety w `composer.lock`. **Bez zmian w `composer.json`** — wszystko zmieściło się w istniejących ograniczeniach wersji.
**PR:** #200. Poprzedzone #199 (włączenie `composer audit` i skanowania obrazu w CI).

Dokument opisuje, **co się zmieniło wobec naszej aplikacji**, a nie streszcza changelogów.

---

## 1. Dlaczego to się wydarzyło

Do 2026-08-16 projekt **nie miał żadnego skanowania zależności**. Pierwsze uruchomienie `composer audit` pokazało 35 zgłoszeń, w tym 5 o wysokiej wadze. Żadne z nich nie wymagało podbicia głównej wersji — byliśmy po prostu w tyle.

Skala aktualizacji (32 pakiety, Laravel o sześć wydań mniejszych) była **świadomą decyzją**, a nie skutkiem ubocznym. Uzasadnienie: UAT nie ma dziś płacącego klienta, więc to najtańsze możliwe okno na szeroką aktualizację. Wersja wybiórcza cofnęłaby nas do tego samego problemu w ciągu miesiąca.

---

## 2. Wymaga naszej uwagi

### `guzzlehttp/guzzle` 7.10.4 → 7.15.3 — pięć realnych CVE

Naprawione: obejście kontroli opartych na nazwie hosta przez niekanoniczną postać (CVE-2026-69246, CVSS 7.2), trzy błędy w obsłudze ciasteczek (domeny złożone z samych kropek dopasowujące wszystkie hosty, ujawnienie ciasteczek przy domenach będących adresami IP, niezachowany zakres host-only) oraz nieograniczony przyrost magazynu ciasteczek prowadzący do odmowy usługi.

**Nasza ekspozycja jest niska, ale nie zerowa.** Jedyny konsument Guzzle to `Przelewy24Service` (`app/Services/Payment/Przelewy24Service.php:23-32`), który rozmawia ze stałym hostem P24. **Nigdzie nie budujemy adresu żądania z danych wprowadzonych przez użytkownika** — ładunek webhooka jest odbierany, nie używany do konstruowania połączeń wychodzących. Nie współdzielimy też jednego klienta ani magazynu ciasteczek między niezaufanymi hostami.

**Co sprawdzić w przyszłości:** jeśli powstanie integracja budująca klienta HTTP wokół adresu podanego przez tenanta lub klienta końcowego, **to rozumowanie przestaje obowiązywać** i trzeba je przeprowadzić od nowa.

### `dompdf/dompdf` 3.1.5 → 3.1.6 — sześć CVE, żadne nas nie dotyczy

Wszystkie sześć (obejście `chroot`, wyrocznia istnienia plików przez `@font-face`, dwa odczyty plików lokalnych przez SVG w `data:`, dwie odmowy usługi przez przewymiarowane bitmapy) **wymagają, żeby PDF ładował zewnętrzny obraz, font albo SVG**.

Nasze protokoły tego nie robią. `OrderProtocolPdfService::render()` renderuje wyłącznie `resources/views/orders/protocols/{handover,return}.blade.php`, a te widoki **nie zawierają ani jednego `<img>` ani SVG** — sprawdzone grepem, nie założone.

**Warunek wygaśnięcia tej oceny:** dodanie logo albo jakiejkolwiek grafiki do protokołu. Wtedy wszystkie sześć wraca do rozważenia.

### `laravel/framework` 12.60.2 → 12.66.0 — sześć wydań, cztery pozycje dotykają nas

Przejrzane komplet notatek wydań 12.61.0–12.66.0 (przez API GitHuba; widok porównania i surowy plik zmian okazały się niedostępne). **Zero zmian łamiących.** Cztery pozycje mają styczność z naszym kodem:

| Zmiana | Wydanie | Gdzie u nas |
|---|---|---|
| `ComponentAttributeBag::merge()` domyka wartość stylu średnikiem | 12.63.0 | **2 komponenty Blade** używają `attributes->merge`; zmiana dotyczy składania stylów wpisanych bezpośrednio |
| Nowe komunikaty wykrywania zerwanych połączeń z bazą | 12.63.0 | Workery kolejek i Horizon — poprawia odzyskiwanie po utracie połączenia |
| Separatory ścieżek nie są kodowane w `LocalFilesystemAdapter` | 12.61.1 | Mamy `FILESYSTEM_DISK=public`; dotyczy ścieżek plików wgrywanych |
| Enum jako klucz blokady nakładania się zadań | 12.64.0 | **16 wywołań `withoutOverlapping()`** w harmonogramie |

**Bez znaczenia dla nas:** wszystko wokół Laravel Cloud i kolejek zarządzanych (używamy Redisa z Horizonem, nie Cloud), poprawki PostgreSQL (mamy MySQL), `JsonSchema` (nie używamy).

**Jedna pozycja warta odnotowania jako zbieg okoliczności:** wydanie 12.61.0 zawiera „Accept Symfony's new control-characters exception message in mailer test". To jest dokładnie ten obszar, który ruszaliśmy w PR #182 przy sanityzacji CR/LF w temacie maila. Nasza obrona sanityzuje **finalny wyrenderowany temat** i nie polega na komunikacie wyjątku Symfony, więc zmiana nas nie dotyka — ale warto wiedzieć, że biblioteka pod spodem właśnie tam się poruszyła.

---

## 3. Warte rozważenia

Możliwość odświeżania blokad cache'u (12.63.0) jest interesująca w kontekście znanej wady: **`ShouldBeUnique` jest bezczynne na powiadomieniach** (23 klasy je deklarują, nie robi nic). Ta zmiana sama tego nie naprawia, ale dotyka mechanizmu, na którym opiera się unikalność zadań.

`nesbot/carbon` 3.11.4 → 3.13.2 dokłada `plus()`/`minus()`, `CarbonPeriod::quarterly()` i tryby przepełnienia przy interwałach rocznych — czysto addytywnie.

---

## 4. Bez wpływu

**`league/commonmark` 2.8.2 → 2.10.0** naprawia odmowę usługi w rozszerzeniu `AttributesExtension`. **Nie rejestrujemy tego rozszerzenia nigdzie** — Filament wystawia punkt zaczepienia, ale nic w `app/Filament` z niego nie korzysta.

**`nesbot/carbon`** — sprawdzone celowo, bo to było moje główne podejrzenie. **Semantyka `diffInDays()` nietknięta** w całym zakresie 3.11.4 → 3.13.2. Nasza arytmetyka dni włącznie (`CartService.php:102` i `RentalAvailabilityService.php:190`, oba `diffInDays($end) + 1`) liczy tak samo jak przed aktualizacją. To był realny punkt ryzyka — jednodniowe przesunięcie zmieniałoby ceny wypożyczeń i dostępność sprzętu, a **nasze testy by tego nie złapały**, bo używają tej samej arytmetyki co kod.

**Rodzina Symfony** (`html-sanitizer`, `http-foundation`, `http-kernel`, `mailer`, `mime`, `routing`, `translation`, `yaml`), **`league/flysystem`, `ramsey/uuid`, `vlucas/phpdotenv`, `sabberworm/php-css-parser`, `masterminds/html5`, `nette/utils`, `guzzlehttp/psr7`, `guzzlehttp/promises`** — zależności pośrednie ciągnięte przez Laravel i Filament, wydania poprawkowe w linii 7.4.x, bez bezpośredniego użycia w `app/`.

**Narzędzia deweloperskie i polyfille** (`psy/psysh`, `laravel/prompts`, `laravel/serializable-closure`, `symfony/error-handler`, `symfony/event-dispatcher`, `symfony/var-dumper`, `symfony/polyfill-*`, `league/mime-type-detection`, `guzzlehttp/uri-template`) — deweloperskie albo bezczynne na PHP 8.3+.

---

## 5. Jak to zweryfikowano

- **Protokół PDF wygenerowany przed i po**, porównanie tekstu wyekstrahowanego z obu plików: identyczny układ, różnice wyłącznie w losowych danych z fabryk.
- Pełny zestaw testów: **1331 przeszło, 5 pominiętych, 0 padnięć**. Pint czysty.
- `composer audit --locked` przed i po: **35 → 0**.
- Changelogi czytane ze źródeł oficjalnych: notatki wydań GitHuba, wpisy w bazie doradczej GHSA.

**Czego nie zweryfikowano:** nie przeszliśmy pojedynczo przez każdą z 45 klas kolejkowanych ani 26 powiadomień wobec changeloga Laravela. Przy braku zmian łamiących uznano to za nieproporcjonalne.

---

## 6. Notatka metodyczna na przyszłość

Pobieranie changeloga Laravela **przez widok porównania na GitHubie albo surowy `CHANGELOG.md` nie działa** — pierwszy się nie renderuje, drugi jest za duży. Właściwa droga to API wydań:

```
gh api "repos/laravel/framework/releases?per_page=40" \
  --jq '.[] | select(.tag_name | test("^v12\\.(6[1-6])\\.")) | "### \(.tag_name)\n\(.body)"'
```

---

## 7. Powiązane

- `.claude/rules/ci-cd-troubleshooting.md` — wpis o włączeniu `composer audit` do CI
- `app/docs/deployment/base-image-split.md` — skanowanie obrazu wykazało **19 krytycznych CVE w warstwie systemowej, wszystkie bez dostępnej poprawki**. To osłabia wycenę cyklicznej przebudowy obrazu bazowego jako mechanizmu bezpieczeństwa.

**Stan na 2026-08-16.** Odniesienia `plik:linia` były prawdziwe tego dnia.
