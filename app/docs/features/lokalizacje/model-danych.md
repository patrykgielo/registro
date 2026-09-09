# Model danych — lokalizacje i stan magazynowy

## Trzy pytania, które trzeba rozdzielić

Naiwny model egzemplarzy skleja trzy różne pytania w jedno i przez to nie działa. Ten model
rozdziela je świadomie:

```
GDZIE SPRZĘT MIESZKA i CZY JEST SPRAWNY  →  service_units            (egzemplarz, nr seryjny)
ILE SZTUK STOI W ODDZIALE                →  service_location_stocks  (kotwica: liczba + blokada)
CZY JEST ZAJĘTY W DANYM OKNIE DAT        →  order_items / rentals    (bez zmian)
```

### Dlaczego kotwica, a nie `COUNT` egzemplarzy w locie

Gdyby pojemność oddziału liczyć jako `COUNT(service_units WHERE status='available')`,
a rezerwacje **nadal** odejmować z `order_items`, to sprzęt fizycznie będący u klienta zostałby
odjęty **dwa razy**: raz jako niedostępny egzemplarz, raz jako rezerwacja.

Żeby tego uniknąć, egzemplarz wypożyczony musiałby zachować `status = 'available'` — co jest
poprawne, ale wtedy `COUNT` i tak nie mówi nic o zajętości, więc nic nie kupujemy w zamian za:

- **rozdęcie blokad** — dziś ścieżka zapisu blokuje **jeden** wiersz `services`; po przejściu na
  `COUNT` z `lockForUpdate` blokowałaby **N** wierszy egzemplarzy,
- **N+1 na listingu** — kafelek katalogu wołałby `COUNT` per pozycja, a
  `RentalController::showCategory()` pobiera kategorię **bez paginacji**.

Stąd: **egzemplarze utrzymują kotwicę, nie zastępują jej w ścieżce gorącej.** Obserwator na
`service_units` przelicza `service_location_stocks.quantity` w tej samej transakcji.

> **Zasada, której nie wolno złamać:** egzemplarz wypożyczony **nie zmienia statusu** i pozostaje
> przypisany do swojego oddziału. Zajętość w oknie dat mieszka wyłącznie w rezerwacjach.

## Tabele

### `locations` (nowa)

Oddział / punkt obsługi. Adres, geolokalizacja, dane kontaktowe, zdjęcie i galeria.

| Kolumna | Uwagi |
|---|---|
| `organization_id` | FK; trait `BelongsToOrganization` daje izolację tenanta za darmo |
| `name`, `slug`, `code` | `code` = krótki symbol, np. `WAW` |
| `street`, `building`, `postal_code`, `city` | adres |
| `latitude` `decimal(10,8)`, `longitude` `decimal(11,8)` | ten sam kształt co `service_areas` |
| `phone`, `email`, `opening_hours` (json) | dane kontaktowe |
| `photo`, `gallery` (json), `description` | materiał dla bloków CMS |
| `is_active`, `sort_order` | |
| **`primary_slot`** | shadow column: `1` dla głównej, `NULL` dla reszty |

**UNIQUE `(organization_id, primary_slot)`** — gwarancja DB, że **dokładnie jedna** lokalizacja
tenanta jest główna. Wzorzec skopiowany z `carts.active_slot`
(`2026_07_05_000001_add_active_slot_unique_to_carts_table.php:73`).

Bez tego skalarne podzapytanie `WHERE is_default = 1` w migracji backfillu wywala się na
produkcji, gdy admin zaznaczy drugą lokalizację jako domyślną — a nic by mu tego nie zabraniało.

**UNIQUE `(organization_id, slug)`** — nigdy samo `slug`. Precedens: migracja
`2026_06_29_120000` musiała naprawiać dokładnie ten błąd na `service_areas`.

**Zaimplementowano** (2026-08-27, kroki 1.1/1.2/1.6, gałąź `feature/lokalizacje-encja`):
`database/migrations/2026_08_27_120000_create_locations_table.php` (schemat) +
`2026_08_27_120001_backfill_primary_location_for_organizations.php` (backfill z
`SettingsManager::contactDetailsFor()`, nazwa domyślna „Siedziba główna” — uzasadnienie w
komentarzu migracji) + `App\Models\Location` + `App\Observers\LocationObserver`.

„Zmiana głównej później — ręcznie, jednym kliknięciem" (patrz [tryb-jednooddzialowy.md](tryb-jednooddzialowy.md))
to dwa mechanizmy, nie jeden: `LocationObserver::updating()` broni każdego bezpośredniego
`$location->save()` przed naruszeniem UNIQUE (dwa kolejne commity: najpierw `NULL` staremu,
dopiero potem `1` nowemu — sama kolejność, nie pojedyncza transakcja), a
`Location::promoteToPrimary()` to jedna transakcja obejmująca oba zapisy naraz — tej drugiej
powinna użyć przyszła akcja Filamenta (krok 1.3, poza tym zakresem).

