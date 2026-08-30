# Lokalizacje (oddziały) — dokumentacja funkcji

Wielooddziałowość: sprzęt stoi w konkretnych oddziałach, klient wybiera oddział jak sklep,
stan magazynowy zdejmuje się z tego oddziału i wraca do niego po zwrocie.

**Status:** 🟡 w toku.
**Faza 0 — zmergowana na `develop`** 2026-08-27 ([PR #227](https://github.com/patrykgielo/registro/pull/227)):
naprawa realnego oversellu w koszyku, usunięcie dwóch martwych kopii matematyki dostępności,
harness współbieżności.
**Faza 1 — zmergowana na `develop`** 2026-08-27 ([PR #228](https://github.com/patrykgielo/registro/pull/228)):
oddział jako encja — tabela, model, zasób w panelu, picker mapy, typ treści dla stron CMS.
Zweryfikowana w przeglądarce, nie tylko testami.
**Faza 2 — zmergowana na `develop`** 2026-08-28 ([PR #231](https://github.com/patrykgielo/registro/pull/231)): kotwica
`service_location_stocks`, backfill z `quantity_total`, `quantity_total` jako mirror, panel bez
regresji dla tenanta jednooddziałowego. Dostępność **nietknięta** — wchodzi w Fazie 4.
**Fazy 3-9 — WSTRZYMANE** decyzją właściciela produktu (2026-08-28): żadna nie ruszy, dopóki
nie zostaną zweryfikowane testy pół-automatyczne panelu tenanta i frontu. To nie jest
„nierozpoczęte" — to świadoma bramka, której nie wolno samodzielnie otworzyć.

## Mapa dokumentów

| Dokument | Odpowiada na pytanie |
|---|---|
| [plan-wdrozenia.md](plan-wdrozenia.md) | Co robimy, w jakiej kolejności, jak weryfikujemy |
| [model-danych.md](model-danych.md) | Jakie tabele, jakie relacje i **dlaczego akurat takie** |
| [kontrakt-dostepnosci.md](kontrakt-dostepnosci.md) | Jak liczy się dostępność i czego **nie wolno** przy niej ruszać |
| [tryb-jednooddzialowy.md](tryb-jednooddzialowy.md) | Co widzi klient z jedną siedzibą (czyli dziś: każdy) |

Dokumentacja biznesowa (ścieżki użytkownika) mieszka zgodnie z konwencją repo w `docs/business/`:
`customer-journey-locations.md` i `staff-journey-locations.md` (+ wersje `.en.md`).

## Status faz

| Faza | Zakres | ClickUp | Status |
|---|---|---|---|
| 0 | Higiena, dowód współbieżności + naprawa żywego oversellu | [`86cbahqbv`](https://app.clickup.com/t/86cbahqbv) | ✅ **ukończona** 2026-08-27 |
| 1 | Lokalizacja jako encja (adres, geo, zdjęcie, galeria, CMS) | [`86cbahqc9`](https://app.clickup.com/t/86cbahqc9) | ✅ **ukończona** (PR #228/#229/#230) |
| 2 | Stan magazynowy per oddział (kotwica) | [`86cbahqd9`](https://app.clickup.com/t/86cbahqd9) | ✅ **ukończona** (PR #231) |
| 3 | Egzemplarze (numery seryjne) | [`86cbahqdx`](https://app.clickup.com/t/86cbahqdx) | ⛔ wstrzymana |
| 4 | Rdzeń dostępności | [`86cbahqen`](https://app.clickup.com/t/86cbahqen) | ⛔ wstrzymana |
| 5 | Front klienta (przełącznik, dostępność) | [`86cbahqfy`](https://app.clickup.com/t/86cbahqfy) | ⛔ wstrzymana |
| 6 | Koszyk i checkout | [`86cbahqgr`](https://app.clickup.com/t/86cbahqgr) | ⛔ wstrzymana |
| 7 | Przesunięcia między oddziałami | [`86cbahqhc`](https://app.clickup.com/t/86cbahqhc) | ⛔ wstrzymana |
| 8 | Uprawnienia pracowników | [`86cbahqj5`](https://app.clickup.com/t/86cbahqj5) | ⛔ wstrzymana |
| 9 | Statystyki per oddział | [`86cbahqk0`](https://app.clickup.com/t/86cbahqk0) | ⛔ wstrzymana |

Każde zadanie główne ma subtaski odpowiadające krokom z
[planu wdrożenia](plan-wdrozenia.md), z kryterium akceptacji i sposobem weryfikacji.

## Znaleziony przy okazji: żywy oversell

Podczas przeglądu ClickUp okazało się, że zgłoszenie
[`86cb93tfw`](https://app.clickup.com/t/86cb93tfw) („Zamówienie tego samego produktu mimo
dostępnej 1 sztuki") opisuje **błąd, który dzieje się dziś** — i nie jest to problem
współbieżności, tylko brak sumowania popytu w pętli walidacji `convertToOrder()`.

Przyczyna i naprawa: [`kontrakt-dostepnosci.md`](kontrakt-dostepnosci.md) → Zasada 7,
zadanie **0.4**.

## Skąd się wziął ten zakres

Audyt konkurencji (5 wypożyczalni sprzętu budowlanego, sierpień 2026) wskazał brak pojęcia
oddziału jako **jedyną** z 22 luk, która zmienia model danych. Pozostałe to warstwa treści
i marketingu.

Plan powstał z pomiaru kodu (7 równoległych sond), trzech niezależnych wariantów projektowych
i trzech sędziów oceniających je w soczewkach poprawności, produktu i wdrażalności — nie
z założeń. Każdy fakt w dokumentach ma dowód `plik:linia`.

## Znane ograniczenia (stan 2026-08-29)

| Ograniczenie | Skutek | Zgłoszenie |
|---|---|---|
| Blok „Siatka treści" nie ma trybu „wszystkie" | Dodany oddział **nie pojawia się** na stronie, dopóki ktoś ręcznie nie dopisze go do bloku. Nic o tym nie informuje | [`123k99ct3xt`](https://app.clickup.com/t/123k99ct3xt) |
| `is_active` nie filtruje renderu | Wyłączenie oddziału **nie zdejmuje go ze strony** — `ContentGridResolver::resolveItems()` robi `whereIn('id', $ids)` bez filtra; `is_active` zawęża tylko listę wyboru w panelu | — |
| Brak trasy pojedynczego oddziału | Oddział istnieje wyłącznie jako karta w siatce; `slug` jest w schemacie, ale nic go nie konsumuje | — |

Obie pierwsze pozycje wyglądają dla właściciela identycznie: „wypełniłem wszystko,
a na stronie tego nie ma". Przy diagnozie sprawdź blok CMS **zanim** zaczniesz szukać
w kodzie lokalizacji.
