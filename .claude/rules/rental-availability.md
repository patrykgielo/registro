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
`getMonthlyAvailability()` (kalendarz).

## 2. Dziewięć wywołań, nie osiem

`RentalBookingController:31` · `:48` (kalendarz, `getMonthlyAvailability()` — krok 4.6, nadal bez
`$locationId`) · `createHold` (`@deprecated`) · `CartService:108` (addItem) · `:249`
(convertToOrder) · `:576` (updateQuantity) · **`RentalExtensionService:81`** · `CreateRental:43` ·
`EditRental:43`.

**Stan 2026-09-09 (Faza 4 etap B, kroki 4.4/4.5):** wszystkie sześć z powyższych oprócz
`RentalBookingController`'a przekazują `$locationId` — `CartService`'s trzy wołania czytają je
z wiersza (`addItem()` dostaje nowy parametr, bo wiersz jeszcze nie istnieje; `updateQuantity()`/
`convertToOrder()` czytają `$item->location_id`, ustawione wcześniej przez `addItem()`);
`CreateRental`/`EditRental` mają nowe pole `location_id` w `RentalResource::form()` (opcjonalne —
kolumna zostaje nullable na stałe, patrz Zasada 6). **Dziewiąte było tym, które się pomijało** —
`checkAvailabilityForExtension()` była przelotką bez własnego parametru lokalizacji; teraz ma
`?int $locationId = null`, a oba jej wywołujące (`requestExtension()`/`approve()`) przekazują
`$item->location_id`. `RentalBookingController` (frontend availability display) zostaje poza
zakresem — nie ma dziś skąd wziąć wybranego oddziału (`LocationContext` to Faza 5).

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
`getAvailableQuantity()` — Zasada 1 dotyczy też tej pomocniczej metody), z tą samą dyscypliną
locków (2/3 powyżej). Reszta jest osobno, bo łatwo przeoczyć: **rezerwacja z `location_id = NULL`
blokuje KAŻDY oddział, nie żaden** — pełne uzasadnienie i dowód falsyfikowalności w
`kontrakt-dostepnosci.md`. Sześć z dziewięciu wywołań przekazuje `$locationId` od Fazy 4 etapu B
(Zasada 2) — kalendarz (4.6) i zbiorczy `availabilityForServices` (4.7, jeszcze nie istnieje) zostają.

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
