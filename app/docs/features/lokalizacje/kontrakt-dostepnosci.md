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

> **Stan 2026-09-09 (Faza 4 etap A, kroki 4.1/4.2/4.3):** sygnatura poniżej jest już w kodzie
> (`RentalAvailabilityService.php`). Dowód falsyfikowalności: 26 testów charakteryzujących z kroku
> 0.2 (`RentalAvailabilityServiceTest.php`) przechodzi BEZ ZMIANY; ręczne cofnięcie gałęzi `null`
> do `$service->quantity_total ?? 0` bez rozgałęzienia na `locationCapacity()` wywala 10/12
> testów w nowym pliku `RentalAvailabilityServiceLocationTest.php` (zmierzone, nie deklarowane).
>
> **Aktualizacja, etap B (kroki 4.4/4.5, ten sam dzień):** 8 z 9 wywołań przekazuje `$locationId`
> — `CartService`'s `addItem()`/`updateQuantity()`/`convertToOrder()`, `CreateRental`,
> `EditRental`, oraz WSZYSCY TRZEJ wywołujący `RentalExtensionService::checkAvailabilityForExtension()`
> (jej dwaj wewnętrzni w `RentalExtensionService` i `RentalExtensionController::checkAvailability()`
> — ten trzeci był pominięty w pierwszym przebiegu 4.5 i doprawiony po code review, patrz
> `RentalExtensionController.php:41`).
> Zachowanie dla `$locationId === null` pozostaje bit w bit identyczne — dowód: `RentalAvailabilityServiceTest.php`
> nadal przechodzi bez zmian, a każdy nowy caller ma dziś `null` jako jedyną możliwą wartość
> tam, gdzie wiersz nigdy jeszcze nie miał ustawionego `location_id`.
>
> **Aktualizacja, krok 4.6 (ten sam dzień):** `RentalBookingController` (`:31`
> `checkAvailability`, `:48` `monthlyAvailability`) przestał być martwym wywołaniem —
> oba dostały opcjonalny query param `location_id`, przepięte w JEDNYM kroku (zob. tabela
> Zasady 3 niżej). `getMonthlyAvailability()` też dostała `?int $locationId = null`, tym samym
> wzorcem co `getAvailableQuantity()` w kroku 4.1 — parametr na końcu, gałąź `null` bit w bit
> dzisiejsza, nigdy nie blokuje.

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

| # | Miejsce | Tryb | `$locationId` (stan 2026-09-09, krok 4.6) |
|---|---|---|---|
| 1 | `RentalBookingController:31` | read-only, publiczne API | ✅ opcjonalny query param `location_id`, fail-closed |
| 2 | `RentalBookingController:48` → `getMonthlyAvailability` | read-only, kalendarz | ✅ tak samo, przepięte w tym samym kroku |
| 3 | `RentalAvailabilityService::createHold` | `@deprecated`, legacy | ❌ celowo pominięte, kod martwy |
| 4 | `CartService:108` (`addItem`) | zapis | ✅ nowy parametr, persystowany na wierszu |
| 5 | `CartService:249` (`convertToOrder`) | zapis | ✅ `$item->location_id` |
| 6 | `CartService:576` (`updateQuantity`) | zapis | ✅ `$item->location_id` |
| 7 | **`RentalExtensionService::checkAvailabilityForExtension()`** (`:71`) | przelotka, zapis i odczyt | ✅ `?int $locationId = null` w sygnaturze |
| 8 | `CreateRental:43` | zapis | ✅ z nowego pola `location_id` w `RentalResource::form()` |
| 9 | `EditRental:43` | zapis | ✅ tak samo |