Testy wykonywanego rollbacku (nie tylko statyczny regex na `down()`):
`tests/Feature/Database/CreateLocationsTableMigrationTest.php`,
`tests/Feature/Database/BackfillPrimaryLocationForOrganizationsMigrationTest.php`. Izolacja
tenanta i mechanizm `primary_slot`: `tests/Unit/Models/LocationTenantIsolationTest.php`,
`tests/Unit/Models/LocationPrimarySlotTest.php`.

#### Dlaczego `latitude`/`longitude` w ogóle są (decyzja 2026-08-27)

Zakwestionowane wprost przez właściciela produktu: „potrzebujemy mapy, czy to legacy?".
Odpowiedź uczciwa: picker mapy trafił do planu przez **skopiowanie wzorca z `ServiceArea`**, gdzie
ma realny sens (rysuje koło zasięgu dostawy, czyli pokazuje dane niewidoczne inaczej). Tu został
skopiowany kształt, nie uzasadnienie.

Zmierzone: **jedynym konsumentem** `Location::latitude`/`longitude` w całym repo jest
`resources/views/components/ios/location-card.blade.php:27-34` — link do Google Maps, który **ma
już fallback na adres tekstowy**. Odległość w km jest jawnie poza zakresem (brak źródła pozycji
odwiedzającego), czyli najpoważniejszy przyszły przypadek użycia też odpada.

**Zachowane mimo to, z jednego konkretnego powodu branżowego:** baza sprzętu budowlanego często
stoi pod adresem, który geokoduje się źle — „ul. Przemysłowa 14G, hala 3", plac bez numeru, teren
przemysłowy. Fallback tekstowy tego nie ratuje, bo szuka po tym samym złym adresie. Ręczna pinezka
pomaga dokładnie w tym przypadku, a klient, który przyjedzie 400 m od bramy po odbiór koparki,
zapamięta to.

Kolumny są nullable i po backfillu **puste dla wszystkich 8 tenantów** — nic od nich nie zależy.
Gdyby picker kiedykolwiek miał zniknąć, kolumny warto zostawić: dwa nullable decimale kosztują
zero, a ich brak zamienia każde późniejsze użycie w migrację.

#### Które kolumny docierają do klienta (stan 2026-08-29)

Karta `resources/views/components/ios/location-card.blade.php` ma **jedno** użycie —
`resources/views/components/content-blocks/content-grid.blade.php:108`, z wariantem `:dark`.
To jedyna droga, którą dane oddziału trafiają na stronę publiczną.

| Kolumna | Na karcie | Forma |
|---|---|---|
| `name` | tak | nagłówek |
| `code` | tak | badge przy nazwie |
| `street`/`building`/`postal_code`/`city` | tak | jedna linia adresu |
| `description` | tak | `Str::limit(..., 120)` pod adresem |
| `opening_hours` | tak | lista `label — hours` |
| `photo` | tak | zdjęcie nagłówkowe karty |
| `gallery` | tak | pasek 4 miniatur, licznik `+N` na ostatniej |
| `phone` | tak | `tel:` |
| `email` | tak | `mailto:` |
| `latitude`/`longitude` | pośrednio | link do Google Maps, z fallbackiem na adres |
| `slug` | **nie** | brak dedykowanej trasy pojedynczego oddziału |
| `is_active`, `sort_order`, `primary_slot` | **nie** | sterują doborem i kolejnością, nie treścią |

**`is_active` NIE filtruje renderu — to pułapka.** `ContentGridResolver::resolveItems()`
robi `whereIn('id', $ids)` na ręcznie wybranej liście z bloku, bez warunku aktywności;
`is_active` zawęża wyłącznie listę wyboru w panelu (`optionsForType()`). Wyłączenie
oddziału **nie zdejmuje go ze strony**, dopóki ktoś nie usunie go z bloku „Siatka treści".
Razem z brakiem trybu „wszystkie" daje to parę symetrycznych zaskoczeń: dodanie oddziału
go nie pokazuje, a wyłączenie nie ukrywa.

`code`, `email`, `description` i `gallery` **były zbierane w panelu od kroku 1.1 i nie docierały
nigdzie** aż do 2026-08-29 — właściciel je wypełniał, a klient ich nie widział. Przy dokładaniu
kolumny do `LocationResource` sprawdź tę tabelę: kolumna bez wiersza tutaj to kolumna, która
prawdopodobnie przepada.

Kontrast nowych elementów policzony liczbowo (WCAG 2.2 AA, próg 4.5:1 dla małego tekstu):
badge 6.87:1 w wariancie jasnym i 9.40:1 w ciemnym, licznik `+N` 5.74:1 w najgorszym przypadku
(biała fotografia pod `bg-black/60`). Uwaga przy przeliczaniu: tło ciemnej karty to
`--color-dark-bg-raised: oklch(20% 0.01 250)` = rgb(19,22,26)
(`resources/css/design-tokens.css:68`), **nie** czerń — przyjęcie `#000000` zawyża wynik.

