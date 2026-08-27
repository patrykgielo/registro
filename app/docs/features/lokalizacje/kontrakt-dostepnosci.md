# Kontrakt dostępności

> Ten dokument opisuje zasady, których złamanie kończy się **oversellem** — wypożyczeniem sprzętu,
> którego nie ma. Kod broniący przed tym powstał po empirycznej weryfikacji realnego buga
> i jest opisany w docblocku `app/Services/RentalAvailabilityService.php:22-53`.

## Zasada 1 — jedno wejście

Wymiar lokalizacji wchodzi **wyłącznie** przez `RentalAvailabilityService::getAvailableQuantity()`.
Nigdy własnym zapytaniem obok.

Projekt miał wcześniej **cztery** kopie tej samej matematyki i już dwie z nich rozjechały się
z prawdą:

| Kopia | Co było nie tak |
|---|---|
| `Service::availableQuantity()` | pomijała status `Held`; **zero wywołań produkcyjnych** |
| `Service::scopeAvailableBetween()` | raw SQL liczący **tylko `rentals`**, ignorował `order_items` → zawyżał dostępność; **zero wywołań produkcyjnych** |

Obie usunięte w Fazie 0. Zostają dwie, które muszą być zmieniane **razem**:
`getAvailableQuantity()` (punkt) i `getMonthlyAvailability()` (kalendarz).

## Zasada 2 — niezmiennik zerowej regresji

```php
getAvailableQuantity(Service $s, Carbon $start, Carbon $end,
                     bool $forUpdate = false, ?int $excludeRentalId = null,
                     ?int $locationId = null): int
```

Gdy `$locationId === null`, metoda czyta **`services.quantity_total` dosłownie** i nie dokłada
żadnego filtra — kod bit w bit dzisiejszy.

To nie jest kosmetyka. Dzięki temu zdanie „tenant bez oddziałów zachowuje się identycznie" jest
**twierdzeniem o kodzie**, a nie o dyscyplinie danych. Wariant, który w gałęzi `null` czytał
nową tabelę, tracił tę gwarancję przy pierwszej usłudze bez wiersza stanu (import, seeder,
fabryka omijająca obserwator).

Parametr idzie **na końcu sygnatury** — wszystkie istniejące wywołania używają parametrów
nazwanych (`forUpdate:`, `excludeRentalId:`), więc działają bez zmiany.

## Zasada 3 — dziewięć wywołań, nie osiem

| # | Miejsce | Tryb |
|---|---|---|
| 1 | `RentalBookingController:31` | read-only, publiczne API |
| 2 | `RentalBookingController:48` → `getMonthlyAvailability` | read-only, kalendarz |
| 3 | `RentalAvailabilityService::createHold` | `@deprecated`, legacy |
| 4 | `CartService:98` (`addItem`) | zapis |
| 5 | `CartService:225` (`convertToOrder`) | zapis |
| 6 | `CartService:509` (`updateQuantity`) | zapis |
| 7 | **`RentalExtensionService:71`** | przelotka, zapis i odczyt |
| 8 | `CreateRental:43` | zapis |
| 9 | `EditRental:43` | zapis |

**Pozycja 7 jest tą, którą się pomija.** `checkAvailabilityForExtension()` nie ma dziś parametru
lokalizacji i przekazuje `$forUpdate` dalej. Bez przepuszczenia przez nią `$locationId`
przedłużenie wypożyczenia sprzedaje sprzęt z **cudzego oddziału**, cicho.

## Zasada 4 — dyscyplina blokad

Na ścieżkach zapisu obowiązuje **jedno i drugie**:

> Zmierzone w Fazie 0 (patrz Zasada 6): wyścig na ostatniej sztuce zamyka **`forUpdate: true`**,
> nie lock na `services`. Reguła „jedno i drugie" zostaje w mocy — lock na `services` pełni tu
> rolę nadmiarowej serializacji i deterministycznej kolejności blokad przy koszyku
> wielopozycyjnym — ale **nie wolno go przywoływać jako dowodu bezpieczeństwa** tej ścieżki.


