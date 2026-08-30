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

`RentalBookingController:31` · `:48` (kalendarz) · `createHold` (`@deprecated`) ·
`CartService:98` · `:225` · `:509` · **`RentalExtensionService:71`** · `CreateRental:43` ·
`EditRental:43`.

**Dziewiąte jest tym, które się pomija.** `checkAvailabilityForExtension()` to przelotka bez
własnego parametru lokalizacji — pominięta, sprzedaje sprzęt z cudzego oddziału, cicho.

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

## 7. Sumuj popyt w obrębie jednej transakcji

`getAvailableQuantity()` mówi „ile jest wolne według **zapisanych** rezerwacji". Nie wie, co
wywołujący zaakceptował chwilę wcześniej w tej samej pętli.

Realny oversell (ClickUp `86cb93tfw`, **naprawione** krok 0.4 Fazy 0): `convertToOrder()`
walidowała w pętli, `OrderItem::create()` szedł dopiero **po** pętli. Trzy pozycje po 1 szt. przy
`quantity_total = 1` przechodziły wszystkie. `addItem()` nie scalał pozycji, więc ten sam sprzęt to
było N wierszy `CartItem`. Jeden użytkownik, jedna transakcja, **zero współbieżności** — lock i
`forUpdate` były tu bez znaczenia.

**Naprawa:** wszystkie trzy ścieżki (`CartService:98`/`:225`/`:509`) odejmują od `$available` sumę
ilości rodzeństwa `CartItem`ów tej samej usługi w tym samym koszyku, których okno dat nakłada się z
badaną pozycją. `updateQuantity()` wyklucza edytowaną pozycję z tej sumy (jak `$excludeRentalId`).
`convertToOrder()` (`CartService.php:213-256`) idzie zachłannie w kolejności `orderBy('service_id')
->orderBy('id')` (`:185`) i liczy tylko WCZEŚNIEJSZE, już zaakceptowane w tej pętli pozycje —
odrzucona pozycja nie zatruwa popytu kolejnej, nienakładającej się z nią pozycji. Naiwne „zsumuj
wszystkie pozycje tej usługi" nadmiarowo odrzuciłoby nienakładające się pozycje — pełny opis i
przykład: `kontrakt-dostepnosci.md` Zasada 7.

**Ogólna zasada, dalej obowiązująca dla nowych wywołujących:** każdy, kto podejmuje więcej niż
jedną decyzję w jednej transakcji, MUSI odejmować od puli to, co sam już zaakceptował — agregując
per usługa i nakładające się okno dat (po dodaniu oddziałów: per usługa i oddział).

## 8. Testy sekwencyjne niczego tu nie dowodzą

Wszystkie testy oversellu w repo są sekwencyjne i przechodzą **także przy usuniętym locku**;
`.env.testing` to SQLite bez prawdziwych blokad wierszy.

Zmiana w tym obszarze wymaga harnessu dwupołączeniowego **na MySQL**, z kryterium:
**test musi paść po usunięciu `forUpdate: true` z `CartService.php:225`.**
