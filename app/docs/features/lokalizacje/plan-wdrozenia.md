# Wielooddziałowość (Lokalizacje) — plan implementacji

---

## Kontekst

Audyt konkurencji (5 wypożyczalni sprzętu budowlanego, sierpień 2026) wskazał **brak pojęcia
oddziału** jako jedyną lukę krytyczną, która zmienia model danych — pozostałe 21 pozycji to
warstwa treści i marketingu. Duże firmy działają wielooddziałowo; bez tego wymiaru Registro nie
jest dla nich kandydatem, niezależnie od tego, jak dobra jest reszta produktu.

Cel: sprzęt stoi w konkretnych oddziałach, klient wybiera oddział jak sklep w Castoramie, stan
zdejmuje się automatycznie z **tego** oddziału i wraca do niego po zwrocie, pracownik jest
przypisany do oddziału, a statystyki i lokalizacje na stronach znają ten wymiar.

Podstawa planu: pomiar kodu przez 7 równoległych sond + 3 niezależne warianty projektowe +
3 sędziów w soczewkach poprawności, produktu i wdrażalności (13 agentów, 2026-08-26).

---

## Decyzje właściciela produktu (2026-08-26)

| Pytanie | Decyzja |
|---|---|
| Kiedy klient wybiera oddział | **Przełącznik w headerze**, zapamiętany, oddział główny domyślnie — bez twardej bramki |
| Zakres zamówienia | **Jedno zamówienie = jeden oddział odbioru** |
| Zwrot | **Zawsze do oddziału wydania** — nigdy do innego |
| Egzemplarze | **Numery seryjne od razu** |

### Tryb jednooddziałowy (klient z jedną, dużą siedzibą)

Nie jest wariantem ani opcją do włączenia — jest **stanem domyślnym**, w którym system po prostu
nie pokazuje niczego zbędnego:

- **Pierwsza dodana lokalizacja automatycznie staje się główną** (`primary_slot = 1`, gwarantowane
  przez UNIQUE w DB, nie przez konwencję). Zmiana głównej później — ręcznie, jednym kliknięciem.
- **Tenant z jedną aktywną lokalizacją nigdy nie widzi przełącznika w headerze.** Kontekst ustawia
  się sam, `LocationContext::selectionRequired()` zwraca `false`, front wygląda jak dziś.
- **W panelu zostaje pole „Ilość w magazynie"** (`ServiceResource.php:270`), w które właściciel
  wpisuje liczbę tak jak dziś — wartość jest po cichu routowana do wiersza stanu siedziby głównej.
  Nie zmuszamy go do przechodzenia na zakładkę „Stany magazynowe".
- Migracja tworzy oddział główny **z danych, które tenant już ma** (`SettingsManager::contactDetailsFor()`),
  więc dzień po wdrożeniu ma poprawny adres bez wpisywania czegokolwiek.

Klient z jedną dużą siedzibą dostaje więc realną wartość (adres, zdjęcie, galeria, godziny otwarcia,
lokalizacja na stronie) **i zero nowych kroków w codziennej pracy**.

---

## Kluczowe rozstrzygnięcie architektoniczne

Decyzja o egzemplarzach zderzyła się z wadą, którą znalazł sędzia poprawności: gdyby pojemność
oddziału liczyć jako `COUNT(egzemplarze WHERE status='available')`, a rezerwacje **nadal**
odejmować z `order_items`, **sprzęt będący u klienta zostałby odjęty dwa razy**. Do tego blokada
przy zapisie przestałaby obejmować jeden wiersz `services`, a zaczęła N wierszy egzemplarzy.

Rozwiązanie — rozdzielenie dwóch pytań, które naiwny model egzemplarzy skleja:

```
GDZIE SPRZĘT MIESZKA i CZY JEST SPRAWNY   →  service_units       (egzemplarz, nr seryjny)
ILE SZTUK STOI W ODDZIALE                 →  service_location_stocks  (kotwica: liczba + blokada)
CZY JEST ZAJĘTY W DANYM OKNIE DAT         →  order_items / rentals    (bez zmian)
```

- Egzemplarz **nie zmienia statusu** na czas wypożyczenia — pozostaje „sprawny, przypisany do
  oddziału X". Zajętość w oknie dat pozostaje wyłącznie w rezerwacjach.
- Dostępność czyta **kotwicę**, nie liczy egzemplarzy w locie. Egzemplarze **utrzymują** kotwicę
  (obserwator, w tej samej transakcji), a nie zastępują jej w ścieżce gorącej.
- Dzięki temu blokada nadal obejmuje **jeden wiersz**, kafelek katalogu nie robi `COUNT` per
  pozycja, a właściciel dostaje numery seryjne, serwis pojedynczej sztuki i przesunięcia.

**Niezmiennik zerowej regresji:** gdy `$locationId === null`, `getAvailableQuantity()` czyta
`services.quantity_total` **dosłownie** — nigdy nowej tabeli. To czyni zdanie „tenant bez
oddziałów zachowuje się identycznie" twierdzeniem o kodzie, a nie o dyscyplinie danych, i chroni
~77 miejsc w testach oraz publiczny kontrakt API `total_quantity`.

**Jedna flaga** (`Organization::hasModule()`, mechanizm już istnieje):

| Flaga | Co włącza | Domyślnie |
|---|---|---|
| `multi_location_stock` | Rozbicie stanu magazynowego i wybór oddziału przez klienta | **OFF** |