**Pozycja 7 to przelotka z TRZEMA wywołującymi, nie jednym** — `RentalExtensionService::requestExtension()`
(`:123`), `::approve()` (`:201`) i `RentalExtensionController::checkAvailability()` (`:41`, endpoint
JSON). Pierwszy przebieg kroku 4.5 wywołania w `RentalExtensionService` przekazał wszędzie
`locationId: $item->location_id` — ale **pominął trzeciego wywołującego**, czyli dokładnie ten sam
błąd o krok wyżej: „wywołanie, które się pomija" doczekało się własnego pominiętego wywołującego.
Naprawione po code review (`RentalExtensionController.php:49`). Skutek pominięcia był jednokierunkowy
i cichy: gdy pula globalna (`quantity_total`) wyczerpana w INNYM oddziale, a sztuka wolna w oddziale
pozycji — endpoint zwracał `can_extend: false` dla w pełni uprawnionego przedłużenia, bez wyjątku,
bez logu. Test przechodzący przez ten endpoint HTTP:
`RentalExtensionControllerTest::test_check_can_extend_is_true_when_the_items_own_location_has_a_free_unit_even_though_the_global_pool_is_exhausted_elsewhere`.

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

> **Stan 2026-09-09 (krok 4.3):** hierarchia zaimplementowana jako
> `RentalAvailabilityService::locationCapacity()` (`private`, wywoływane wyłącznie z wnętrza
> `getAvailableQuantity()` — Zasada 1 nadal obowiązuje, żaden wywołujący nie widzi tej metody
> bezpośrednio). Punkt 1 (lock na `services`) NIE jest tu ponownie zdobywany — metoda zakłada, że
> wywołujący już go trzyma, dokładnie tak jak reszta klasy zakłada to dziś dla `forUpdate: true`.
> Punkt 2 (`lockForUpdate()` na wierszu kotwicy) wykonuje się tylko gdy `$forUpdate = true`.
> Brakujący wiersz kotwicy czyta się jako pojemność 0 i **nigdy nie jest materializowany** przez
> tę metodę — zgodnie z akapitem niżej o `insertOrIgnore` poza ścieżką blokady.
>
> **Aktualizacja, etap B (krok 4.4):** `locationCapacity()` jest dziś wywoływana na ścieżce
> produkcyjnej (`CartService::convertToOrder()` czyta `$item->location_id`, ustawiane przez
> `addItem()`). Harness `tests/Concurrency/CartCheckoutRaceTest` dostał dwa nowe scenariusze
> per-oddział (ten sam oddział/ostatnia sztuka → jeden wygrywa; różne oddziały/po sztuce każdy →
> oba przechodzą) — patrz `tests.md`, sekcja „tests/Concurrency".

**Materializacja brakujących wierszy kotwicy (`insertOrIgnore`) musi zostać POZA ścieżką blokady.**
`INSERT IGNORE` na duplikacie klucza unikalnego zakłada S-lock i w połączeniu z `lockForUpdate`
jest generatorem zakleszczeń — czyli dokładnie tym, co eager-materializacja miała wyeliminować.