1. `Service::lockForUpdate()` na wierszu usługi **przed** wywołaniem,
2. `forUpdate: true` w wywołaniu.

Powód (z docblocku): pod MySQL REPEATABLE READ transakcja dzieli snapshot ustalony przy pierwszym
spójnym odczycie. `SELECT ... FOR UPDATE` na **innym** wierszu (usługi) tego snapshotu **nie
resetuje**. Transakcja, która zaczekała na locku i wznowiła się po commicie zwycięzcy, nadal
policzyłaby dostępność ze stanu **sprzed** jego wstawienia — i obie sprzedałyby ostatnią sztukę.
Dopiero uczynienie samych zapytań zliczających blokującymi zamyka wyścig.

### Po dodaniu kotwicy

Hierarchia blokad, **zawsze w tej kolejności**:

1. `services` po `service_id` rosnąco — istniejąca, deterministyczna kolejność z
   `CartService.php:185` (`$cart->items()->orderBy('service_id')->orderBy('id')`),
2. `service_location_stocks` po `(service_id, location_id)` rosnąco — wewnątrz już zdobytego locka.

`Service::lockForUpdate()` **zostaje** mimo że kotwica czyni go zbędnym. Płacimy nadmiarową
serializacją niezależnych oddziałów (dla wypożyczalni SMB nieodczuwalną), kupujemy zero ryzyka
regresji na kodzie, który powstał po realnym bugu. Zawężenie locka to osobna, późniejsza decyzja.

**Materializacja brakujących wierszy kotwicy (`insertOrIgnore`) musi zostać POZA ścieżką blokady.**
`INSERT IGNORE` na duplikacie klucza unikalnego zakłada S-lock i w połączeniu z `lockForUpdate`
jest generatorem zakleszczeń — czyli dokładnie tym, co eager-materializacja miała wyeliminować.

## Zasada 5 — filtr lokalizacji w outer WHERE

Na `order_items` filtr `location_id` idzie **w zewnętrznym WHERE**. Nigdy:

- w `whereHas` — `FOR UPDATE` nie zejdzie do podzapytania,
- w JOIN na `orders`,
- w `Order::scopeExpired()` ani w gałęzi `pending_payment` scope'u `blockingAvailability()`.

`OrderItem::scopeBlockingAvailability()` (`OrderItem.php:115-137`) i `Order::scopeExpired()`
(`Order.php:358-372`) **muszą pozostać lustrzane** — komentarze-kontrakty w obu miejscach wprost
tego wymagają. Ich rozjazd to overbooking.

## Zasada 6 — dowód, nie deklaracja

W repo **nie istniał ani jeden test dwupołączeniowy**. Wszystkie testy oversellu są sekwencyjne
i przeszłyby także przy skutecznie usuniętym locku; `.env.testing` to SQLite, który nie ma
prawdziwych blokad wierszy. Weryfikacja dyscypliny blokad była **ręczna** — komentarz
`OrderItem.php:93-95`.

Faza 0 postawiła ten harness: `tests/Concurrency/CartCheckoutRaceTest.php`, uruchamiany przez
`bash scripts/test-concurrency.sh` na **jednorazowym** kontenerze `mysql:8.0`. Suite `Concurrency`
jest celowo poza `defaultTestSuite` (ten sam wzorzec co `Browser`) i pomija się z komunikatem, gdy
sterownik nie jest MySQL.

Wyścig jest **sterowany, nie statystyczny**: proces A otwiera transakcję zewnętrzną (zagnieżdżone
`DB::transaction()` używa savepointów, więc blokad nie zwalnia), `DB::listen()` wykrywa moment
pobrania locka i dotyka pliku sygnalizującego, test czeka na ten plik i dopiero wtedy startuje
proces B. Drugi proces trafia na blokadę **za każdym razem**, bez pętli i bez obciążania maszyny.

### Zmierzony werdykt — która warstwa naprawdę zamyka wyścig

Trzy warianty, ten sam scenariusz (ostatnia sztuka, nakładające się daty, dwa koszyki):