Sama **encja lokalizacji flagi nie potrzebuje** — każda wypożyczalnia ma fizyczny adres, a tenant
bez dodanych lokalizacji po prostu nie ma czego wybrać w bloku CMS. Magazyn wielooddziałowy
natomiast włącza się **decyzją operatora, nigdy danymi**: `count() > 1` jest odrzucone, bo admin
dodający drugi adres do wyświetlenia na stronie nie może przypadkiem uruchomić rozbicia magazynu.

---

## Zmierzony stan kodu

### Cztery kopie matematyki dostępności — dwie są martwe

| # | Miejsce | Status |
|---|---|---|
| 1 | `RentalAvailabilityService::getAvailableQuantity()` (`app/Services/RentalAvailabilityService.php:55-97`) | **jedyna prawdziwa**, 9 wywołujących |
| 2 | `getMonthlyAvailability()` (`:105-161`) | kalendarz, nigdy nie blokuje |
| 3 | `Service::availableQuantity()` / `isAvailable()` (`app/Models/Service.php:296-326`) | **0 wywołań produkcyjnych**; pomija status `Held` |
| 4 | `Service::scopeAvailableBetween()` (`Service.php:217-238`) | **0 wywołań produkcyjnych**; raw SQL liczący wyłącznie `rentals`, **ignoruje `order_items`** → zawyża dostępność |

Kopie 3 i 4 są martwe i już dziś rozjechane z prawdą. Usunięcie ich przed czymkolwiek innym
zdejmuje z projektu obowiązek dodawania wymiaru lokalizacji cztery razy.

### Dziewięć wywołań `getAvailableQuantity()`

`RentalBookingController:31` (read-only, publiczne API) · `createHold` (`@deprecated`) ·
`CartService:98`, `:192`, `:464` · **`RentalExtensionService:71`** · `CreateRental:43` ·
`EditRental:43` · `getMonthlyAvailability` (osobna ścieżka).

`RentalExtensionService::checkAvailabilityForExtension()` to dziewiąte miejsce — **łatwe do
przeoczenia i najczęściej pomijane**. Bez przepuszczenia przez nie `$locationId` przedłużenie
wypożyczenia sprzedaje sprzęt z cudzego oddziału.

### Współbieżność — czego dziś nie ma

`RentalAvailabilityService.php:22-53` opisuje **empirycznie zweryfikowany** bug: pod MySQL
REPEATABLE READ lock na wierszu `services` nie odświeża snapshotu dla odczytów z innych tabel.
Stąd kontrakt: na ścieżkach zapisu `forUpdate: true` **oraz** wcześniejszy `Service::lockForUpdate()`.

**Ale:** w repo **nie istnieje ani jeden test dwupołączeniowy**. Wszystkie testy oversellu są
sekwencyjne i przeszłyby także przy skutecznie usuniętym locku (dodatkowo `.env.testing` to
SQLite, który nie ma prawdziwych blokad wierszy). Weryfikacja była ręczna — komentarz
`OrderItem.php:93-95`. **To jest największe ryzyko całego projektu i pierwsza rzecz do naprawy.**

### Rezerwacja — który tor żyje

- **`Order` + `OrderItem` to tor produkcyjny.** `Rental` jest legacy: jedyne `Rental::create()`
  leży w `@deprecated createHold()`. Ale legacy **nadal liczy się do dostępności** (`:69-79`).
- `order_items` **nie ma kolumny `status`** → zwrot jest atomowy na poziomie zamówienia.
- **`CartItem`y nie rezerwują** — `getAvailableQuantity()` ich nie widzi (`CartService.php:140`).
  Koszyk to preferencja aż do checkoutu.
- Koszyk wymaga **zalogowanego** użytkownika; unique `(organization_id, user_id, active_slot)`
  wymusza jeden aktywny koszyk → spójne z „jedno zamówienie = jeden oddział".

### Wielotenantowość

`App\Traits\BelongsToOrganization` — global scope + auto `organization_id` + **fail-closed**.
Nowy model dostaje izolację za darmo: trait + kolumna + `Resource extends BaseResource`.

Pułapki: `BaseResource::canViewAny()` to **deny-by-default** (zasób bez override'a znika nawet
super-adminowi) · unique **musi** zawierać `organization_id` (precedens: migracja
`2026_06_29_120000` naprawiała ten błąd na `service_areas`) · `order_items`/`cart_items` nie mają
`organization_id`, izolacja idzie przez JOIN na rodzicu.

### Uprawnienia

User ↔ Organization **wyłącznie przez pivot** `organization_user`. `users` nie ma
`organization_id` i **nie może dostać `branch_id`** bez złamania modelu „user w wielu
organizacjach". Role Spatie są **globalne** (`config/permission.php:134` → `'teams' => false`).
„Pracownik" nie jest modelem — `EmployeeResource` to filtr na `User` po roli `staff`.

### Front klienta

Brak Livewire (czysty Blade + Alpine) · **brak jakiegokolwiek kontekstu klienta w sesji** ·
**dwie rozbieżne implementacje kafelka** (`services/index.blade.php:37-71` własny markup vs
`x-ios.service-card` w wypożyczalni) · w `service-card.blade.php:40` jest **martwa zmienna**
`$quantityAvailable` gotowa do ożywienia · filtrowanie na listingach nie istnieje (kategorie to
ścieżki URL) · `RentalController::showCategory()` robi `->get()` **bez paginacji**.

