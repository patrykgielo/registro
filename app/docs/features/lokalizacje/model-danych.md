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

`organization_id`, `service_id`, `location_id`, `serial_number`, `inventory_number`,
`status` (`available` / `maintenance` / `in_transit` / `retired`), `acquired_at`, `notes`
UNIQUE `(organization_id, serial_number)`

Jeden `location_id`, nie para „macierzysta/bieżąca" — bo zwrot idzie **zawsze** do oddziału
wydania, więc te dwie wartości rozjeżdżałyby się wyłącznie na czas świadomego przeniesienia,
co pokrywa status `in_transit`.

`status = 'maintenance'` zdejmuje sztukę z kotwicy. Dziś jedynym wyłącznikiem jest `is_active`
na **całej** usłudze (`app/Models/Service.php:148`) — all-or-nothing.

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

| Tabela | Zmiana |
|---|---|
| `rentals` | `+ location_id` **nullable**, indeks `(service_id, location_id, start_date, end_date)` |
| `order_items` | jw. |
| `cart_items` | `+ location_id` nullable |
| `carts` | `+ location_id` — **oddział na koszyku, nie na pozycji** |
| `orders` | `+ pickup_location_id` + snapshoty `pickup_location_name` / `_address` |
| `statistics_daily_snapshots` | `+ location_id NOT NULL DEFAULT 0`, UNIQUE rozszerzony |
| `services` | **bez zmian schematu** — `quantity_total` zostaje jako mirror |

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
