---
paths:
  - "app/Services/RentalAvailabilityService.php"
  - "app/Services/RentalExtensionService.php"
  - "app/Services/Cart/**"
  - "app/Models/Service.php"
  - "app/Models/OrderItem.php"
  - "app/Models/Location.php"
  - "app/Models/ServiceUnit.php"
  - "app/Models/ServiceLocationStock.php"
---

# Dostępność sprzętu — reguły, których złamanie = oversell

Pełny kontrakt: `app/docs/features/lokalizacje/kontrakt-dostepnosci.md`.

## 1. Jedno wejście

Dostępność liczy **wyłącznie** `RentalAvailabilityService`. Nigdy własnym zapytaniem obok.

Projekt miał **cztery** kopie tej matematyki; dwie rozjechały się z prawdą, zanim ktokolwiek
zauważył (`Service::scopeAvailableBetween()` liczył tylko `rentals`, ignorując `order_items` —
zawyżał dostępność; `Service::availableQuantity()` pomijał status `Held`). Obie miały **zero**
wywołań produkcyjnych, więc nic nie krzyczało. Usunięte.

Zostają dwie i zmienia się je **razem**: `getAvailableQuantity()` (punkt) i
`getMonthlyAvailability()` (kalendarz). Od kroku 4.7 dochodzi **trzecia**, wyłącznie do odczytu
zbiorczego: `availabilityForServices()` — musi liczyć DOKŁADNIE to co `getAvailableQuantity()`
dla tych samych danych (dowód: `RentalAvailabilityServiceBulkTest`'s testy porównujące zbiorczy
wynik z N wywołaniami pojedynczej metody), nigdy własna kopia matematyki.

## 2. Dziewięć wywołań, nie osiem

`RentalBookingController:31` · `:48` (kalendarz, `getMonthlyAvailability()`) · `createHold`
(`@deprecated`) · `CartService:108` (addItem) · `:249` (convertToOrder) · `:576` (updateQuantity)
· **`RentalExtensionService:81`** · `CreateRental:43` · `EditRental:43`.

**Stan 2026-09-09 (Faza 4, kroki 4.4-4.6):** wszystkie osiem żywych (`createHold` celowo
pominięte, martwy kod) przekazują `$locationId`. `CartService`'s trzy wołania czytają je z wiersza
(`addItem()` dostaje nowy parametr, bo wiersz jeszcze nie istnieje; `updateQuantity()`/
`convertToOrder()` czytają `$item->location_id`); `CreateRental`/`EditRental` mają pole
`location_id` w `RentalResource::form()` (opcjonalne — kolumna zostaje nullable na stałe, patrz
Zasada 6); `checkAvailabilityForExtension()` ma `?int $locationId = null` w sygnaturze.
`RentalBookingController` (`:31`/`:48`) dostał opcjonalny query param `location_id`, fail-closed
zwalidowany przeciwko `$service->organization_id` ORAZ `is_active = true`
(`Rule::exists('locations','id')->where(...)->where('is_active', true)`, code review 2026-09-09 —
nieaktywny oddział niczego nie sprzedaje, degradacja do puli globalnej pokazałaby cudzy sprzęt)
— NIE przez `LocationContext` (Faza 5.1, wciąż nie istnieje) — param jest bezstanowy i domyślnie
`null` (zero regresji). Oba endpointy przepięte w JEDNYM kroku (dowód zgodności:
`RentalBookingControllerTest::test_point_check_and_calendar_agree_for_the_same_location_and_day`).

## 3. Blokady — jedno I drugie

Na ścieżce zapisu: `Service::lockForUpdate()` **przed** wywołaniem **oraz** `forUpdate: true`
w wywołaniu.

Pod MySQL REPEATABLE READ `SELECT ... FOR UPDATE` na wierszu usługi **nie resetuje snapshotu**
dla zwykłych odczytów z `rentals`/`order_items`. Transakcja, która zaczekała na locku, policzyłaby
dostępność ze stanu sprzed commitu zwycięzcy. Dopiero blokujące zapytania zliczające zamykają
wyścig. Mechanizm opisany w docblocku `RentalAvailabilityService.php:22-53` — **przeczytaj go,
zanim cokolwiek tam zmienisz.**

## 4. Filtr lokalizacji tylko w outer WHERE

Na `order_items` — nigdy w `whereHas` (FOR UPDATE nie zejdzie do podzapytania), nigdy w JOIN
na `orders`, nigdy w `Order::scopeExpired()`.

`OrderItem::scopeBlockingAvailability()` i `Order::scopeExpired()` **muszą pozostać lustrzane** —
komentarze-kontrakty w obu miejscach wprost tego wymagają. Ich rozjazd to overbooking.

## 5. Egzemplarz wypożyczony NIE zmienia statusu

Pozostaje `available` i przypisany do swojego oddziału. Zajętość w oknie dat mieszka **wyłącznie**
w rezerwacjach.

Zmiana statusu na czas wypożyczenia odjęłaby sprzęt **dwa razy**: raz jako niedostępny egzemplarz,
raz jako rezerwacja.

## 6. Gałąź `$locationId === null` czyta `quantity_total` dosłownie

Nigdy nowej tabeli. To czyni „tenant bez oddziałów zachowuje się identycznie" twierdzeniem
o kodzie, a nie o dyscyplinie danych — chroni ~77 miejsc w testach i publiczny kontrakt API
`total_quantity`.