**Znane ograniczenie, osobne zadanie (ClickUp `123k99ct3xt`):** dodanie oddziału **nie** sprawia,
że pojawia się on na stronie. Blok „Siatka treści" trzyma ręcznie wybraną listę identyfikatorów
(`content_items`, `->multiple()->required()` w `app/Filament/Support/BuilderBlocks.php:532-537`)
i **nie ma trybu „wszystkie"**. Odtworzone na tenancie `qatest`: aktywna lokalizacja z kompletem
danych nie renderuje się na `/nasze-oddzialy`, bo nie została dopisana do bloku. Nic o tym
nie informuje.

### `service_location_stocks` (nowa) — kotwica

Ile sztuk danego sprzętu stoi w danym oddziale. **Jednocześnie punkt blokady** przy zapisie.

`organization_id`, `service_id`, `location_id`, `quantity`, `is_active`
UNIQUE `(service_id, location_id)`, indeks `(location_id, service_id)`

#### Zaimplementowane w Fazie 2 — decyzje, które kosztowały dwa blokery

**Klucze obce.** `location_id` i `organization_id` → `cascadeOnDelete`, `service_id` → **`cascadeOnDelete`**.

`location_id` nie może być `restrictOnDelete`: `locations.organization_id` jest już kaskadą (Faza 1),
więc usunięcie organizacji uruchomiłoby **dwie siostrzane kaskady z tego samego wiersza rodzica**
(`organizations→locations` i `organizations→service_location_stocks`) bez gwarantowanej kolejności
między nimi. Gdyby silnik skasował `locations` pierwsze, `restrict` odrzuciłby całe usunięcie
organizacji. `cascade` zamienia to w prawdziwą kaskadę wielopoziomową, odporną na kolejność.

`service_id` dostało w pierwszej wersji `restrictOnDelete`, skopiowane z `rentals.service_id` — i to
był **bloker**. `rentals` i `order_items` chronią **rekordy prawne** (retencja 5-6 lat, art. 112
ustawy o VAT). Wiersz stanu magazynowego to żywa liczba bez wymogu retencji. Skutek pomyłki:
`handle()` materializuje wiersz kotwicy przy **każdym** zapisie formularza usługi, więc praktycznie
każda usługa wynajmu przestawała być usuwalna, a `DeleteAction` nie obsługuje `QueryException` —
admin dostawał surowy błąd zamiast działającego przycisku.

> **Reguła:** zanim skopiujesz politykę `onDelete` z sąsiedniej tabeli, sprawdź, **co ta tabela
> chroni**. Klasyfikacja z `.claude/rules/migrations.md` (rekord prawny vs dane operacyjne) jest
> tu jedynym kryterium — nie podobieństwo nazwy kolumny.

**UNIQUE `(service_id, location_id)` świadomie bez `organization_id`.** Oba to klucze obce, każdy
jednoznacznie należy do jednej organizacji, więc integralność referencyjna **jest** tu
tenant-scopingiem — inaczej niż przy `locations.slug`, gdzie unikalny ma być dzielony string.
Audyt bezpieczeństwa zweryfikował to, sprawdzając **wszystkie** ścieżki zapisu, a nie przyjmując
na słowo: w każdej `location_id` pochodzi z zapytania jawnie zawężonego do organizacji tej samej
usługi.

#### Niezmiennik mirrora — i dlaczego łatwo go złamać

`quantity_total` jest **mirrorem** `SUM(service_location_stocks.quantity)`. Drugi bloker Fazy 2
wziął się z tego, że **kwalifikacja pola i suma mirrora liczyły co innego**:

| Element | Co liczył |
|---|---|
| `tenantHasExactlyOneActiveLocation()` | tylko lokalizacje `is_active = true` |
| `recalculateQuantityTotal()` | **wszystkie** wiersze stanu, także na dezaktywowanych |

Dezaktywacja oddziału ze stanem sprawiała, że tenant „wyglądał" na jednooddziałowego, pole
„Ilość w magazynie" wracało jako edytowalne z pełną sumą, a zapis kazał wierszowi głównemu
**wchłonąć cudzy stan** — po czym przelicznik doliczał osierocony wiersz jeszcze raz.
`8 → 11 → 14 → …` przy samym klikaniu „Zapisz", bez dotykania stanu.

> **Niezmiennik, przypięty testem:** żadna sekwencja akcji w panelu nie może zmienić
> `SUM(service_location_stocks.quantity)` poza jawną edycją stanu. Zapis formularza **bez zmiany
> wartości** musi być idempotentny.

Naprawa: guard w `handle()` **oraz** ta sama reguła w kwalifikacji pola, przez jedno wspólne
źródło prawdy. Sam guard nie wystarcza — pole zostałoby włączone i **dehydrowane**, więc Eloquent
i tak wpisałby liczbę do `quantity_total`, podczas gdy routowanie by odmówiło, i mirror
rozjechałby się z sumą. To gorsze niż inflacja, bo dostępność czyta `quantity_total` **dosłownie
już dziś**, nie dopiero po Fazie 4.

