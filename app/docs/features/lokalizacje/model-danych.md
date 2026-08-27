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

### `service_location_stocks` (nowa) — kotwica

Ile sztuk danego sprzętu stoi w danym oddziale. **Jednocześnie punkt blokady** przy zapisie.

`organization_id`, `service_id`, `location_id`, `quantity`, `is_active`
UNIQUE `(service_id, location_id)`, indeks `(location_id, service_id)`

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