`?int $locationId = null` jest już w sygnaturze. Gałąź z oddziałem czyta pojemność z
`service_location_stocks` przez prywatną `locationCapacity()` (nigdy poza
`getAvailableQuantity()`/`getMonthlyAvailability()` — Zasada 1 dotyczy też tej pomocniczej
metody), z tą samą dyscypliną locków (2/3 powyżej — `getMonthlyAvailability()` zawsze woła ją z
`forUpdate: false`, nigdy nie blokuje). Reszta jest osobno, bo łatwo przeoczyć: **rezerwacja
z `location_id = NULL` blokuje KAŻDY oddział, nie żaden** — pełne uzasadnienie i dowód
falsyfikowalności w `kontrakt-dostepnosci.md`. `availabilityForServices()` (krok 4.7) rozwiązuje
to samo bez `WHERE ... OR location_id IS NULL` per wywołanie: sumuje osobno „grupę NULL" per
usługa i dokłada ją do KAŻDEGO realnego oddziału tej usługi w PHP — jedyny sposób wyrazić tę
regułę w stałej liczbie zapytań zbiorczych (mechanizm i uzasadnienie w docblocku metody).
**Kontrakt interfejsu (code review 2026-09-09):** ta metoda buduje `locations` WYŁĄCZNIE z wierszy
kotwicy — oddział z rezerwacją, ale bez wiersza kotwicy dla tej usługi, jest w wyniku **NIEOBECNY**,
co znaczy pojemność ZERO, nie „brak ograniczenia". Wywołujący MUSI czytać `?? 0`, nigdy traktować
brak klucza jako „nielimitowane". Pełne uzasadnienie: `kontrakt-dostepnosci.md` pod Zasadą 5.

## 7. Sumuj popyt w obrębie jednej transakcji

`getAvailableQuantity()` mówi „ile jest wolne według **zapisanych** rezerwacji". Nie wie, co
wywołujący zaakceptował chwilę wcześniej w tej samej pętli.

Realny oversell (ClickUp `86cb93tfw`, **naprawione** krok 0.4 Fazy 0): `convertToOrder()`
walidowała w pętli, `OrderItem::create()` szedł dopiero **po** pętli. Trzy pozycje po 1 szt. przy
`quantity_total = 1` przechodziły wszystkie. `addItem()` nie scalał pozycji, więc ten sam sprzęt to
było N wierszy `CartItem`. Jeden użytkownik, jedna transakcja, **zero współbieżności** — lock i
`forUpdate` były tu bez znaczenia.

**Naprawa:** wszystkie trzy ścieżki (`CartService:108`/`:249`/`:576`) odejmują od `$available` sumę
ilości rodzeństwa `CartItem`ów tej samej usługi w tym samym koszyku, których okno dat nakłada się z
badaną pozycją. `updateQuantity()` wyklucza edytowaną pozycję z tej sumy (jak `$excludeRentalId`).
`convertToOrder()` idzie zachłannie w kolejności `orderBy('service_id')->orderBy('id')` i liczy
tylko WCZEŚNIEJSZE, już zaakceptowane w tej pętli pozycje — odrzucona pozycja nie zatruwa popytu
kolejnej, nienakładającej się z nią pozycji. Naiwne „zsumuj wszystkie pozycje tej usługi"
nadmiarowo odrzuciłoby nienakładające się pozycje — pełny opis i przykład:
`kontrakt-dostepnosci.md` Zasada 7.

**Faza 4 krok 4.4 — agregacja per (usługa, oddział), nie per usługa.** Dokładnie ta zapowiedź z
poprzedniej wersji tego akapitu — teraz zaimplementowana. `addItem()`/`updateQuantity()` filtrują
zapytanie o rodzeństwo dodatkowym `where('location_id', $locationId)` (przez `where(kolumna,
null)` Eloquent tłumaczy się na `whereNull()`, więc `$locationId === null` zachowuje się identycznie
jak przed tą zmianą). `convertToOrder()`'s klucz agregujący w PHP to `"{$serviceId}|{$locationId}"`
zamiast gołego `$serviceId`. Pomyłka w OBIE strony jest realna i sfalsyfikowana testem
(`CartServiceLocationTest`, zmutowano kod ręcznie i cofnięto): brak filtra lokalizacji w
zapytaniu o rodzeństwo → fałszywe odrzucenie dwóch nienakładających się kompetencyjnie pozycji
w różnych oddziałach; klucz bez `$locationId` w `convertToOrder()` → to samo, w drugą stronę
(przez zbytnią agregację) ORAZ odwrotnie — brak jakiegokolwiek klucza per-oddział pozwoliłby
oversell w obrębie jednego oddziału, gdyby ktoś przez pomyłkę usunął go całkiem.

**Ogólna zasada, dalej obowiązująca dla nowych wywołujących:** każdy, kto podejmuje więcej niż
jedną decyzję w jednej transakcji, MUSI odejmować od puli to, co sam już zaakceptował — agregując
per usługa i nakładające się okno dat, i (od kroku 4.4) per oddział.

## 8. Testy sekwencyjne niczego tu nie dowodzą

Wszystkie testy oversellu w repo są sekwencyjne i przechodzą **także przy usuniętym locku**;
`.env.testing` to SQLite bez prawdziwych blokad wierszy.

Zmiana w tym obszarze wymaga harnessu dwupołączeniowego **na MySQL**, z kryterium:
**test musi paść po usunięciu `forUpdate: true` z `CartService::convertToOrder()`.**

Od Fazy 4 kroku 4.4 harness (`tests/Concurrency/CartCheckoutRaceTest.php`) ma też dwa scenariusze
per-oddział, lustro istniejących dwóch dla dat: ten sam oddział/ostatnia sztuka → dokładnie jeden
wygrywa; różne oddziały/po jednej sztuce każdy → oba przechodzą. Uruchom przez
`bash scripts/test-concurrency.sh`.
