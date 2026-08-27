# Lokalizacje (oddziały) — dokumentacja funkcji

Wielooddziałowość: sprzęt stoi w konkretnych oddziałach, klient wybiera oddział jak sklep,
stan magazynowy zdejmuje się z tego oddziału i wraca do niego po zwrocie.

**Status:** 🟡 w toku.
**Faza 0 — zmergowana na `develop`** 2026-08-27 ([PR #227](https://github.com/patrykgielo/registro/pull/227)):
naprawa realnego oversellu w koszyku, usunięcie dwóch martwych kopii matematyki dostępności,
harness współbieżności.
**Faza 1 — w toku** na gałęzi `feature/lokalizacje-encja` (niezmergowana): kroki 1.1/1.2/1.6
(warstwa danych) zrobione 2026-08-27; kroki 1.3–1.5 (Filament, zdjęcia, mapa) i 1.7 (blok CMS)
nierozpoczęte. Fazy 2-9 nierozpoczęte.

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
| 1 | Lokalizacja jako encja (adres, geo, zdjęcie, galeria, CMS) | [`86cbahqc9`](https://app.clickup.com/t/86cbahqc9) | 🟡 1.1/1.2/1.6 zrobione |
| 2 | Stan magazynowy per oddział (kotwica) | [`86cbahqd9`](https://app.clickup.com/t/86cbahqd9) | ⬜ |
| 3 | Egzemplarze (numery seryjne) | [`86cbahqdx`](https://app.clickup.com/t/86cbahqdx) | ⬜ |
| 4 | Rdzeń dostępności | [`86cbahqen`](https://app.clickup.com/t/86cbahqen) | ⬜ |
| 5 | Front klienta (przełącznik, dostępność) | [`86cbahqfy`](https://app.clickup.com/t/86cbahqfy) | ⬜ |
| 6 | Koszyk i checkout | [`86cbahqgr`](https://app.clickup.com/t/86cbahqgr) | ⬜ |
| 7 | Przesunięcia między oddziałami | [`86cbahqhc`](https://app.clickup.com/t/86cbahqhc) | ⬜ |
| 8 | Uprawnienia pracowników | [`86cbahqj5`](https://app.clickup.com/t/86cbahqj5) | ⬜ |
| 9 | Statystyki per oddział | [`86cbahqk0`](https://app.clickup.com/t/86cbahqk0) | ⬜ |

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
