# Blok „Siatka treści" — co widzi klient (ClickUp 123k99cu26t, 2026-10-04)

Blok `content_grid` trzyma ręcznie wybraną listę identyfikatorów (`content_items`). Do 2026-10-04
`ContentGridResolver::resolveItems()` robił `whereIn('id', $ids)` **bez żadnego filtra widoczności**:
wyłączony oddział (a także usługa, wpis, promocja, realizacja) dalej renderował się na stronie, jeśli
był wcześniej wybrany. Klient mógł pojechać do zamkniętego oddziału.

## Kontrakt

Jedna definicja „widoczne dla publiczności" per typ: `ContentGridResolver::visibleQuery()`. Używają jej
render (`resolveItems()` — **jedyne** miejsce, które decyduje, co dotrze do klienta) i lista wyboru
(`optionsForType()`, które według niej tylko **oznacza** pozycje — patrz niżej). Każdy typ używa scope'a, który jego własna trasa publiczna
już egzekwuje, żeby karta w siatce nigdy nie prowadziła w 404:

| Typ | Model | Widoczne gdy | Zgodne z |
|---|---|---|---|
| `services` | `Service::visibleOnSite()` | `is_active` **i** (time_slot: `published_at <= now` / item_rental: nic więcej) | `ServiceController::index/show`, `SitemapBuilder` (te dwa teraz też używają scope'a) |
| `posts` | `Post::published()` | `published_at` ustawione i `<= now` | `PostController` |
| `promotions` | `Promotion::activeAndValid()` | `active` **i** w oknie `valid_from`/`valid_until` | `PromotionController` |
| `portfolio` | `PortfolioItem::published()` | `published_at` ustawione i `<= now` | `PortfolioController` |
| `locations` | `Location::active()` | `is_active` | `LocationContext::activeLocations()` |

Nie ma „blanket `is_active`": Post/Portfolio nie mają takiej kolumny (wyznacza je `published_at`),
Promotion ma i `active`, i okno dat, a usługa zależy od typu.

## Zasady

- **Filtr jest przy renderze, dane bloku nietknięte.** Ukryty identyfikator zostaje w `content_items`;
  ponowne włączenie / publikacja przywraca kartę bez ponownego wybierania.
- **Kolejność = kolejność wyboru admina** (`CASE WHEN id = ? THEN n`, zgodne z SQLite), po filtrze.
- **Wszystko ukryte (albo nic nie wybrano) = blok nie renderuje nic** — ani sekcji, ani nagłówka.
  Nagłówek nad pustą siatką wygląda na zepsuty, a dotychczasowe żółte okienko „Brak elementów" było
  komunikatem dla redaktora, który trafiał do klienta. Klucze `storefront.cms.no_items` /
  `items_missing` usunięte z obu języków.
- **Tenant:** zakres dają globalne scope'y `BelongsToOrganization` (żądanie HTTP). Resolver nie używa
  `withoutGlobalScope`.
- **Lista wyboru oferuje wszystko, co należy do tenanta; niewidoczne oznacza.** Pozycje widoczne idą
  pierwsze, bez dopisku; pozostałe dostają `ContentGridResolver::NOT_VISIBLE_SUFFIX` =
  „ (niewidoczny na stronie)". **Jedno znaczenie dla każdego typu:** strona nie pokazuje tej pozycji
  *teraz* — wyłączona, szkic, zaplanowana na później albo poza oknem dat promocji. Wybranie jej jest
  dozwolone (przygotowanie strony z wyprzedzeniem: promocja na przyszły tydzień, zaplanowany wpis —
  karta pojawi się sama, gdy pozycja stanie się widoczna), ale `resolveItems()` nie wyrenderuje jej
  wcześniej. Nie są oferowane: wiersze innego tenanta (global scope) i usunięte.
- **Dlaczego też techniczny powód:** Filament waliduje stan multi-selecta regułą `in` względem opcji.
  Gdyby opcje były tylko widocznym zbiorem, strona z oddziałem wyłączonym *po* wybraniu **nie dałaby się
  zapisać**, a błąd nie wskazywałby żadnego elementu (brak chipa do usunięcia). Zmierzone przed
  poprawką: `content_items.0` i `.1` odrzucone przy zapisie niezwiązanej zmiany; błąd istniał już dla
  usług i oddziałów. Pozostałość: id **usuniętego** wiersza, który został w bloku, nadal blokuje zapis
  (nie ma go w opcjach i nie ma chipa) — usuń i zapisz blok od nowa.

- **Dane bloku są nieufne przy renderze.** `resolveItems()` przyjmuje `mixed`: nie-tablica w `content_items`
  albo nie-string w `content_type` daje pusty wynik (blok nie renderuje nic), nie 500 na publicznej stronie.
  Elementy nienumeryczne są odrzucane, duplikaty usuwane, a lista **ucięta do `MAX_ITEMS` = 100**: siatka
  ponad 100 kart nie jest realnym układem, a limit trzyma zapytanie (`whereIn` + 2 bindingi CASE na id) daleko
  poniżej limitu 65 535 parametrów MySQL. Ucięcie dotyczy tylko renderu — zapisane dane bloku zostają.
- **Koszt listy wyboru:** Filament woła closure opcji kilka razy na żądanie na blok (render, reguła `in`,
  etykiety chipów), więc `optionsForType()` to **jedno zapytanie na typ** z wąskim `select` (etykieta + kolumny
  widoczności, bez `body`/`content`/JSON), podział widoczne / niewidoczne w PHP (`isVisible()` — bliźniak
  `visibleQuery()`, parytet pinuje test kontraktu na granicach dat) i sortowanie po etykiecie w SQL (kolacja
  MySQL daje poprawny porządek polskich liter). Było 3 zapytania (`pluck` + dwa `get()` z pełną hydracją
  i `whereNotIn` z placeholderem na każdy widoczny wiersz).
- Przyjęta kolejność w liście: najpierw widoczne, potem niewidoczne, w każdej grupie alfabetycznie. Dla
  oddziałów oznacza to sortowanie po nazwie zamiast po `sort_order`.

## Effect on existing pages (po wdrożeniu — to NIE jest regresja)

Od wdrożenia (rc39) każda siatka wskazująca na usługę **aktywną, ale nieopublikowaną** (`is_active = true`,
`service_type = time_slot`, `published_at` puste lub w przyszłości) **przestanie ją renderować**. Jeśli to była
jedyna wybrana pozycja, cały blok zniknie ze strony (nie renderuje nic). To zamierzone: strona szczegółów takiej
usługi zwraca dziś 404 (`ServiceController::show`), więc karta prowadziła w ślepy zaułek. To samo dotyczy
wyłączonych oddziałów, nieopublikowanych / zaplanowanych wpisów i realizacji oraz promocji spoza okna dat —
wcześniej renderowały się mimo to. Jeśli właściciel zgłosi „zniknęła sekcja", sprawdź najpierw widoczność
wybranych pozycji (w liście wyboru mają dopisek „niewidoczny na stronie"). Seeder strony głównej
(`SeedTenantWebsite`) wybiera teraz `Service::visibleOnSite()`, więc świeżo zasiany blok nie trafia w tę pułapkę.

## Testy

`tests/Feature/Cms/ContentGridVisibilityTest.php` — GET strony CMS na hoście tenanta, per typ, wraz z
kolejnością, ponownym włączeniem, obcym tenantem, „wszystko ukryte", kontraktem pickera (widoczne
bez dopisku, niewidoczne z dopiskiem, obcy tenant i usunięte nieoferowane) i pozycją oznaczoną, która
nie trafia na stronę, dopóki nie stanie się widoczna.
`tests/Feature/Filament/ContentGridHiddenItemSaveTest.php` — zapis strony z ukrytym oddziałem w bloku
(Livewire, `EditPage`).

Test renderuje dwa żądania w jednym teście: `LocationContext` jest `scoped`, więc przed drugim żądaniem
trzeba `$this->app->forgetScopedInstances()` (php-fpm buduje kontener od nowa; aplikacja testowa nie).