**`services/show.blade.php:292` to `<input type="hidden" name="quantity" value="1">`** — klient
nie może dziś zamówić 2 sztuk. „Dostępne 2 szt." bez selektora ilości jest informacją bez akcji.

### Bloki CMS — najlepszy punkt zaczepienia w projekcie

`App\Support\ContentGridResolver` to rejestr typów treści; blok `content_grid` („Siatka treści")
go czyta. Wszystkie **pięć** rendererów bloków delegują do jednego komponentu
`x-content-blocks.content-grid`, który dispatchuje po `$contentType` (`:104`).

Zasilenie lokalizacji = wpis w rejestrze + gałąź `match` + gałąź w komponencie + karta.
**Uwaga:** wszystko poza `services` trafia dziś do `x-cms.card`, który czyta tylko
tytuł/excerpt/obraz — adres i galeria wymagają własnej karty.

### Statystyki

UNIQUE `(organization_id, date, source)`, `source` to **ENUM**, 3 wiersze/dzień/tenant.
`RecalculateDailyStatisticsJob::upsertForOrg()` buduje 3 sztywne wiersze z conflict targetem
zaszytym na tych kolumnach. Dołożenie wymiaru **wymaga** zmiany UNIQUE + przepisania agregacji
na `GROUP BY` — i to jest **nierozbrajalny blok atomowy** w każdym wariancie. Stąd: ostatnia faza.

`location_id` **musi być `NOT NULL DEFAULT 0`** (sentinel), nie nullable — w MySQL `NULL != NULL`
w UNIQUE, więc wiersze „bez oddziału" duplikowałyby się co godzinę, a przychód rósłby liniowo.

---

## Legacy do ponownego użycia

| Co | Gdzie | Jak |
|---|---|---|
| Izolacja tenanta | `app/Traits/BelongsToOrganization.php` | `use BelongsToOrganization` na `Location` — i to wszystko |
| Szkielet zasobu geograficznego | `app/Filament/Resources/ServiceAreas/**` (6 plików) | skopiować 1:1 do `Locations/**` |
| Picker map | `ServiceAreaForm` + `google-maps-picker.blade.php` | **wymaga wariantu** — widok czyta `radius_km`/`color_hex` i rysuje koło zasięgu; oddział to punkt |
| Współrzędne | `service_areas`: `decimal(10,8)`/`decimal(11,8)` + indeks złożony | ten sam kształt |
| Zdjęcie / galeria | `PostResource.php:102` / `PortfolioItemResource` | zdjęcie siedziby / galeria oddziału |
| Rejestr typów treści | `App\Support\ContentGridResolver` | dopisać `locations` |
| Feature flag per tenant | `Organization::hasModule()` | flaga `multi_location_stock`, patrz wyżej |
| Shadow column + UNIQUE | `carts.active_slot` (`2026_07_05_000001:73`) | `locations.primary_slot` → gwarancja DB „dokładnie jedna siedziba główna" |
| Audyt zmian | trait `Auditable` + `$auditInclude` na `Order` | dopisać kolumnę oddziału, inaczej zmiana punktu odbioru nie jest audytowana |
| Ślad „kto wykonał" | `state_histories.responsible_*` (automat z `auth()->user()`) | oddział wynika z przypisania pracownika |

---

## Plan wdrożenia

Każdy krok = jeden temat, jedna weryfikacja, aplikacja działa po każdym. Kryterium akceptacji
podane tam, gdzie nie jest oczywiste.

### Faza 0 — Higiena i dowód (bez nowych funkcji, warunek wstępny)

| # | Krok | Kryterium akceptacji |
|---|---|---|
| 0.1 | Usuń `Service::availableQuantity()`, `isAvailable()`, `scopeAvailableBetween()`; przenieś ich testy na `RentalAvailabilityService` | suite zielony; `grep` potwierdza 0 wywołań poza testami |
| 0.2 | Testy charakteryzujące na **konkretne liczby** dla dzisiejszej dostępności (wszystkie 9 wywołań) | pinują wartości, nie kształt |
| 0.3 | **Harness dwupołączeniowy na MySQL** — dwie równoległe transakcje na tym samym sprzęcie | **test MUSI PAŚĆ po ręcznym usunięciu `forUpdate: true` z `CartService.php:192`. Jeśli nie pada — krok nie jest zrobiony.** |
| 0.4 | **Napraw żywy oversell w `convertToOrder()`** — pętla walidacji nie sumuje ilości już zaakceptowanych w tej samej pętli | test czerwony przed poprawką: koszyk z 3 pozycjami po 1 szt. tej samej usługi przy `quantity_total = 1` **musi zostać odrzucony** |

> Krok 0.3 jest jedynym falsyfikowalnym kryterium w całym planie i jedyną rzeczą, która pozwoli
> udowodnić, że blokada nadal działa po przeniesieniu stanu do kotwicy. Bez niego reszta planu
> jest deklaracją, nie dowodem. Wymaga MySQL — SQLite z `.env.testing` tu nie wystarczy.

> **Krok 0.4 to nie profilaktyka — to naprawa błędu, który dzieje się dziś.** Zgłoszenie
> ClickUp `86cb93tfw` („Zamówienie tego samego produktu mimo dostępnej 1 sztuki"). Przyczyna
> zmierzona: `convertToOrder()` waliduje pozycje w pętli (`CartService.php:183-211`), ale
> `OrderItem::create()` wykonuje się dopiero w `:303` — **po** pętli. Każda iteracja pyta o tę
> samą, niezmienioną pulę i nie dolicza ilości zaakceptowanych wcześniej w tym samym przebiegu.
> `addItem()` nie scala pozycji (`CartService.php:107`), więc ten sam sprzęt tworzy N osobnych
> wierszy `CartItem`. Jeden użytkownik, jedna transakcja, **zero współbieżności** — dlatego
> żaden test sekwencyjny tego nie złapał, a pokrycia dla wielu pozycji tej samej usługi nie ma
> w repo wcale.
>
> Naprawa: agregacja popytu per usługa i nakładające się okno dat **wewnątrz** pętli walidacji,
> zanim zapadnie decyzja. Musi wejść **przed** Fazą 4 — dołożenie wymiaru lokalizacji do wadliwej
> pętli powieli wadę per oddział.

### Faza 1 — Lokalizacja jako encja (wartość natychmiast, zero wpływu na magazyn)

| # | Krok |
|---|---|
| 1.1 | Migracja `locations`: `organization_id`, `name`, `slug`, `code`, adres (`street`, `building`, `postal_code`, `city`), `latitude`/`longitude` nullable, `phone`, `email`, `opening_hours` json, `photo`, `gallery` json, `description`, `is_active`, `sort_order`, **`primary_slot`** + UNIQUE `(organization_id, primary_slot)`, UNIQUE `(organization_id, slug)` |
| 1.2 | Model `Location` + `BelongsToOrganization` + `Auditable`; casty `gallery`/`opening_hours` → array |
| 1.3 | `LocationResource` — 6 plików wg wzorca `ServiceAreas/**`. **Własny `canViewAny()`** (`hasAnyRole(['admin','super-admin'])`), bez `$module` |
| 1.4 | Zdjęcie siedziby + galeria (`FileUpload` wg `PostResource:102` / `PortfolioItemResource`), `directory('locations/{tenantId}/...')` |
| 1.5 | Geolokalizacja: **nowy** `location-map-picker.blade.php` (kopia bez `radius_km`/`color_hex`) |
| 1.6 | Migracja: dla każdego tenanta utwórz oddział główny z `SettingsManager::contactDetailsFor()`, `primary_slot = 1`. Obserwator: **pierwsza lokalizacja tenanta zawsze staje się główna** |
| 1.7 | Wyświetlanie lokalizacji na stronach: wpis `'locations'` w `ContentGridResolver::CONTENT_TYPES` + gałąź `optionsForType()` + gałąź w `content-grid.blade.php:108` + **karta `x-ios.location-card`** |

**Pełny kontrakt karty (stan po 2026-08-29):** symbol oddziału jako badge, nazwa, adres,
opis (`Str::limit` 120), godziny otwarcia, zdjęcie nagłówkowe, pasek do 4 miniatur galerii
z licznikiem `+N`, telefon (`tel:`), e-mail (`mailto:`), link do Google Maps ze współrzędnych
z fallbackiem na adres. Wąski zakres zapisany tu pierwotnie („adres, zdjęcie, godziny,
telefon") jest dokładnie powodem, dla którego `code`, `email`, `description` i `gallery`
były zbierane od kroku 1.1 i **nigdy nie docierały do klienta**. Przy dokładaniu kolumny
do `LocationResource` dopisz ją także tutaj albo świadomie zapisz, że nie ma jej pokazywać. |

Krok 1.7 nie tworzy nowego bloku — istniejący blok „Siatka treści" czyta rejestr typów treści,
więc lokalizacje po prostu dochodzą do listy obok usług, postów i promocji. Jedyna nieoczywistość:
wszystko poza `services` trafia dziś do `x-cms.card`, który czyta tylko tytuł, excerpt i obraz —
adres i godziny wymagają własnej karty.

Po tej fazie właściciel ma adres, zdjęcie, galerię i lokalizacje na stronie **bez najmniejszego
dotknięcia magazynu**. `multi_location_stock` nadal OFF.

### Faza 2 — Stan magazynowy per oddział (kotwica)

| # | Krok |
|---|---|
| 2.1 | Migracja `service_location_stocks`: `organization_id`, `service_id`, `location_id`, `quantity`, `is_active`; UNIQUE `(service_id, location_id)`, indeks `(location_id, service_id)` |
| 2.2 | `SyncServiceLocationStock` — materializacja brakujących wierszy (qty 0), `insertOrIgnore` **poza** ścieżką blokady |
| 2.3 | Backfill: cały `quantity_total` → wiersz stanu oddziału domyślnego |
| 2.4 | `Service::recalculateQuantityTotal()` — `quantity_total` staje się **mirrorem** `SUM(stocks.quantity)`, wołanym wyłącznie w istniejącej transakcji na zablokowanym wierszu |
| 2.5 | Panel: RelationManager „Stany magazynowe" na `ServiceResource`. **Dla tenanta jednooddziałowego `TextInput` „Ilość w magazynie" (`ServiceResource.php:270`) zostaje** — `afterSave` routuje wartość do wiersza siedziby głównej |

> Krok 2.5 wprost odrzuca rozwiązanie z dwóch wariantów, które zamieniały to pole na `disabled`
> dla wszystkich. Dzisiejszy tenant `budowlana` nie może stracić pola, w które wpisuje liczbę.

### Faza 3 — Egzemplarze

| # | Krok |
|---|---|
| 3.1 | Migracja `service_units`: `organization_id`, `service_id`, **`location_id`**, `serial_number`, `inventory_number`, `status` (`available`/`maintenance`/`in_transit`/`retired`), `acquired_at`, `notes`; UNIQUE `(organization_id, serial_number)` |
| 3.2 | Obserwator utrzymujący kotwicę: `stocks.quantity = COUNT(units WHERE location_id = L AND status = 'available')`, **w tej samej transakcji** |
| 3.3 | Generator: z istniejącej `quantity_total` twórz N egzemplarzy bez numeru w oddziale domyślnym (numer uzupełniany ręcznie) |
| 3.4 | Panel: RelationManager „Egzemplarze" na `ServiceResource` — nr seryjny, oddział, status, historia |
| 3.5 | Serwis pojedynczej sztuki: `status = maintenance` zdejmuje 1 z kotwicy (dziś jedyny wyłącznik to `is_active` na **całej** usłudze — all-or-nothing) |

> **Egzemplarz wypożyczony pozostaje `available`** i przypisany do oddziału wydania. Zajętość
> mieszka wyłącznie w rezerwacjach. Złamanie tej zasady = podwójne odejmowanie sprzętu.

### Faza 4 — Rdzeń dostępności

| # | Krok |
|---|---|
| 4.1 | `getAvailableQuantity(..., ?int $locationId = null)` — parametr **na końcu**. Gałąź `null`: kod bit w bit dzisiejszy, czyta `services.quantity_total`. Gałąź z oddziałem: pojemność z kotwicy + filtr |
| 4.2 | Filtr lokalizacji **w outer WHERE** na `order_items` — nigdy w `whereHas`, nigdy w JOIN na `orders`, nigdy w `Order::scopeExpired()` ani w gałęzi `pending_payment` (`OrderItem.php:93-113` wymaga lustrzaności) |
| 4.3 | `lockStock()` — blokada na wierszu kotwicy, hierarchia: `services` po `service_id` rosnąco (istniejąca kolejność z `CartService.php:167`), potem kotwica. `Service::lockForUpdate()` **zostaje** przez całą fazę |
| 4.4 | Przepięcie 6 ścieżek zapisu: `CartService:98`, `:192`, `:464`; `CreateRental:43`; `EditRental:43` |
| 4.5 | **`RentalExtensionService::checkAvailabilityForExtension()` — zmiana sygnatury o `$locationId`** (9. wywołanie) |
| 4.6 | `getMonthlyAvailability(..., ?int $locationId = null)` — **w jednym kroku z endpointem API**, inaczej kalendarz kłamie |
| 4.7 | `availabilityForServices(Collection $services, ...)` — **zbiorcze**, `GROUP BY service_id, location_id`. Bez tego kafelek na `/wypozyczalnia/{kategoria}` to N+1 na nieograniczonej liście |
| 4.8 | Migracje: `location_id` nullable + indeks `(service_id, location_id, start_date, end_date)` na `rentals`, `order_items`, `cart_items`; backfill otwartych rezerwacji do oddziału domyślnego |

> Kolumny zostają **nullable na stałe**. Wymuszanie `NOT NULL` w środku planu było w jednym
> z wariantów jedynym krokiem nieodwracalnym, niepodzielnym i umieszczonym w środku — odrzucone.

### Faza 5 — Front klienta

| # | Krok |
|---|---|
| 5.1 | `LocationContext` — sesja + middleware `ShareSelectedLocation` walidujący przynależność do **bieżącego** tenanta (stale session po zmianie subdomeny). **Jedno źródło prawdy** `selectionRequired()` czytane przez header, stronę sprzętu, koszyk i checkout |
| 5.2 | Przełącznik w headerze — desktop + drawer mobilny. Ukryty, gdy tenant ma jeden aktywny oddział (auto-wybór) |
| 5.3 | Kafelki: ożyw `$quantityAvailable` (`service-card.blade.php:40`) → dostępność **w wybranym oddziale**. **Oba** listingi (`services/index.blade.php:37-71` i `x-ios.service-card`) |
| 5.4 | Strona sprzętu: `services/show.blade.php:71-72` i `:323-326` przestają pokazywać `quantity_total`, zaczynają pokazywać dostępność wybranego oddziału |
| 5.5 | „Dostępne też w: Gdańsk (2 szt.)" — z `availabilityForServices`. **Bez dystansu** (patrz Poza zakresem) |

> Krok 5.4 jest obowiązkowy w tej samej fazie co 4.1. „Zero zmian w Blade" było reklamowane
> jako zaleta jednego z wariantów — jest odwrotnie: klient w oddziale Gdańsk zobaczyłby
> „5 szt. w magazynie" i kalendarz mówiący „niedostępny". To regresja zaufania, nie oszczędność.

> **Ilość zostaje jedna sztuka na rezerwację — świadomie.** Kalendarz istnieje po to, żeby klient
> za każdym razem wyklikał daty; potrzebując dwóch sztuk, powtarza przejście. Liczba na kafelku
> jest informacją „czy w ogóle jest sens", a rozstrzygnięcie zapada przy składaniu zamówienia.
> **Ta rewalidacja już działa:** `CartService::convertToOrder()` (`CartService.php:184-192`)
> blokuje wiersz i sprawdza dostępność ponownie dla każdej pozycji w transakcji, a `CartItem`y
> nie rezerwują niczego (`CartService.php:140`) — kto pierwszy zapłaci, ten ma sprzęt. Nic tu
> nie trzeba dobudowywać, wystarczy przepuścić przez to `$locationId` (krok 4.4).

### Faza 6 — Koszyk i checkout

| # | Krok |
|---|---|
| 6.1 | `carts.location_id` (**na koszyku, nie na pozycji**) — czyni „jedno zamówienie = jeden punkt odbioru" niemożliwym do złamania |
| 6.2 | `CartService::setLocation()` z rewalidacją dostępności; zmiana oddziału z niepustym koszykiem to **jawna decyzja klienta**, nie błąd przy checkoucie |
| 6.3 | `orders.pickup_location_id` + snapshoty `pickup_location_name`/`_address`; dopisanie do `$auditInclude` i do guardu immutability `Order::updating()` |
| 6.4 | Walidacja `SubmitCheckoutRequest`: `Rule::exists('locations','id')->where('organization_id', $tenantId)`. **Fail-closed** — nie kopiować failsafe'u z `ServiceAreaValidator:25-33` („brak obszarów = wpuszczamy wszystkich") |
| 6.5 | Protokół wydania (PDF) i maile zawierają adres oddziału odbioru |

### Faza 7 — Przesunięcia między oddziałami

**Zwrot nie wymaga ani jednej linii kodu.** Sprzęt wraca zawsze do oddziału wydania, a dostępność
jest **liczona, nie przechowywana** — zwrot zwalnia zasób przez to, że status wypada ze zbioru
blokującego. Egzemplarz przez cały czas wypożyczenia pozostaje przypisany do swojego oddziału, więc
kotwica pojemności też się nie zmienia. Nic nie trzeba dekrementować ani inkrementować.

Zostaje jedno: **świadome przeniesienie sprzętu przez admina** — bo w realnej wypożyczalni maszyna
czasem jedzie z Gdańska do Warszawy na stałe.

| # | Krok |
|---|---|
| 7.1 | `stock_movements` — księga ruchu (`from_location_id`, `to_location_id`, `service_unit_id`, `quantity`, `reason`, `user_id`, `notes`). Przeniesienie to **wpis**, nie destrukcyjny UPDATE |
| 7.2 | Akcja w panelu „Przenieś do innego oddziału" na egzemplarzu i na stanie zbiorczym |
| 7.3 | **Guard pokrycia:** przeniesienie musi udowodnić, że oddział źródłowy po zabraniu sztuki nadal pokrywa **już przyjęte przyszłe rezerwacje**. Inaczej odmawia i pokazuje kolidujące zamówienia |
| 7.4 | Status `in_transit` — sprzęt zdjęty z A i niedopisany jeszcze do B nie jest dostępny w żadnym |

> Krok 7.3 broni przed najcichszym błędem, jaki ten model dopuszcza: `stock[Gdańsk] = 1`,
> zamówienie na dni 10-15 opłacone, admin przenosi jedyną sztukę do Warszawy → opłacona
> rezerwacja traci pokrycie **bez jednego komunikatu**. Kryterium akceptacji: test odtwarzający
> ten scenariusz i dowodzący, że przeniesienie zostało odrzucone.

### Faza 8 — Uprawnienia

| # | Krok |
|---|---|
| 8.1 | Pivot `location_user` (`is_primary`) — **nie `users.branch_id`**, bo `users` nie ma `organization_id` i użytkownik może należeć do wielu organizacji |
| 8.2 | `EmployeeResource`: przypisanie oddziałów przy tworzeniu/edycji pracownika |
| 8.3 | Zawężenie widoku `staff` do swoich oddziałów — **scope warunkowy**, nigdy globalny (globalny obciąłby widok adminowi całej firmy) |
| 8.4 | Pracownik widzi do obsługi (wydanie/zwrot) wyłącznie zamówienia swojego oddziału. **Kto przyjął zwrot, zapisuje się już dziś** — `state_histories.responsible_*`, automatycznie z `auth()->user()` |

> Role Spatie są globalne (`teams => false`). „Kierownik oddziału" nie powstanie przez Spatie —
> to przypisanie w pivocie, nie rola. Włączanie `teams` jest poza zakresem.

### Faza 9 — Statystyki (ostatnia, blok atomowy)

| # | Krok |
|---|---|
| 9.1 | Pełny `statistics:backfill` przed czymkolwiek (musi umieć odtworzyć każdy stan z tabel surowych) |
| 9.2 | **Jedna migracja:** `location_id NOT NULL DEFAULT 0` + DROP starego UNIQUE + CREATE `(organization_id, date, source, location_id)` + zmiana conflict targetu w `RecalculateDailyStatisticsJob:127-131` + przepisanie `upsertForOrg()` z 6 zapytań skalarnych na `GROUP BY` |
| 9.3 | `StatisticsService` live fallback (>2h) musi zwracać dane **z wymiarem** — inaczej po jego włączeniu dane per oddział znikają |
| 9.4 | Widgety: wypożyczenia per oddział, ranking sprzętu per oddział |

> Ten blok jest nierozbrajalny — rozdzielenie go zostawia upsert nadpisujący wiersze różnych
> oddziałów po starym kluczu. Stąd: na końcu, po pełnym backfillu, jako jeden release.

---

## Wycofywalność — wymóg przekrojowy

Pytanie właściciela produktu (2026-08-27): czy da się wycofać zmiany **łącznie z bazą i migracjami**.
Odpowiedź zmierzona, nie deklarowana.

### Co repo ma dziś

| Mechanizm | Co realnie robi |
|---|---|
| `.githooks/pre-commit` | odrzuca migrację z **pustym** `down()` |
| `MigrationRollbackTest` + `migrations:check-rollback` | **statyczny regex** na treści `down()` — sprawdza, że ciało nie jest puste |
| `scripts/backup-database.sh`, `scripts/server/tenant-backup.sh` / `tenant-restore.sh` | kopia i odtworzenie bazy — działa, odtworzone realnie w PR #173 |

**Czego NIE ma:** nic w repo nigdy **nie wykonuje** `down()`. Migracja, której `down()` rzuca wyjątek,
kasuje niewłaściwą kolumnę albo wywala się na realnych danych, przechodzi obie bramki bez słowa.

### Wzorzec, który to naprawia — już w repo

`tests/Feature/Database/OrderPaidPickupHtmlSeparatorMigrationTest.php` **faktycznie uruchamia**
`migrate:rollback` i sprawdza stan po nim. Ten wzorzec istnieje, tylko nie jest obowiązkowy.

### Wymóg dla Faz 1-9

1. **Każda migracja tej implementacji ma wykonywany test rollbacku** wzorowany na powyższym:
   `up` → asercja stanu → `migrate:rollback` → asercja, że stan sprzed wrócił → `up` ponownie.
   **Na MySQL**, nie na SQLite — SQLite nie egzekwuje ENUM-ów, FK ani NOT NULL tak jak InnoDB, więc
   zielony rollback na SQLite nic nie dowodzi (patrz `.claude/rules/tests.md`, bramka 27 porażek).
2. **Backfille wyłącznie addytywne.** Zapisują nowe kolumny, nie nadpisują istniejących. Tam, gdzie
   nadpisanie jest nieuniknione, backfill najpierw robi snapshot nadpisywanych wartości.
3. **Kroki jednokierunkowe nazwane z góry.** W całym planie jest **jeden**: krok **9.2** (zmiana
   UNIQUE na `statistics_daily_snapshots`). Formalnie ma `down()`, ale powrót wymaga wcześniejszego
   usunięcia wierszy z `location_id != 0`, inaczej stary UNIQUE nie założy się na duplikatach.
   Dlatego jest ostatni w planie i poprzedza go pełny `statistics:backfill`, który potrafi odtworzyć
   każdy stan z tabel surowych.
4. **Punkt przywracania przed wdrożeniem każdej fazy** na środowisku z danymi — `backup-database.sh`
   przed migracją, zweryfikowany odczytem, nie samym kodem wyjścia.

### Ta konkretna dostawa (Faza 0, PR #227)

**Zero migracji, zero zmian schematu, zero transformacji danych** — zweryfikowane:
`git diff develop -- database/` jest pusty. Cofa się samym `git revert`, baza nie jest w to
w ogóle zaangażowana.

---

## Poza zakresem (świadomie)

- **Dystans „34 km"** — żaden model nie zna pozycji odwiedzającego. `UserAddress` ma współrzędne,
  ale tylko dla zalogowanych z adresem; anonim na `/wypozyczalnia` nie ma ich wcale. Wymaga
  osobnej decyzji (geolokalizacja przeglądarki / pole kodu pocztowego). Do Fazy 5 wchodzi
  „Dostępne też w: Gdańsk (2 szt.)" **bez kilometrów**.
- **Selektor ilości** — świadomie zostaje jedna sztuka na rezerwację; kalendarz istnieje po to,
  żeby daty klikać za każdym razem. Rewalidacja przy składaniu zamówienia już działa.
- **Zwrot części pozycji zamówienia** — `order_items` nie ma `status`, zwrot jest atomowy.
  Osobny temat.
- **`handed_over_at`** — dziś świadomie nie ma daty wydania (`OrderStatusStateMachine.php:110-113`),
  jest tylko wpis w `state_histories`. Realna luka, ale niezwiązana z oddziałami — osobny temat.
- **Ustawienia per oddział** (szablony maili, ceny, regulaminy) — `SettingsManager` nie zna
  dziedziczenia organizacja→oddział. Godziny otwarcia mieszczą się w json na `locations`.
- **Izolacja uploadów per tenant** — `directory()` jest dziś stałe dla wszystkich zasobów, pliki
  różnych organizacji lądują w jednym katalogu publicznym. Wada zastana; nowe lokalizacje
  dostają `locations/{tenantId}/`, ale naprawa całości to osobne zadanie.
- **Spatie `teams`** — zmienia schemat pakietu.

---

## Weryfikacja

**Po każdym kroku (obowiązkowo):**
```bash
docker compose exec -T app ./vendor/bin/pint --test
docker compose exec -T app php artisan test
docker compose exec -T app npm run build      # po każdej zmianie Blade/CSS/JS
```

**Bramka Fazy 0 (falsyfikowalna):** usuń ręcznie `forUpdate: true` z `CartService.php:192` →
harness dwupołączeniowy **musi paść**. Przywróć → musi przejść. Jeśli nie pada, harness nie
testuje tego, co deklaruje.

**Ręcznie, po każdej fazie — panel admina i front klienta osobno:**

| Faza | Panel | Front |
|---|---|---|
| 1 | dodaj 2 oddziały ze zdjęciem i galerią | siatka lokalizacji na stronie CMS |
| 2 | rozdziel 5 szt. na 3+2; tenant jednooddziałowy nadal ma pole „Ilość" | katalog bez zmian (flaga OFF) |
| 3 | dodaj nr seryjne, wyślij 1 szt. na serwis | dostępność spada o 1 |
| 4 | — | kalendarz i API zgodne z oddziałem |
| 5 | — | przełącznik, badge na obu listingach, dostępność oddziału na stronie sprzętu |
| 6 | zamówienie ma oddział odbioru | zmiana oddziału z pełnym koszykiem to jawna decyzja |
| 7 | przeniesienie sztuki → wpis ruchu; przeniesienie łamiące pokrycie → odrzucone | zwrot: stan oddziału wraca sam, bez akcji |
| 8 | pracownik widzi tylko swój oddział | — |
| 9 | statystyki per oddział | — |

**Scenariusze wyścigów (MySQL, dwa połączenia):** dwóch klientów, ostatnia sztuka, ten sam
oddział → jeden przechodzi · ten sam sprzęt, **różne** oddziały → **oba** przechodzą (dowód, że
oddziały nie serializują się fałszywie) · przedłużenie kontra nowa rezerwacja w tym samym oknie.

**Środowiska:** `feature/*` → `develop` → `staging` → tag `rc*` → dispatch. UAT (`budowlana`,
tenant jednooddziałowy) jest kanarkiem niezmiennika zerowej regresji — po każdej fazie musi
zachowywać się identycznie jak przed nią, dopóki `multi_location_stock` jest OFF.

---

## Po zatwierdzeniu zakresu

### ClickUp — struktura

Jedno zadanie główne na fazę (10 zadań), w każdym subtaski odpowiadające krokom z tabel powyżej.
Numeracja zadań = numeracja z tego dokumentu, żeby dało się przejść z ticketu do planu i z powrotem.

| Zadanie główne | Subtaski | Bramka wyjścia |
|---|---|---|
| `0.x` Higiena i dowód | 0.1–0.3 | harness pada po usunięciu `forUpdate: true` |
| `1.x` Lokalizacja jako encja | 1.1–1.7 | 2 oddziały widoczne na stronie CMS |
| `2.x` Stan per oddział | 2.1–2.5 | tenant jednooddziałowy bez zmian w pracy |
| `3.x` Egzemplarze | 3.1–3.5 | serwis 1 szt. zdejmuje 1 z dostępności |
| `4.x` Rdzeń dostępności | 4.1–4.8 | 9/9 wywołań przepuszcza `$locationId`; brak N+1 |
| `5.x` Front klienta | 5.1–5.5 | oba listingi pokazują dostępność oddziału |
| `6.x` Koszyk i checkout | 6.1–6.5 | zamówienie niesie oddział odbioru |
| `7.x` Przesunięcia | 7.1–7.4 | przeniesienie łamiące pokrycie odrzucone |
| `8.x` Uprawnienia | 8.1–8.4 | `staff` widzi tylko swój oddział |
| `9.x` Statystyki | 9.1–9.4 | raport per oddział zgodny z backfillem |

Każdy subtask nosi: opis, **kryterium akceptacji** (kolumna z tabel wyżej), pliki do dotknięcia
i sposób ręcznej weryfikacji (panel / front). Zakładanie masowe przez `scripts/clickup.py`,
nie przez MCP — limit 50 wywołań na dobę na planie Free.

### Dokumentacja (obowiązkowa, nie „jeśli starczy czasu")

`app/docs/features/lokalizacje/`:

| Plik | Zawartość |
|---|---|
| `README.md` | mapa dokumentów + status faz |
| `model-danych.md` | tabele, relacje, dlaczego kotwica a nie `COUNT` egzemplarzy |
| `kontrakt-dostepnosci.md` | niezmiennik zerowej regresji, 9 wywołań, dyscyplina blokad, filtr w outer WHERE |
| `tryb-jednooddzialowy.md` | co widzi i czego nie widzi tenant z jedną siedzibą |

Dokumentacja **biznesowa** trafia zgodnie z konwencją repo do `docs/business/` (jedyne miejsce,
gdzie żyją ścieżki użytkownika), jako para `.md` + `.en.md`:
`customer-journey-locations` (wybór oddziału, dostępność, „dostępne też w") oraz
`staff-journey-locations` (wydanie, zwrot, przeniesienie sprzętu).

**Uwaga o dwóch drzewach dokumentacji:** repo ma `app/docs/` (żywy indeks, dokumentacja
techniczna i wdrożeniowa — tak mówi `CLAUDE.md`) oraz `docs/` (starszy hub z `business/`,
którego README nosi datę grudnia 2025 i ma 31 zwisających linków). Techniczne idzie do
`app/docs/`, biznesowe do `docs/business/`.

`.claude/rules/` — reguła pinująca dwie zasady, na których ten projekt stoi: wymiar lokalizacji
wchodzi **wyłącznie** przez `getAvailableQuantity()` (nigdy własnym zapytaniem obok), a egzemplarz
wypożyczony **nie zmienia statusu** (zajętość mieszka w rezerwacjach).

### Wykonanie

Agent przed kodem · gałąź `feature/*` · `code-reviewer` **oraz** audyt
`agent-security-audit-specialist` przed każdym commitem dotykającym izolacji tenanta ·
`pint --test && php artisan test` · `npm run build` po każdej zmianie Blade/CSS/JS.
