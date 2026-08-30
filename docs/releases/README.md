# Notatki wydaniowe

Katalog powstał 2026-08-30. To **trzecie** podejście do tej konwencji i warto wiedzieć,
dlaczego dwa poprzednie umarły — inaczej to podejście umrze tak samo.

| Podejście | Co się stało |
|---|---|
| `v4.13.0` – `v4.15.0` | Notatki powstawały, potem zresetowano wersjonowanie do `v0.x` i przestały. Leżą w [`../archive/releases/`](../archive/releases/) |
| Reguła z 2026-08-16 | Deklarowała konwencję „od dzisiaj obowiązuje", ale celowała `paths` w `docs/releases/**`, którego **nie było** — więc nigdy się nie ładowała. Zero dokumentów przy 25 tagach |

Obie umarły nie z zapominalstwa, tylko dlatego, że **nie miały czytelnika**. Ten katalog ma
sens dopiero wtedy, gdy ktoś poza autorem zmiany może zostać nią zaskoczony.

## Kiedy piszemy, a kiedy nie

| Zdarzenie | Notatka |
|---|---|
| Tag `rc*` cięty ze `staging` | **Nie.** Wystarczy adnotowany tag gita. Osiem tagów w jeden dzień to już się zdarzyło — dokument na każdy byłby szumem |
| Tag produkcyjny `vX.Y.Z` z `main` | **Tak**, plik `vX.Y.Z.md`, przed wypchnięciem tagu |
| Domknięty zakres funkcjonalny na `develop` | **Tak, jeśli zmienia to, co widzi klient albo operator.** Plik `RRRR-MM-DD-zakres.md` |

Trzeci wiersz to odstępstwo od reguły z 2026-08-16 i jest świadome: między `develop`
a produkcją stoi dziś maszyna, której **nie kupiono**, więc czekanie na tag produkcyjny
oznaczałoby czekanie w nieskończoność.

## Zasada, na której to stoi

**Notatka mówi, GDZIE ten kod jest.** Nie „wydane", tylko: na `develop`, na `staging`, na UAT,
na produkcji. Wpis, który sugeruje, że coś działa u klienta, kiedy leży tylko na gałęzi
integracyjnej, jest gorszy niż brak wpisu.

Status aktualizuje się **w tym samym pliku**, gdy kod idzie dalej — nie zakłada się nowego.

## Czego tu nie piszemy

Historii gałęzi, listy commitów i struktury katalogów — to zapisuje git. Do notatki wchodzi
wyłącznie to, czego z gita nie widać: **co to zmienia dla właściciela wypożyczalni albo
dla operatora**, czego jeszcze nie robi, i co może zaskoczyć.

## Wpisy

| Data | Zakres | Status |
|---|---|---|
| 2026-08-30 | [Lokalizacje — fazy 0-2](2026-08-30-lokalizacje-0-2.md) | na `develop`, niewydane |
