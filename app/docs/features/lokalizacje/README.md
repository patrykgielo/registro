# Lokalizacje (oddziały) — dokumentacja funkcji

Wielooddziałowość: sprzęt stoi w konkretnych oddziałach, klient wybiera oddział jak sklep,
stan magazynowy zdejmuje się z tego oddziału i wraca do niego po zwrocie.

**Status:** 🟡 w toku.
**Faza 0 — zmergowana na `develop`** 2026-08-27 ([PR #227](https://github.com/patrykgielo/registro/pull/227)):
naprawa realnego oversellu w koszyku, usunięcie dwóch martwych kopii matematyki dostępności,
harness współbieżności.
**Faza 1 — zmergowana na `develop`** 2026-08-27 ([PR #228](https://github.com/patrykgielo/registro/pull/228)):
oddział jako encja — tabela, model, zasób w panelu, picker mapy, typ treści dla stron CMS.
Zweryfikowana w przeglądarce, nie tylko testami.
**Faza 2 — zmergowana na `develop`** 2026-08-28 ([PR #231](https://github.com/patrykgielo/registro/pull/231)): kotwica
`service_location_stocks`, backfill z `quantity_total`, `quantity_total` jako mirror, panel bez
regresji dla tenanta jednooddziałowego. Dostępność **nietknięta** — wchodzi w Fazie 4.
**Fazy 3-9 — nierozpoczęte, ale odblokowane.** Bramka postawiona 2026-08-28 (weryfikacja
testów pół-automatycznych panelu tenanta i frontu) została **zdjęta 2026-08-30**: oba testy
walkthrough przechodzą i są stałą częścią suite'u.
**Faza 3 — zmergowana na `develop`** 2026-09-08 (PR #257 kroki 3.1-3.3, PR #259 kroki 3.4-3.8):
egzemplarze (`service_units`), obserwator utrzymujący kotwicę, wydanie/zwrot konkretnej sztuki
z numerem na protokole. Wdrożona na UAT.
**Faza 4 etap A — zmergowana na `develop`** 2026-09-09 (PR #263): kroki 4.1 (`getAvailableQuantity(...,
?int $locationId = null)`, gałąź `null` bit w bit dzisiejsza), 4.2 (filtr lokalizacji w outer
WHERE na `order_items`), 4.3 (pojemność z kotwicy `service_location_stocks`, blokowana wewnątrz
już zdobytego locka na `services`) i 4.8 (`location_id` nullable + indeks na `rentals`/
`order_items`/`cart_items`, backfill otwartych rezerwacji do oddziału głównego). **Żadne z 9
wywołań `getAvailableQuantity()` jeszcze nie przekazuje `$locationId`** — to kroki 4.4-4.7,
świadomie poza zakresem tej dostawy, do zrobienia po review. Do tego czasu zachowanie jest
bit w bit identyczne jak przed etapem A (dowód: 26 testów charakteryzujących z kroku 0.2 bez
zmiany + harness współbieżności `tests/Concurrency` zielony bez nowego scenariusza per-oddział).

**Faza 4 etap B — zmergowana na `develop`** 2026-09-09 (PR #264): kroki 4.4 i 4.5. Osiem z dziewięciu
wywołań `getAvailableQuantity()` przekazuje `$locationId` — trzy ścieżki `CartService`
(`addItem`/`updateQuantity`/`convertToOrder`, wraz z agregacją popytu rodzeństwa Zasady 7
przepiętą z per-usługa na per-(usługa,oddział)), `CreateRental`/`EditRental` (nowe pole
`location_id` w `RentalResource::form()`, formularz nie miał go wcale) i „dziewiąte wywołanie"
`RentalExtensionService::checkAvailabilityForExtension()` — przelotka z trzema wywołującymi
(`requestExtension()`, `approve()`, `RentalExtensionController::checkAvailability()`), wszystkie
trzy teraz przekazują `$item->location_id`. Trzeci wywołujący (endpoint HTTP) był pominięty
w pierwszym przebiegu i doprawiony po code review — pełny opis w `kontrakt-dostepnosci.md`
Zasada 3. Harness `tests/Concurrency` ma dwa nowe scenariusze per-oddział.

**Faza 4 etap C — gałąź `feature/lokalizacje-faza4-kalendarz`** 2026-09-09, jeszcze nie
zmergowana: kroki 4.6 i 4.7, **Faza 4 zamknięta w całości**.
`getMonthlyAvailability(..., ?int $locationId = null)` mirroruje `getAvailableQuantity()`'s
gałąź `null`/dyscyplinę locków (nigdy nie blokuje). `availabilityForServices(Collection
$services, Carbon $start, Carbon $end): array` — 3 zapytania zbiorcze zawsze, niezależnie od
liczby usług; `location_id = NULL` na rezerwacji rozwiązane sumowaniem osobnej „grupy NULL"
per usługa i dołożeniem jej w PHP do każdego realnego oddziału (nie da się jednym
`GROUP BY`) — **nie wpięte jeszcze do żadnego widoku**, to zadanie Fazy 5.
`RentalBookingController` dostał opcjonalny, fail-closed query param `location_id`
(zwalidowany przeciwko `organization_id` ORAZ `is_active` oddziału) na obu endpointach naraz —
nie czeka na `LocationContext` (Faza 5.1), bo param jest bezstanowy.

**Faza 5 krok 5.1 — gałąź `feature/lokalizacje-faza5-kontekst`** 2026-09-09, jeszcze nie
zmergowana: `App\Support\LocationContext` (jedyne źródło prawdy dla `selectionRequired()` —
`false` dla 0 lub 1 aktywnej lokalizacji, `true` dla 2+, niezależnie od tego, czy coś jest
aktualnie wybrane) i middleware `App\Http\Middleware\ShareSelectedLocation`, dopisany do
globalnej grupy `web` w `bootstrap/app.php` zaraz po `CheckMaintenanceMode` (po `ResolveTenant`,
zgodnie z porządkiem Layer 7). Middleware czyści `session('selected_location_id')`, gdy nie
rozwiązuje się do aktywnej lokalizacji bieżącego tenanta (obcy tenant, usunięta, nieaktywna) —
nigdy nie rzuca. `LocationContext::selected()` jest samodzielnie bezpieczny nawet bez tego
middleware (rewalidacja przy każdym odczycie) — middleware istnieje wyłącznie po to, żeby surowa
wartość w sesji nie została „duchem" dla przyszłego kodu czytającego ją bezpośrednio. Tenant
zawsze pochodzi z `TenantFeature::currentTenant()` (request attribute), nigdy z sesji — sesja
niesie wyłącznie wybór lokalizacji.

Ustalenie o zasięgu ciasteczka sesji (istotne dla scenariusza „stale session po zmianie
subdomeny" z opisu zgłoszenia): `SESSION_DOMAIN` jest fałszywe w KAŻDYM środowisku tego projektu
(`.env.example`: literalne `null`, które `env()` zamienia na prawdziwe `null`;
`docker-compose.prod.yml`: pusty string `""`) — obie wartości są falsy, więc
`Symfony\Component\HttpFoundation\Cookie::__toString()` nigdy nie dokleja atrybutu `Domain=`.
Ciasteczko sesji jest więc **host-only** (RFC 6265) w każdym środowisku — przeglądarka NIE wyśle
ciasteczka z `tenant-a.{domena}` na `tenant-b.{domena}` ani na domenę główną. Realna „stara
sesja po zmianie subdomeny" przez carry-over ciasteczka jest więc dziś niemożliwa; middleware
i tak waliduje defensywnie (błędny tenant nie jest jedynym źródłem nieaktualnego wyboru —
usunięcie/dezaktywacja lokalizacji na TYM SAMYM hoście wystarczy).

Nic jeszcze nie czyta `LocationContext` poza middleware i testami — żaden widok nie został
dotknięty (przełącznik w headerze to krok 5.2).

Weryfikacja: `pint --test` 966 plików / 0 problemów (baseline 962 + 4 nowe pliki); pełny
`php artisan test` (SQLite) 1899 passed / 5 skipped / 0 failed (baseline 1878 + 21 nowych
testów, dokładna zgodność). MySQL 8.0 nie uruchamiany osobno dla tego kroku — brak nowych
migracji ani zapytań wrażliwych na silnik.

## Mapa dokumentów

| Dokument | Odpowiada na pytanie |
|---|---|
| [plan-wdrozenia.md](plan-wdrozenia.md) | Co robimy, w jakiej kolejności, jak weryfikujemy |
| [model-danych.md](model-danych.md) | Jakie tabele, jakie relacje i **dlaczego akurat takie** |
| [kontrakt-dostepnosci.md](kontrakt-dostepnosci.md) | Jak liczy się dostępność i czego **nie wolno** przy niej ruszać |
| [tryb-jednooddzialowy.md](tryb-jednooddzialowy.md) | Co widzi klient z jedną siedzibą (czyli dziś: każdy) |

Dokumentacja biznesowa (ścieżki użytkownika) mieszka zgodnie z konwencją repo w `docs/business/`:
`customer-journey-locations.md` i `staff-journey-locations.md` (+ wersje `.en.md`).

## Status faz

| Faza | Zakres | ClickUp | Status |
|---|---|---|---|
| 0 | Higiena, dowód współbieżności + naprawa żywego oversellu | [`86cbahqbv`](https://app.clickup.com/t/86cbahqbv) | ✅ **ukończona** 2026-08-27 |
| 1 | Lokalizacja jako encja (adres, geo, zdjęcie, galeria, CMS) | [`86cbahqc9`](https://app.clickup.com/t/86cbahqc9) | ✅ **ukończona** (PR #228/#229/#230) |
| 2 | Stan magazynowy per oddział (kotwica) | [`86cbahqd9`](https://app.clickup.com/t/86cbahqd9) | ✅ **ukończona** (PR #231) |
| 3 | Egzemplarze (numery seryjne) | [`86cbahqdx`](https://app.clickup.com/t/86cbahqdx) | ✅ **ukończona** (PR #257/#259) |
| 4 | Rdzeń dostępności | [`86cbahqen`](https://app.clickup.com/t/86cbahqen) | 🟡 **ukończona (4.1-4.8), etap C niezmergowany** (PR #263/#264 zmergowane; etap C na `feature/lokalizacje-faza4-kalendarz`, code review w toku) |
| 5 | Front klienta (przełącznik, dostępność) | [`86cbahqfy`](https://app.clickup.com/t/86cbahqfy) | 🟡 **krok 5.1 gotowy, niezmergowany** (gałąź `feature/lokalizacje-faza5-kontekst`, code review w toku) |
| 6 | Koszyk i checkout | [`86cbahqgr`](https://app.clickup.com/t/86cbahqgr) | ⬜ nierozpoczęta |
| 7 | Przesunięcia między oddziałami | [`86cbahqhc`](https://app.clickup.com/t/86cbahqhc) | ⬜ nierozpoczęta |
| 8 | Uprawnienia pracowników | [`86cbahqj5`](https://app.clickup.com/t/86cbahqj5) | ⬜ nierozpoczęta |
| 9 | Statystyki per oddział | [`86cbahqk0`](https://app.clickup.com/t/86cbahqk0) | ⬜ nierozpoczęta |

Każde zadanie główne ma subtaski odpowiadające krokom z
[planu wdrożenia](plan-wdrozenia.md), z kryterium akceptacji i sposobem weryfikacji.

## Znaleziony przy okazji: żywy oversell

Podczas przeglądu ClickUp okazało się, że zgłoszenie
[`86cb93tfw`](https://app.clickup.com/t/86cb93tfw) („Zamówienie tego samego produktu mimo
dostępnej 1 sztuki") opisuje **błąd, który dzieje się dziś** — i nie jest to problem
współbieżności, tylko brak sumowania popytu w pętli walidacji `convertToOrder()`.

Przyczyna i naprawa: [`kontrakt-dostepnosci.md`](kontrakt-dostepnosci.md) → Zasada 7,
zadanie **0.4**.

## Skąd się wziął ten zakres

Audyt konkurencji (5 wypożyczalni sprzętu budowlanego, sierpień 2026) wskazał brak pojęcia
oddziału jako **jedyną** z 22 luk, która zmienia model danych. Pozostałe to warstwa treści
i marketingu.

Plan powstał z pomiaru kodu (7 równoległych sond), trzech niezależnych wariantów projektowych
i trzech sędziów oceniających je w soczewkach poprawności, produktu i wdrażalności — nie
z założeń. Każdy fakt w dokumentach ma dowód `plik:linia`.

## Znane ograniczenia (stan 2026-08-29)

| Ograniczenie | Skutek | Zgłoszenie |
|---|---|---|
| Blok „Siatka treści" nie ma trybu „wszystkie" | Dodany oddział **nie pojawia się** na stronie, dopóki ktoś ręcznie nie dopisze go do bloku. Nic o tym nie informuje | [`123k99ct3xt`](https://app.clickup.com/t/123k99ct3xt) |
| `is_active` nie filtruje renderu | Wyłączenie oddziału **nie zdejmuje go ze strony** — `ContentGridResolver::resolveItems()` robi `whereIn('id', $ids)` bez filtra; `is_active` zawęża tylko listę wyboru w panelu | — |
| Brak trasy pojedynczego oddziału | Oddział istnieje wyłącznie jako karta w siatce; `slug` jest w schemacie, ale nic go nie konsumuje | — |

Obie pierwsze pozycje wyglądają dla właściciela identycznie: „wypełniłem wszystko,
a na stronie tego nie ma". Przy diagnozie sprawdź blok CMS **zanim** zaczniesz szukać
w kodzie lokalizacji.
