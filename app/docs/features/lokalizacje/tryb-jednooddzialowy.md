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

### 2. Migracja tworzy oddział z danych, które tenant już ma

Backfill czyta `SettingsManager::contactDetailsFor()` (`contact.address_line`, `postal_code`,
`city`, `phone`, `email`) i zakłada oddział główny. Dzień po wdrożeniu tenant ma poprawny adres
**bez wpisywania czegokolwiek**.

### 3. Pole „Ilość w magazynie" zostaje

`ServiceResource.php:270` — `TextInput` z `required|numeric|minValue(1)`. Właściciel wpisuje
liczbę tak jak dziś; `afterSave` routuje wartość do wiersza stanu siedziby głównej.

Dwa z trzech rozważanych wariantów projektowych zamieniały to pole na `disabled` z podpowiedzią
„edytuj w zakładce Stany magazynowe" — **dla wszystkich tenantów**. To odrzucono świadomie:
tenant, który ma i będzie miał jeden punkt, nie może stracić pola, w które dziś po prostu wpisuje `5`.

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