**Pułapka Filamenta:** `disabled()` **nie wystarcza** — Filament domyślnie **dehydruje pola
wyłączone**. Bez `dehydrated(false)` zapis formularza po cichu nadpisałby rozbity stan per oddział
zagregowaną wartością z ukrytego pola.

### `service_units` (nowa) — egzemplarze

`organization_id`, `service_id`, `location_id`, **`identifier`** (nie `serial_number` — patrz
niżej), `inventory_number`, `status` (`available` / `maintenance` / `in_transit` / `retired`),
`acquired_at`, `notes`
UNIQUE `(organization_id, identifier)`

Jeden `location_id`, nie para „macierzysta/bieżąca" — bo zwrot idzie **zawsze** do oddziału
wydania, więc te dwie wartości rozjeżdżałyby się wyłącznie na czas świadomego przeniesienia,
co pokrywa status `in_transit`.

`status = 'maintenance'` zdejmuje sztukę z kotwicy. Dziś jedynym wyłącznikiem jest `is_active`
na **całej** usłudze (`app/Models/Service.php:148`) — all-or-nothing.

**Nazewnictwo kolumny — `identifier`, nie `serial_number`.** Ta sekcja mówiła wcześniej
`serial_number`; to była rozbieżność z `plan-wdrozenia.md`, który jest tu rozstrzygający
(sekcja „Nazewnictwo kolumny — rozstrzygnięcie"). Powód: to jest **własne oznaczenie firmy**
(„KOP-04" na naklejce), nie numer seryjny producenta — stąd `identifier`, pole tekstowe bez
wymuszonego formatu, nullable.

#### Zaimplementowane w krokach 3.1-3.3 (2026-09-08, gałąź `feature/lokalizacje-faza3-egzemplarze`)

`database/migrations/2026_09_08_090000_create_service_units_table.php` (schemat) +
`2026_09_08_090001_generate_service_units_from_quantity_total.php` (generator) +
`App\Models\ServiceUnit` + `App\Enums\ServiceUnitStatus` + `App\Observers\ServiceUnitObserver`
(rejestrowany w `AppServiceProvider` obok `ServiceLocationStockObserver`).

**Status jako plain `string` + PHP enum, nie `$table->enum()`.** W całym repo nie ma ani
jednego `$table->enum(` (zweryfikowane grepem przed napisaniem migracji) — `.claude/rules/
tests.md`'s sekcja „MySQL 8.0 gate" tłumaczy dlaczego: SQLite nigdy nie egzekwuje realnego
ENUM-a, więc literalna kolumna enum ujawniłaby rozjazd dopiero na bramce MySQL, a istniejące
kolumny statusowe (`orders.status`, `rentals.status`) już używają `string()` + cast na
`BackedEnum`.

**UNIQUE `(organization_id, identifier)` z wieloma `NULL`-ami — zweryfikowane, nie założone.**
SQLite i MySQL obie traktują `NULL` jako różny od każdego innego `NULL` w indeksie unikalnym —
to standardowe zachowanie SQL, nie idiosynkrazja sterownika, i nie jest jednym z rozjazdów,
przed którymi ostrzega `tests.md` (te dotyczą ENUM-ów, kolejności kluczy JSON, `migrate:rollback
--path`). Test wykonywalny:
`CreateServiceUnitsTableMigrationTest::test_multiple_units_without_an_identifier_do_not_collide()`.

**Obserwator (krok 3.2) — pełny przelicznik, nie inkrementacja.** `ServiceUnitObserver` liczy
`COUNT(service_units WHERE service_id = S AND location_id = L AND status = 'available')` na
nowo przy każdym create/update/delete jednostki, tym samym wzorcem co
`Service::recalculateQuantityTotal()` (SUM, nie `+1`/`-1`) — odporne na pominięty przypadek
brzegowy, bo każdy kolejny zapis tej pary samoleczy się do prawdy. Przy `update()` zmieniającym
`location_id` lub `status` przelicza **obie** pary (starą i nową) — pominięcie starej
zostawiłoby jej kotwicę trwale zawyżoną o jednostkę, której już tam nie ma. Po przeliczeniu
kotwicy woła też `Service::recalculateQuantityTotal()` na tym samym serwisie, w tej samej
transakcji — bez tego mirror `quantity_total` rozjechałby się z sumą anchorów natychmiast po
pierwszym zapisie jednostki, a `getAvailableQuantity()` czyta `quantity_total` **dosłownie**
już dziś (Zasada 2), nie dopiero po Fazie 4.

**`is_active` na `service_location_stocks` — świadomie NIETKNIĘTE przez ten obserwator.**
Rozstrzygnięcie: `is_active` to przełącznik operatora „czy ten oddział w ogóle sprzedaje ten
produkt", nieczytany dziś przez żadną logikę (patrz sekcja Fazy 2 wyżej). Wysłanie WSZYSTKICH
jednostek usługi na serwis w danym oddziale i tak poprawnie komunikuje „zero dostępnych teraz"
przez samo `quantity = 0` z przelicznika — dopisanie do tego automatycznego przestawienia
`is_active = false` zlałoby dwa różne pytania („chwilowo zero" vs „nie oferujemy tu wcale") w
jedno i byłoby realną zmianą zachowania dla przyszłego kodu, który kiedyś zacznie czytać
`is_active` — nie neutralnym no-opem. Test:
`ServiceUnitObserverTest::test_sending_the_only_unit_to_maintenance_does_not_touch_the_anchors_is_active_flag()`.

**Generator (krok 3.3) — ryzyko sprawdzone, nie pominięte.** Dosłowne wykonanie instrukcji
(„z `quantity_total` twórz N egzemplarzy w oddziale domyślnym") koliduje z tenantem, który ma
**już** rozbity stan na więcej niż jeden oddział: wszystkie N jednostek trafiłyby do oddziału
głównego, obserwator nadpisałby jego kotwicę całym `quantity_total`, a stan pozostałych
oddziałów zostałby z liczbą bez żadnych fizycznych jednostek za nią. Migracja generatora dostała
więc dodatkowy guard (`hasStockOutsidePrimary`) — usługa, której stan jest już rozbity na więcej
niż oddział główny, jest **pomijana całkowicie**, nie kolidowana. **Nie dotyczy dziś żadnego
realnego tenanta** (0/8 ma `multi_location_stock` włączone — Faza 2), więc to zabezpieczenie
przed przyszłością, nie naprawiony bug. Redystrybucja jednostek dla faktycznie rozbitych
tenantów zostaje jawnie **poza zakresem** tego kroku — należy do przyszłych kroków 3.4/3.5.

Idempotencja generatora (migracja nie może zdublować jednostek przy ponownym uruchomieniu) nie
opiera się na UNIQUE `(organization_id, identifier)` — każdy wiersz z tego generatora ma
`identifier = NULL`, więc ograniczenie unikalności nigdy by tego nie złapało (patrz wyżej: wiele
`NULL`-i współistnieje). Strażnikiem jest sprawdzenie na poziomie usługi: usługa, która ma
**jakikolwiek** wiersz `service_units`, jest pomijana w całości, nigdy niedopełniana.

Testy: `tests/Feature/Database/CreateServiceUnitsTableMigrationTest.php`,
`tests/Feature/Database/GenerateServiceUnitsFromQuantityTotalMigrationTest.php`,
`tests/Unit/Models/ServiceUnitTenantIsolationTest.php`,
`tests/Feature/Organizations/ServiceUnitObserverTest.php`.

**Niezweryfikowane w tym kroku:** zachowanie FK `cascadeOnDelete` na realnym MySQL (dowiedzione
tylko na SQLite lokalnie, tak jak Faza 2 — bramka MySQL w CI jest jedynym miejscem, które to
faktycznie sprawdza); pełny scenariusz twardego usunięcia organizacji przez `service_units`
(Faza 2 ma na to dedykowany `ServiceLocationStockCascadeDeletionTest` przez prawdziwy model
`Organization` — dla `service_units` nie napisano odpowiednika w tym kroku, tylko testy FK na
poziomie samej tabeli); wpływ na panel/RelationManager (kroki 3.4+ — nietworzone tutaj).

#### Zaimplementowane w krokach 3.6-3.7 (2026-09-08, ta sama gałąź) — `order_items.service_unit_id`

`database/migrations/2026_09_08_100000_add_service_unit_id_to_order_items_table.php` dodaje
**`order_items.service_unit_id`** (nullable, `nullOnDelete`) — kolumna FK, nie tabela pośrednia.
Uzasadnienie: krok 2 tej fazy (patrz „Ilość > 1 — rozstrzygnięcie" wyżej) sprawił, że **jedna
pozycja zamówienia = jeden egzemplarz zawsze i wszędzie**, więc tabela pośrednia miałaby sens
tylko wtedy, gdyby jedna pozycja mogła nieść wiele sztuk — a nie może.

**`nullOnDelete`, nie `restrictOnDelete`** — mimo że `order_items` jest rekordem prawnym
(retencja Art. 112 VAT, `.claude/rules/migrations.md`'s tabela klasyfikacji FK). Rozstrzyga
**która strona FK jest chroniona**: `order_items` to legalny rekord, `service_units` to po drugiej
stronie tego konkretnego FK dane operacyjne bez wymogu retencji. `restrictOnDelete` uczyniłoby
egzemplarz **trwale nieusuwalny** od pierwszego wydania na cały okres retencji zamówienia (5-6
lat) — dokładnie ten sam błąd, co „Faza 2's code-reviewer BLOKER 2" opisany wyżej przy
`service_units.service_id`. Precedens z `.claude/rules/migrations.md`'s tabeli FK onDelete Policy:
`appointments.staff_id -> nullOnDelete` dla identycznego kształtu problemu.

Izolacja tenanta dla tego FK **nie idzie przez schemat** — `order_items` nie ma własnej kolumny
`organization_id` (izolacja przez JOIN na zamówieniu, jak w `OrderItem::scopeBlockingAvailability()`).
Egzekwuje ją `OrderService::handOver()`/`completeReturn()` przez `resolveUnitForItem()`: `service_unit.
service_id === order_item.service_id` (immutable, więc tranzytywnie pina tenanta) **oraz**
`service_unit.organization_id === order.organization_id` jako tani, nadmiarowy check.

**Egzemplarz wypożyczony pozostaje `available`** (Invariant A, Zasada 5 wyżej) — przypisanie
zapisuje WYŁĄCZNIE `order_items.service_unit_id`, nigdy `service_units.status`/`location_id`.
Konflikt (ta sama sztuka, nakładające się terminy) sprawdza nowy scope
`OrderItem::scopeAssignedToUnitOverlapping()`, ograniczony do zamówień w stanie `confirmed`/
`in_progress` — `completed` jest świadomie wykluczone, bo zwrócona sztuka wraca do puli.

**Numer nadawany przy wydaniu** (plan-wdrozenia.md krok 3.6: „Jeśli wybrana sztuka nie ma jeszcze
numeru — pracownik wpisuje go na miejscu") — `OrderService::handOver()`'s
`assignIdentifierIfMissing()` wypełnia `service_units.identifier` TYLKO gdy jest `NULL`, nigdy nie
nadpisuje istniejącego numeru, i sam sprawdza kolizję z UNIQUE `(organization_id, identifier)`
przed zapisem (czytelny komunikat zamiast surowego `QueryException`).

**Ślad „kto przyjął i czy sztuka się zgadzała"** (wymóg właściciela produktu, potwierdzony wprost
w zgłoszeniach ClickUp 123k99cu2b3/123k99cu2b4) nie dostał nowej kolumny: `OrderItem::$auditInclude`
(`app/Traits/Auditable.php`) loguje każdą zmianę `service_unit_id` z `user_id`, a
`state_histories.custom_properties` (mechanizm biblioteki stanu, już istniejący dla
`responsible_id`/`responsible_type`) niesie przy każdym przejściu `in_progress`/`completed`
strukturalny opis niezgodności — `['mismatch_confirmed' => bool, 'mismatches' => [['order_item_id',
'service_name', 'handed_out_unit_id', 'handed_out_label', 'returned_unit_id', 'returned_label'],
...]]` — czyli **oba numery, per pozycja**, obok automatycznie zapisanego `responsible_id` tej samej
transakcji stanu. Przy zwrocie niezgodność **nie blokuje** zwrotu (zgłoszenie 123k99cu2b4: „twarda
blokada uniemożliwiłaby zamknięcie takiego wypożyczenia i zmusiła pracownika do obchodzenia
systemu") — wymaga jawnego potwierdzenia (`mismatch_confirmed`), ale w odróżnieniu od siostrzanego
`amount_mismatch_confirmed` na `record_offline_payment` (tam: informacyjny checkbox, egzekwowanie
tylko w serwisie) TU checkbox ma `->rule('accepted')` — Filament odrzuca zapis formularza od razu,
zanim żądanie w ogóle dotrze do `OrderService::completeReturn()` — bo zgłoszenie wprost wymaga
„nie może dać się kliknąć dalej przypadkiem". Egzekwowanie w serwisie zostaje jako druga, niezależna
warstwa (ten sam formularz teoretycznie dałoby się ominąć inną ścieżką wywołania metody serwisu).

**Numer przy zwrocie — sugerowany, nigdy wymuszony** (zgłoszenie 123k99cu2b4, przypadek brzegowy
zostawiony do rozstrzygnięcia): gdy wydany egzemplarz nie ma numeru, nie ma czego porównać z tym,
co wraca. `ServiceUnitAssignmentForms::returnFields()` pokazuje wtedy to samo opcjonalne pole co
przy wydaniu (`return_identifiers.{itemId}`), a `OrderService::completeReturn()`'s
`assignIdentifierIfMissing()` — ten sam prywatny helper co przy wydaniu — wypełnia numer TYLKO
jeśli faktycznie wybrany egzemplarz go nie ma.

Cztery miejsca UI (`OrderResource.php`'s `mark_in_progress`/`complete`, `EditOrder.php`'s te same
dwie akcje nagłówkowe) dzielą jeden schemat formularza
(`App\Filament\Resources\OrderResource\Support\ServiceUnitAssignmentForms`) i wołają wyłącznie
`OrderService::handOver()`/`completeReturn()` — żadnej logiki domenowej w samym Filamencie.

Testy: `tests/Unit/Services/OrderServiceUnitAssignmentTest.php` (33 przypadki, domena),
`tests/Feature/Filament/OrderServiceUnitAssignmentFilamentActionTest.php` (9 przypadków,
dowód że wszystkie cztery miejsca UI zachowują się identycznie, w tym że `mismatch_confirmed`
faktycznie blokuje zapis na poziomie Filamenta — `assertHasActionErrors`/`assertHasTableActionErrors`,
nie tylko przechwycony wyjątek serwisu).

#### KRYTYCZNA poprawka po code review (2026-09-08) — anulowanie po wydaniu nie zwalniało sztuki

`OrderItem::scopeAssignedToUnitOverlapping()` blokowała pierwotnie tylko statusy `confirmed`/
`in_progress`. `OrderService::cancel()` wprost dopuszcza anulowanie zamówienia `in_progress`
(wyjątkowy przypadek: wymuszony offboarding tenanta) i **nie czyści** przypisania egzemplarza —
takie zamówienie wypadało więc z zakresu sprawdzenia, a sztuka wyglądała na wolną. Recenzent
odtworzył to scratch-testem: wydanie → anulowanie z `in_progress` → drugie wydanie tej samej
sztuki na nakładający się termin **przechodziło**. Dwóch klientów, jeden ponumerowany egzemplarz,
dwa podpisane protokoły.

**Naprawa oparta na zweryfikowanym fakcie** (grep całego `app/`+`database/` przed napisaniem
poprawki): `service_unit_id` jest zapisywane WYŁĄCZNIE w `OrderService::handOver()` i
`::completeReturn()`. Skoro tak, obecność niepustego `service_unit_id` na zamówieniu w statusie
innym niż `completed`/`refunded` **zawsze** opisuje sprzęt, który wyszedł i nie wrócił —
niezależnie od tego, dlaczego zamówienie przestało iść naprzód. Reguła: blokują wszystkie
statusy poza `completed` i `refunded` (`whereNotIn`, nie `whereIn` na wąskiej liście).

**`refunded` wykluczone RAZEM z `completed`, nie samo `completed`** — doprecyzowanie względem
pierwotnego sformułowania recenzenta („wszystkie poza completed"): maszyna stanów osiąga
`refunded` WYŁĄCZNIE z `completed` (`OrderStatusStateMachine::transitions()`), czyli fizyczny
zwrot już nastąpił przy przejściu w `completed` — późniejszy zwrot pieniędzy nie może na nowo
zablokować sprzętu, który już wrócił.

**Świadoma cena, nieodwracalna dziś:** sztuka z anulowanego-po-wydaniu zamówienia zostaje
zablokowana na swoje okno dat i **nie ma dziś ścieżki jawnego zwolnienia**. To przyjęty
kompromis (decyzja właściciela produktu za pośrednictwem code review) — zablokowany sprzęt da
się odblokować ręcznie (support), dwóch klientów z protokołem na to samo urządzenie już nie.
**Nie „naprawiaj" tego przez rozluźnienie filtra statusów** bez dodania najpierw jawnej akcji
zwolnienia.

#### Blokady na `ServiceUnit` (dodane w tej samej poprawce)

`OrderService::resolveUnitForItem()` blokuje teraz wiersz `ServiceUnit` (`lockForUpdate()`) w tej
samej transakcji, w której zamówienie jest już zablokowane — bez tego dwóch pracowników wydających
tę samą sztukę w tej samej chwili piszą do RÓŻNYCH wierszy `order_items`, więc żaden unique ich nie
zatrzymuje i oboje widzą „jeszcze nieprzypisana". Kolejność blokad: `handOver()`/`completeReturn()`
sortują przypisania po `unit_id` rosnąco PRZED pętlą (`OrderService::sortAssignmentsByUnitId()`) —
gdy jedno zgłoszenie dotyka więcej niż jednej sztuki, każda współbieżna transakcja w tym kodzie
blokuje je w tej samej globalnej kolejności, co wyklucza zakleszczenie między dwoma zgłoszeniami
dotykającymi tych samych dwóch sztuk w odwrotnej kolejności (ten sam problem co
`rental-availability.md` §3, zastosowany do innej tabeli). Brak dedykowanego testu
dwupołączeniowego na realnym MySQL dla tego konkretnego locka — ten sam brak co reszta
tego kroku, `tests/Concurrency/` nie ma dziś scenariusza per-unit.

#### `order_items.service_unit_identifier_snapshot` (dodane w tej samej poprawce, migracja NIEURUCHOMIONA na dev-MySQL)

`service_unit_id` jest `nullOnDelete` — słusznie (patrz uzasadnienie tej kolumny wyżej), ale to
znaczy, że po usunięciu egzemplarza sam FK **nie pozwala odtworzyć**, że wydano „KOP-04": audyt
loguje wyłącznie liczbowe `service_unit_id`, a wiersz, do którego on wskazywał, może już nie
istnieć. Krok 3.8 wstawia numer na protokół — dokument, który klient podpisuje — więc nie może
zależeć od relacji, która może zniknąć.

`database/migrations/2026_09_08_110000_add_service_unit_identifier_snapshot_to_order_items_table.php`
dodaje nullable `order_items.service_unit_identifier_snapshot`, wypełniany w TEJ SAMEJ operacji
`update()` co `service_unit_id` — w `handOver()` przy pierwszym przypisaniu i w `completeReturn()`
przy potwierdzonej niezgodności. Dokładnie ten sam wzorzec, jakiego ten model już używa dla
`service_name` i `price_snapshot` (kopia punktu-w-czasie obok FK, nie zamiast niego). **Migracja
NIE została uruchomiona na dev-MySQL** — team-lead zdecyduje kiedy i gdzie; ćwiczona dotąd
wyłącznie przez efemeryczny SQLite `RefreshDatabase` w testach.

**Otwarte pytanie, poza zakresem kroków 3.6/3.7:** brak guardu pokrycia (blokady zdjęcia
pojemności spod przyjętej rezerwacji) — świadomie odłożone do kroku 7.3, jak w planie.

### `stock_movements` (nowa) — księga ruchu

`organization_id`, `service_id`, `service_unit_id`, `from_location_id`, `to_location_id`,
`quantity`, `reason`, `user_id`, `notes`

Przeniesienie sprzętu między oddziałami to **wpis w księdze**, nie destrukcyjny UPDATE dwóch
wierszy. Bez tego nie da się odpowiedzieć „kto przeniósł, kiedy i ile było na stanie miesiąc temu".

### `location_user` (nowa, pivot)

`location_id`, `user_id`, `is_primary`; UNIQUE `(location_id, user_id)`

**Nie `users.branch_id`.** Tabela `users` nie ma `organization_id` (migracja
`2026_03_08_000003` świadomie ją pomija), a użytkownik może należeć do wielu organizacji przez
pivot `organization_user`. Kolumna na `users` złamałaby ten model i dotknęłaby też klientów
i super-adminów.

### Zmiany w istniejących tabelach

| Tabela | Zmiana | Status |
|---|---|---|
| `rentals` | `+ location_id` **nullable**, indeks `(service_id, location_id, start_date, end_date)`, FK `nullOnDelete` | ✅ krok 4.8 (2026-09-09) |
| `order_items` | jw. | ✅ krok 4.8 (2026-09-09) |
| `cart_items` | `+ location_id` nullable, sama kolumna + indeks, FK `nullOnDelete` (CartItem nie blokuje dostępności — patrz kontrakt-dostepnosci.md) | ✅ krok 4.8 (2026-09-09) |
| `carts` | `+ location_id` — **oddział na koszyku, nie na pozycji** | ⬜ Faza 6 krok 6.1 |
| `orders` | `+ pickup_location_id` + snapshoty `pickup_location_name` / `_address` | ⬜ Faza 6 krok 6.3 |
| `statistics_daily_snapshots` | `+ location_id NOT NULL DEFAULT 0`, UNIQUE rozszerzony | ⬜ Faza 9 |
| `services` | **bez zmian schematu** — `quantity_total` zostaje jako mirror | — |

Backfill (krok 4.8, migracja `2026_09_09_090003_backfill_location_id_for_open_reservations.php`):
każdą **otwartą** rezerwację (dokładnie ten sam warunek, który dziś blokuje dostępność —
`RentalStatus::blocksAvailability()` dla `rentals`, `OrderItem::scopeBlockingAvailability()`'s
logika dla `order_items`, `carts.status = 'active'` dla `cart_items`) przypisuje do oddziału
głównego organizacji, per organizacja (nie per wiersz) — każdy tenant ma dokładnie jeden
`primary_slot` dzięki niezmiennikowi z Fazy 1. `down()` to celowy no-op (ten sam wzorzec co
`2026_08_28_090001` dla kotwicy) — cofnięcie realnie odbywa się przez `down()` trzech migracji
schematu powyżej, które usuwają kolumnę w całości.

Kolumny `location_id` zostają **nullable na stałe**. Wymuszanie `NOT NULL` w środku planu byłoby
krokiem jednocześnie nieodwracalnym, niepodzielnym i umieszczonym w środku — a `.claude/rules/migrations.md`
wprost ostrzega przed ślepym przywracaniem `NOT NULL` w `down()`.

`statistics_daily_snapshots.location_id` przeciwnie — **musi** być `NOT NULL DEFAULT 0`
(sentinel). W MySQL `NULL != NULL` w UNIQUE, więc wiersze „bez oddziału" duplikowałyby się przy
każdym godzinowym przebiegu, a przychód rósłby liniowo.

## `quantity_total` — co się z nim dzieje

Zostaje. Staje się **mirrorem** `SUM(service_location_stocks.quantity)`, przeliczanym w tej samej
transakcji na już zablokowanym wierszu.

Powód jest czysto praktyczny: `quantity_total` występuje w ~77 miejscach w testach, 10 w `app/`,
w Blade i w publicznym kontrakcie API (`total_quantity`). Usunięcie go zamieniłoby ten projekt
w refaktor całego repo.

**Uwaga:** niezmiennik trzyma się dyscypliną kodu, nie constraintem DB. Każda ścieżka pisząca
`quantity_total` wprost (m.in. `ServiceFactory`) musi zostać przejrzana.
