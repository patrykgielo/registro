# Tryb jednooddziałowy

> Klient z jedną, dużą siedzibą. Dziś: **każdy** tenant, łącznie z produkcyjną `budowlana`.

To nie jest wariant do włączenia ani tryb do skonfigurowania. To **stan domyślny**, w którym
system po prostu nie pokazuje niczego zbędnego.

## Co dostaje taki klient

- adres, telefon, e-mail i godziny otwarcia jako **encję**, a nie luźne pole w ustawieniach,
- zdjęcie siedziby i galerię,
- lokalizację do wyświetlenia na stronie przez istniejący blok „Siatka treści",
- pozycję na mapie.

## Czego nie zobaczy

| Element | Zachowanie |
|---|---|
| Przełącznik oddziału w headerze | **nie renderuje się** — `LocationContext::selectionRequired()` zwraca `false` |
| Wybór oddziału w koszyku i checkoucie | pomijany, kontekst ustawia się sam |
| „Dostępne też w oddziale X" | brak innych oddziałów, brak sekcji |
| Zakładka „Stany magazynowe" jako jedyna droga do ilości | **pole „Ilość w magazynie" zostaje na miejscu** |

## Trzy mechanizmy, które to gwarantują

### 1. Pierwsza lokalizacja automatycznie staje się główną

Obserwator na `Location` ustawia `primary_slot = 1`, gdy tenant nie ma jeszcze żadnej lokalizacji.
Gwarantuje to UNIQUE `(organization_id, primary_slot)` w DB — nie konwencja, nie walidacja
w formularzu. Zmiana głównej później jest ręczna, jednym kliknięciem.

### 2. Migracja (tenanci sprzed Fazy 1) i provisioning (tenanci od ClickUp 123k99cvc53) tworzą oddział

Backfill (`2026_08_27_120001_backfill_primary_location_for_organizations.php`, jednorazowa)
czyta `SettingsManager::contactDetailsFor()` i zakłada oddział główny dla tenantów, którzy
istnieli PRZED Fazą 1. Dla każdego tenanta zakładanego przez `registro:tenant-provision` tę samą
rolę pełni `SeedOrganizationDefaults::ensurePrimaryLocation()` (nazwa „Siedziba główna", adres
pusty — nowy tenant nie ma jeszcze `contact.*` w ustawieniach) — bez tego kroku (naprawiony
2026-09-19) nowo zakładany tenant miał ZERO lokalizacji i pole „Ilość w magazynie" ciche milkło
(patrz punkt 3). **Wołana bezwarunkowo, nie tylko przy tworzeniu nowej organizacji** (poprawka
tego samego dnia w code review): pierwsza wersja wołała ją tylko gdy org była nowo tworzona, więc
ponowne uruchomienie komendy na ISTNIEJĄCYM, bezoddziałowym tenancie nigdy go nie leczyło —
dokładnie tej ścieżki naprawy szukałby operator. Oba mechanizmy razem gwarantują: **żaden tenant
nie ma dziś zera lokalizacji**, niezależnie od tego, kiedy powstał ani ile razy uruchomiono
provisioning.

### 3. Pole „Ilość w magazynie" zostaje

`ServiceResource.php:270` — `TextInput` z `required|numeric|minValue(1)`. Właściciel wpisuje
liczbę tak jak dziś; `afterSave` routuje wartość do wiersza stanu siedziby głównej.

Dwa z trzech rozważanych wariantów projektowych zamieniały to pole na `disabled` z podpowiedzią
„edytuj w zakładce Stany magazynowe" — **dla wszystkich tenantów**. To odrzucono świadomie:
tenant, który ma i będzie miał jeden punkt, nie może stracić pola, w które dziś po prostu wpisuje `5`.

Trzy nowe warunki wyłączenia pola (ClickUp 123k99cvc53/cvcc3/cvc54, naprawione 2026-09-19), każdy
z osobnym tekstem podpowiedzi zamiast jednego ogólnego: **zero aktywnych oddziałów** („dodaj
oddział w sekcji Lokalizacje"), **stan rozjechany na inny oddział** (istniejące już wcześniej
zabezpieczenie), **ten produkt ma zarejestrowane egzemplarze** — od chwili pierwszego
egzemplarza jedynym pisarzem stanu jest `ServiceUnitObserver` (`COUNT()` dostępnych sztuk), więc
pole musiałoby z nim rywalizować. Ten sam per-wiersz warunek chroni inline-edycję w zakładce
„Stany magazynowe": wiersz z egzemplarzami jest disabled, wiersz bez nich (inny oddział tego
samego produktu) zostaje edytowalny.

**Niezmiennik od 123k99cvc54:** pierwszy egzemplarz utworzony dla pary (usługa, oddział), która
miała już ręcznie wpisaną ilość, MATERIALIZUJE różnicę jako egzemplarze bez numeru (numer jest
opcjonalny — decyzja Fazy 3) — `ServiceUnitObserver::materializePlaceholdersForFirstUnit()`.
Admin, który miał „5" i dodaje pierwszy egzemplarz, kończy z 1 nazwanym + 4 bezimiennymi, nie z „1".

## Flaga `multi_location_stock`

| | |
|---|---|
| Domyślnie | **OFF** |
| Włącza | rozbicie stanu magazynowego i wybór oddziału przez klienta |
| Kto włącza | **operator, świadomą decyzją** |

**Encja lokalizacji flagi nie potrzebuje** — każda wypożyczalnia ma fizyczny adres, a tenant bez
dodanych lokalizacji po prostu nie ma czego wybrać w bloku CMS.

Widoczność funkcji **nigdy nie zależy od danych**. Warunek typu `Location::active()->count() > 1`
został odrzucony: admin dodający drugi adres tylko po to, żeby pokazać go na stronie, nie może
przypadkiem uruchomić rozbicia magazynu, wyboru oddziału w checkoucie i zmiany semantyki
dostępności.

## Rola kanarka

UAT (`budowlana`, jeden oddział) jest **kanarkiem niezmiennika zerowej regresji**. Po każdej
fazie musi zachowywać się identycznie jak przed nią, dopóki `multi_location_stock` jest OFF.

Weryfikacja: dostępność, kalendarz i kafelki zwracają te same liczby co przed wdrożeniem fazy —
pinowane testami charakteryzującymi z Fazy 0, które sprawdzają **konkretne wartości**, nie kształt.