**Faza 3 (`ServiceUnitObserver`) trzyma się tego wyłącznie przez niezmiennik A** ("egzemplarz
wypożyczony pozostaje `available`", `.claude/rules/rental-availability.md` §5) — wydanie i zwrot
z definicji nie zmieniają ani `status`, ani `location_id` egzemplarza, więc obserwator (który
przelicza kotwicę i woła `insertOrIgnore` wyłącznie gdy jedno z tych dwóch pól faktycznie się
zmieniło — `wasChanged('location_id') || wasChanged('status')`) w ogóle nie odpala na gorącej
ścieżce dostępności. Materializacja zachodzi tylko przy `created` i przy realnej zmianie
lokalizacji/statusu — obie to akcje panelu, nigdy ścieżka trzymająca `Service::lockForUpdate()`.

**Na ścieżce trzymającej `Service::lockForUpdate()` NIE WOLNO zmieniać `status` ani `location_id`
egzemplarza `ServiceUnit`.** Zrobienie tego uruchomi ten sam `insertOrIgnore` obserwatora
wewnątrz aktywnego locka — dokładnie ten deadlock, który powyższy akapit każe trzymać poza
ścieżką blokady. Jeśli przyszły etap (wydanie/zwrot z panelu) kiedykolwiek będzie musiał zmienić
jedno z tych pól w tej samej transakcji co lock — kotwica musi zostać zmaterializowana **przed**
wejściem w lock, przez `App\Actions\Inventory\SyncServiceLocationStock::forService()`, nie
liczyć na to, że obserwator zrobi to bezpiecznie w locie. Dowód braku zapisu na kotwicy przy
zmianie pola niezwiązanego ze statusem/lokalizacją:
`ServiceUnitObserverTest::test_updating_a_field_unrelated_to_status_or_location_never_touches_the_anchor_table`.

## Zasada 5 — filtr lokalizacji w outer WHERE

Na `order_items` filtr `location_id` idzie **w zewnętrznym WHERE**. Nigdy:

- w `whereHas` — `FOR UPDATE` nie zejdzie do podzapytania,
- w JOIN na `orders`,
- w `Order::scopeExpired()` ani w gałęzi `pending_payment` scope'u `blockingAvailability()`.

`OrderItem::scopeBlockingAvailability()` (`OrderItem.php:115-137`) i `Order::scopeExpired()`
(`Order.php:358-372`) **muszą pozostać lustrzane** — komentarze-kontrakty w obu miejscach wprost
tego wymagają. Ich rozjazd to overbooking.

> **Stan 2026-09-09:** zaimplementowane w `getAvailableQuantity()` dokładnie tak, jak wyżej —
> filtr dochodzi jako osobne `->where(...)` DOKLEJONE po `blockingAvailability()`, nigdy do środka
> jej domknięcia. Ani `scopeBlockingAvailability()`, ani `Order::scopeExpired()` nie zostały
> dotknięte przez tę zmianę (`git diff` na obu plikach modeli jest pusty).

### `location_id = NULL` na rezerwacji — rozstrzygnięcie (Faza 4 etap A)

Rezerwacja bez przypisanego oddziału (dane sprzed backfillu kroku 4.8, albo wiersz utworzony
zanim ścieżka zapisu zaczęła ustawiać to pole) **blokuje KAŻDY oddział, nie żaden** — od kroku 4.6
dotyczy to też tego, co widzi `RentalBookingController`/`getMonthlyAvailability`, gdy klient poda
`location_id`.

Uzasadnienie: metoda nie wie, gdzie fizycznie stoi sprzęt tej rezerwacji — mógł być w dowolnym
oddziale. Potraktowanie „na pewno nie w tym oddziale" pozwoliłoby rezerwacji ze zgubionym
oddziałem współistnieć z nową, przypisaną rezerwacją na ten sam fizyczny egzemplarz — czyli
dokładnie oversell, któremu ta cała metoda ma zapobiegać. Blokowanie wszędzie kosztuje najwyżej
fałszywe „niedostępne" — ten sam kierunek konserwatywności, co Zasada 7 niżej ("zaniżanie, nie
zawyżanie"). Implementacja: `->where('location_id', $locationId)->orWhereNull('location_id')`
po obu stronach (legacy `rentals` i `order_items`).

Dowód falsyfikowalności: `RentalAvailabilityServiceLocationTest::
test_an_order_item_with_no_location_assigned_blocks_every_location` i
`test_a_legacy_rental_with_no_location_assigned_blocks_every_location` — usunięcie
`orWhereNull(...)` z obu miejsc w `RentalAvailabilityService.php` wywala dokładnie te dwa testy,
żaden inny.

### `availabilityForServices()` — brak klucza w wyniku znaczy ZERO, nie „brak ograniczenia"

Krok 4.7 (code review, 2026-09-09). `capacityByServiceLocation` w `availabilityForServices()`
buduje się **wyłącznie** z wierszy kotwicy `service_location_stocks`. Kotwica i rezerwacje
(`rentals`/`order_items`) to dwie niezależne tabele bez FK między sobą — możliwy jest stan, w
którym oddział B ma rezerwację dla usługi, ale nigdy nie miał dla niej wiersza kotwicy (np.
rezerwacja z dawnego, jednooddziałowego okresu, albo wiersz kotwicy usunięty ręcznie). W takim
przypadku `$result[$serviceId]['locations']` **nie zawiera klucza B w ogóle** — nie `0`, tylko
brak klucza.

Numerycznie to poprawne (`getAvailableQuantity(locationId: B)` też zwróciłoby `0`, bo
`locationCapacity()` czyta brakujący wiersz jako pojemność 0 — Zasada 2 wyżej). Problem jest
w **kontrakcie interfejsu**: przyszły wywołujący (Faza 5's kafelek) MUSI czytać ten wynik jako
`$bulk[$id]['locations'][$locationId] ?? 0`, nigdy `isset(...)` jako „czy w ogóle mamy dane" —
pomyłka w tę stronę pokazałaby dostępność sprzętu tam, gdzie żadnej kotwicy dla niego nie ma.

Dowód: `RentalAvailabilityServiceBulkTest::
test_a_location_with_reservations_but_no_anchor_row_is_absent_from_the_result_and_that_means_zero_not_unlimited`
— tworzy rezerwację w lokalizacji bez wiersza kotwicy, potwierdza brak klucza w wyniku zbiorczym
ORAZ że `getAvailableQuantity(locationId: $ta)` zwraca jawne `0` dla tej samej lokalizacji —
dwa różne kształty, ta sama liczba.

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

**Aktualizacja, etap B (krok 4.4):** wariant per-oddział DODANY — dwa nowe scenariusze
(`test_two_concurrent_checkouts_for_the_last_unit_in_the_same_location_only_one_succeeds`,
`..._in_different_locations_both_succeed`), lustro istniejących dwóch dla dat. Nadal świadomie
pominięte: przedłużenie kontra nowa rezerwacja (poza zakresem tego etapu).

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

Wszystkie trzy ścieżki zapisu (`CartService:108` `addItem` / `:249` `convertToOrder` / `:576`
`updateQuantity`) odejmują teraz od `$available` sumę ilości **rodzeństwa pozycji tej samej
usługi w tym samym koszyku**, których okno dat nakłada się z badaną pozycją (inkluzywnie po obu
stronach, identycznie jak w `getAvailableQuantity()`):

- `addItem()` sumuje **istniejące** `CartItem`y (już zaakceptowane wcześniejszymi wywołaniami),
- `updateQuantity()` sumuje identycznie, **z wyłączeniem edytowanej pozycji** —
  ten sam powód co parametr `$excludeRentalId` w `getAvailableQuantity()`,
- `convertToOrder()` (rozstrzygająca ścieżka, pętla `CartService.php:237-283`) idzie zachłannie:
  iteruje pozycje w deterministycznej kolejności (`orderBy('service_id')->orderBy('id')`,
  `CartService.php:204`) i dolicza do popytu tylko te wcześniejsze pozycje TEJ SAME transakcji,
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
> okno dat, i (od kroku 4.4, zaimplementowane) per oddział. `addItem()`/`updateQuantity()`
> dokładają `->where('location_id', $locationId)` do zapytania o rodzeństwo (Eloquent tłumaczy
> `where(kolumna, null)` na `whereNull()`, więc `$locationId === null` zachowuje się identycznie
> jak przed tą zmianą); `convertToOrder()`'s klucz agregujący w PHP to `"{$serviceId}|{$locationId}"`
> zamiast gołego `$serviceId`. Sfalsyfikowane w obie strony ręczną mutacją kodu (złamano→czerwone
> testy→cofnięto): brak filtra lokalizacji w zapytaniu o rodzeństwo → fałszywe odrzucenie dwóch
> nienakładających się kompetencyjnie pozycji w różnych oddziałach; klucz bez `$locationId` w
> `convertToOrder()` → to samo zjawisko w drugą stronę.

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
