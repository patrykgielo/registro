# Podróż klienta — Wybór oddziału

> **Status: CZĘŚCIOWO WDROŻONE** (stan 2026-08-29).
> **Oddział już istnieje** — jako encja z adresem, godzinami, zdjęciem i galerią, zarządzalny
> w panelu i **widoczny na stronie** (fazy 0-2, PR #227-#231).
> **Jeszcze nie istnieje** wybór oddziału przez klienta ani dostępność per punkt — to fazy 5-6,
> **wstrzymane** decyzją właściciela produktu.
> Sekcja „Co klient widzi dziś" opisuje stan faktyczny; reszta dokumentu opisuje stan docelowy.
> Szczegóły techniczne: [`app/docs/features/lokalizacje/`](../../app/docs/features/lokalizacje/README.md).

**Dla klientów:** jeśli Twoja firma ma kilka oddziałów, klient wybiera oddział raz — jak sklep
w Castoramie — a katalog, dostępność i odbiór dotyczą już tylko tego punktu. Jeśli masz jedną
siedzibę, klient nie zobaczy żadnego wyboru i wszystko wygląda tak jak dziś.

Dotyczy tenantów z włączoną flagą `multi_location_stock`. Rozszerza
[podróż wypożyczenia](customer-journey-rental.md) — nie zastępuje jej.

## Zasada nadrzędna

**Jedno zamówienie = jeden oddział odbioru.** Klient potrzebujący sprzętu z dwóch punktów składa
dwa zamówienia. Ta reguła **będzie** wymuszona schematem (`carts.location_id`, Faza 6), a nie
dyscypliną kodu — nie da się jej wtedy obejść przypadkiem. Kolumna jeszcze nie istnieje.

## Pełna ścieżka

```mermaid
flowchart TD
    START(["Klient wchodzi na stronę"])
    START --> CTX{Firma ma > 1 aktywny oddział?}

    CTX -- Nie --> AUTO["Oddział główny ustawiony automatycznie\nPrzełącznik się NIE renderuje"]
    AUTO --> CAT

    CTX -- Tak --> DEFAULT["Oddział główny ustawiony wstępnie\nPrzełącznik widoczny w headerze"]
    DEFAULT --> CAT["/wypozyczalnia\nKatalog"]

    CAT --> CARD["Kafelek sprzętu\nDostępne w Twoim oddziale: N szt."]
    CARD --> AVAIL{Dostępne tutaj?}

    AVAIL -- Nie --> ELSEWHERE["Dostępne też w: Gdańsk (2 szt.)\nlink przełącza oddział"]
    ELSEWHERE --> SWITCH

    AVAIL -- Tak --> PRODUCT["/uslugi/{service:slug}\nKalendarz dostępności TEGO oddziału"]
    PRODUCT --> DATES["Wybór zakresu dat"]
    DATES --> CART["Dodanie do koszyka"]

    SWITCH["Zmiana oddziału"] --> CARTFULL{Koszyk niepusty?}
    CARTFULL -- Nie --> CAT
    CARTFULL -- Tak --> CONFIRM["Pytanie: pozycje dotyczą innego oddziału.\nPrzeliczyć koszyk?"]
    CONFIRM --> CAT

    CART --> CHECKOUT["Checkout\nAdres odbioru = wybrany oddział"]
    CHECKOUT --> REVALIDATE{Sprzęt nadal wolny\nw tym oddziale?}
    REVALIDATE -- Nie --> TAKEN["Ktoś był szybszy —\nkomunikat i powrót do koszyka"]
    REVALIDATE -- Tak --> ORDER["Zamówienie złożone\nProtokół wydania z adresem oddziału"]
```

## Co klient widzi DZIŚ

Oddziały pojawiają się na stronie jako **karty w bloku „Siatka treści"** na dowolnej stronie CMS.
Karta pokazuje:

| Element | Skąd |
|---|---|
| Nazwa oddziału | pole „Nazwa" |
| Symbol jako plakietka przy nazwie | pole „Symbol" (np. `MMZ`) |
| Adres | ulica, budynek, kod, miasto |
| Krótki opis | pole „Opis", skracany do 120 znaków |
| Godziny otwarcia | tabela „Godziny otwarcia" |
| Zdjęcie siedziby | pole „Zdjęcie siedziby" |
| Pasek do 4 miniatur galerii, z licznikiem „+N" | pole „Galeria" |
| Telefon (klikalny) i e-mail (klikalny) | pola „Telefon", „E-mail" |
| „Zobacz na mapie" | współrzędne z pickera, z zapasowym wyszukaniem po adresie |

**Czego nie ma:** oddział nie ma własnej podstrony ani adresu URL — istnieje wyłącznie jako
karta w siatce. Klient nie wybiera oddziału, katalog i dostępność nie są jeszcze podzielone
na punkty.

### Dwie pułapki, o których trzeba wiedzieć

**1. Dodanie oddziału NIE umieszcza go na stronie.** Blok „Siatka treści" trzyma ręcznie
wybraną listę elementów i nie ma opcji „wszystkie". Po dodaniu nowego oddziału trzeba wejść
w stronę CMS i dopisać go do bloku. Nic o tym nie przypomina — strona po prostu wygląda tak
jak wcześniej. ([`123k99ct3xt`](https://app.clickup.com/t/123k99ct3xt))

**2. Wyłączenie oddziału NIE zdejmuje go ze strony.** Odznaczenie „Aktywna" usuwa oddział
z listy do wyboru w panelu, ale jeśli był już dodany do bloku — nadal się renderuje.
Żeby zniknął, trzeba usunąć go z bloku.

Obie sytuacje wyglądają dla właściciela tak samo: „ustawiłem, a strona pokazuje co innego".
Przy każdej takiej skardze sprawdź najpierw blok „Siatka treści", a dopiero potem oddział.

---

## Co klient zobaczy docelowo (fazy 5-6, wstrzymane)

| Etap | Co się zmienia względem dziś |
|---|---|
| Wejście na stronę | Oddział główny ustawiony wstępnie; przełącznik w headerze (tylko gdy oddziałów > 1) |
| Katalog | Kafelek pokazuje dostępność **w wybranym oddziale**, nie sumę ze wszystkich |
| Strona sprzętu | Kalendarz i licznik dotyczą wybranego oddziału. Dodatkowo: „Dostępne też w: Gdańsk (2 szt.)" |
| Koszyk | Niesie jeden oddział odbioru; zmiana oddziału z niepustym koszykiem to **jawne pytanie**, nie błąd |
| Checkout | Adres odbioru = adres oddziału; walidacja odrzuca oddział spoza firmy |
| Po zakupie | Protokół wydania i e-maile zawierają adres oddziału |

## Ilość: jedna sztuka na rezerwację

To **świadoma decyzja, nie ograniczenie techniczne**. Kalendarz istnieje po to, żeby klient za
każdym razem wybrał zakres dat; potrzebując dwóch sztuk, powtarza przejście.

Liczba „dostępne 2 szt." jest informacją **czy w ogóle jest sens**, a nie obietnicą. Rozstrzygnięcie
zapada dopiero przy składaniu zamówienia — dodanie do koszyka niczego nie rezerwuje, więc w czasie,
gdy klient się zastanawia, ktoś może go wyprzedzić. **Kto pierwszy zapłaci, ten ma sprzęt.**

## Zwrot

Sprzęt wraca **zawsze do oddziału, z którego został wydany**. Klient nie ma wyboru punktu zwrotu —
adres zwrotu jest ten sam co adres odbioru i widnieje na protokole.

## Czego świadomie nie ma

| Element | Dlaczego |
|---|---|
| Odległość w kilometrach („Gdańsk — 34 km") | System nie zna położenia anonimowego odwiedzającego. Wymaga osobnej decyzji (geolokalizacja przeglądarki albo pole kodu pocztowego) |
| Zwrot w innym oddziale | Decyzja właściciela produktu — utrzymuje prostotę rozliczeń i protokołów |
| Zamówienie z dwóch oddziałów naraz | Jedno zamówienie = jeden protokół wydania, jedna kaucja, jeden odbiór |
| Selektor ilości | Patrz wyżej — kalendarz jest mechanizmem powtarzalnego wyboru |