| Wariant | Wynik |
|---|---|
| kod nietknięty | brak oversellu — jedno zamówienie |
| `forUpdate: true` → `false` (lock na `services` zostaje) | **OVERSELL** — dwa zamówienia na jedną sztukę |
| `Service::lockForUpdate()` → zwykły `findOrFail()` (`forUpdate` zostaje) | brak oversellu — potwierdzone dwukrotnie |

**Warstwą zamykającą wyścig jest `forUpdate: true`**, czyli blokujący odczyt na
`rentals`/`order_items` — nie lock na wierszu `services`. Hipoteza formułowana przed pomiarem
(„lock na `services` wystarcza, `forUpdate` jest nadmiarowe") okazała się **odwrotna do prawdy**.

Najlepsza hipoteza mechanizmu — **niezweryfikowana osobnym testem, podana jako hipoteza, nie
fakt**: `SELECT ... FOR UPDATE` z warunkiem na zakres dat bierze pod REPEATABLE READ blokady
next-key/gap, które serializują konkurencyjne INSERT-y w tym samym oknie, niezależnie od tego, czy
wiersz `services` jest zablokowany.

> **To NIE jest argument za usunięciem `Service::lockForUpdate()`.** Harness pokrywa dwa
> scenariusze i tylko dla nich rozstrzyga. Nie testował kolejności blokad przy koszyku
> wielopozycyjnym, którą Zasada 4 przywołuje jako osobne uzasadnienie. Wynik mówi wyłącznie tyle,
> że tego konkretnego wyścigu **nie zamyka** lock na `services` — więc nie wolno go przywoływać
> jako dowodu bezpieczeństwa tej ścieżki.

Scenariusze pokryte:

- dwóch klientów, ostatnia sztuka, nakładające się daty → przechodzi dokładnie jeden,
- ten sam sprzęt, **nienakładające się** okna → przechodzą **oba** (dowód braku fałszywej odmowy).

Świadomie pominięte do Fazy 4: wariant per-oddział (oddziały jeszcze nie istnieją) oraz
przedłużenie kontra nowa rezerwacja.

## Zasada 7 — sumuj popyt w obrębie jednej transakcji

`getAvailableQuantity()` odpowiada na pytanie „ile jest wolne **teraz, według zapisanych
rezerwacji**". Nie wie nic o tym, co wywołujący zaakceptował chwilę wcześniej w tej samej pętli —
ani o rodzeństwie pozycji w tym samym koszyku, którego w ogóle nie widzi (Sekcja „Co rezerwuje, a
co nie" niżej).

To była przyczyna realnego oversellu (ClickUp `86cb93tfw`, **naprawione** krok 0.4 Fazy 0):
`convertToOrder()` walidowała pozycje w pętli, a `OrderItem::create()` wykonywał się dopiero **po**
pętli. Trzy pozycje po 1 szt. tej samej usługi przy `quantity_total = 1` przechodziły wszystkie, bo
każda iteracja widziała tę samą, niezmienioną pulę. `addItem()` nie scalał pozycji, więc ten sam
sprzęt tworzył N osobnych wierszy `CartItem`.

**Jeden użytkownik, jedna transakcja, zero współbieżności** — dlatego ani lock, ani `forUpdate`,
ani żaden test sekwencyjny tego nie łapały.

### Naprawa — agregacja popytu rodzeństwa

Wszystkie trzy ścieżki zapisu (`CartService:98`/`:225`/`:509`) odejmują teraz od `$available`
sumę ilości **rodzeństwa pozycji tej samej usługi w tym samym koszyku**, których okno dat nakłada
się z badaną pozycją (inkluzywnie po obu stronach, identycznie jak w `getAvailableQuantity()`):

- `addItem()` sumuje **istniejące** `CartItem`y (już zaakceptowane wcześniejszymi wywołaniami),
- `updateQuantity()` sumuje identycznie, **z wyłączeniem edytowanej pozycji** —
  ten sam powód co parametr `$excludeRentalId` w `getAvailableQuantity()`,
- `convertToOrder()` (rozstrzygająca ścieżka, `CartService.php:213-256`) idzie zachłannie:
  iteruje pozycje w deterministycznej kolejności (`orderBy('service_id')->orderBy('id')`,
  `CartService.php:185`) i dolicza do popytu tylko te wcześniejsze pozycje TEJ SAME transakcji,
  które już zostały **zaakceptowane** (nie odrzucone) i nakładają się oknem dat — pozycja odrzucona
  nie zatruwa popytu kolejnej, nienakładającej się z nią pozycji. To rozróżnienie jest konieczne:
  naiwne „zsumuj wszystkie pozycje tej usługi w koszyku" nadmiarowo odrzuciłoby pozycje, które
  faktycznie się nie kolidują (patrz test
  `test_convert_to_order_does_not_over_reject_when_only_middle_item_bridges_two_non_overlapping_windows`
  w `CartServiceTest`).

Komunikat błędu pokazuje zagregowany popyt jako `requested`, nie samą ilość jednej pozycji — klient
widzi „żądane 3, dostępne 1", a nie mylące „żądane 1, dostępne 1".

> Każdy wywołujący, który podejmuje **więcej niż jedną** decyzję w jednej transakcji, musi
> odejmować od puli to, co sam już zaakceptował — agregując popyt per usługa i nakładające się
> okno dat. Po dodaniu oddziałów: per usługa **i** oddział.

### Znana konserwatywność: okno, nie szczyt

Zarówno `getAvailableQuantity()`, jak i agregacja popytu z Zasady 7 liczą **po oknie**, a nie po
rzeczywistym szczycie jednoczesności.

`getAvailableQuantity($start, $end)` odejmuje **wszystkie** rezerwacje nakładające się gdziekolwiek
z `[$start, $end]` — nie sprawdzając, czy nakładają się ze sobą. Przy `quantity_total = 2`
i rezerwacjach `[1-3]` (1 szt.) oraz `[8-10]` (1 szt.) zapytanie o `[1-10]` zwróci **0**, mimo że
w żadnym dniu nie są zajęte więcej niż 1 sztuka.

Agregacja rodzeństwa z koszyka dziedziczy tę samą własność: dla pozycji X sumuje zaakceptowane
pozycje nakładające się z X, nawet jeśli nie nakładają się ze sobą.

**To jest zaniżanie, nie zawyżanie** — system może odmówić rezerwacji, która fizycznie by się
zmieściła, ale nigdy nie sprzeda sprzętu, którego nie ma. Przy wypożyczalni sprzętu to właściwa
strona błędu i zachowanie jest **spójne między obiema warstwami**.

Policzenie prawdziwego szczytu wymaga przemiatania po granicach dat (sweep line) w obu miejscach
naraz — zmiana samej agregacji koszyka, bez `getAvailableQuantity()`, wprowadziłaby tylko
niespójność. Jeżeli kiedyś będzie potrzebna, to osobne zadanie dotykające obu warstw.

**Przy oddziałach ta konserwatywność się kumuluje** — pula jest mniejsza (jeden oddział zamiast
sumy), więc odmowa „mieściłoby się, ale liczymy po oknie" trafi się częściej. Warto to zmierzyć po
Fazie 4, zanim ktoś uzna to za bug.

## Co rezerwuje, a co nie

- **`CartItem` nie rezerwuje niczego wobec `getAvailableQuantity()`.** Metoda go nie widzi
  (`CartService.php:151`) — koszyk to preferencja aż do checkoutu. Ale od naprawy Zasady 7
  rodzeństwo `CartItem`ów TEGO SAMEGO koszyka jest liczone jawnie, osobnym zapytaniem, na każdej
  ścieżce zapisu (patrz wyżej) — to nie jest sprzeczność: dostępność nadal liczy wyłącznie
  `RentalAvailabilityService`, agregacja popytu koszyka to osobny krok, który się od niej odejmuje.
- Ostateczne rozstrzygnięcie zapada w `CartService::convertToOrder()` (`:174-230`): blokada wiersza
  + ponowne sprawdzenie dostępności per pozycja + agregacja rodzeństwa, w transakcji. **Kto
  pierwszy zapłaci, ten ma sprzęt.**
- Dlatego liczba na kafelku katalogu jest informacją „czy w ogóle jest sens", a nie obietnicą.
