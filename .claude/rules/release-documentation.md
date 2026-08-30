---
paths:
  - "docs/releases/**"
---

# Release Documentation Rules

## Zakres (zaktualizowane 2026-08-30)

**Historia dwóch nieudanych podejść — przeczytaj, zanim dołożysz trzecie wymaganie.**
Notatki `v4.13.0`–`v4.15.0` powstawały, po czym zresetowano wersjonowanie do `v0.x` i ustały.
Potem ta reguła zadeklarowała 2026-08-16 konwencję „od dzisiaj obowiązuje" — i nie powstał
**ani jeden** dokument przy 25 tagach, bo `paths` celowało w katalog, którego nie było, więc
reguła nie ładowała się nigdy. Obie konwencje umarły, bo **nie miały czytelnika**.

`docs/releases/` **istnieje od 2026-08-30**, z pierwszym wpisem (Lokalizacje 0-2) i własnym
`README.md` opisującym konwencję. Ten plik jest regułą dla agenta; `docs/releases/README.md`
jest instrukcją dla człowieka — trzymaj je zgodne.

**Obowiązuje, ale nie wstecz** — nie twórz dokumentów dla historycznych tagów, tylko dla każdego NOWEGO tagu od
2026-08-16, i to zróżnicowane wg tego, skąd tag jest cięty:

- **Tag `rc*`, cięty z `staging`** (`vX.Y.Z-rc.N`) — **adnotowany tag gita wystarczy.**
  `git tag -a vX.Y.Z-rc.N -m "..."` z treścią wiadomości opisującą zmiany jako listę (nie jedno
  zdanie) — bez osobnego pliku w `docs/releases/`. Uzasadnienie: rc-tagi tną się często (osiem w
  jeden dzień 2026-08-16) — pełny dokument na każdy byłby szumem, nie dyscypliną, i szybko
  zniechęciłby do pisania czegokolwiek.
- **Tag produkcyjny (`vX.Y.Z`, bez `-rc`), cięty z `main` po promocji `staging → main`** — WYMAGA
  `docs/releases/vX.Y.Z.md` wg szablonu poniżej, PRZED wypchnięciem tagu.
- **Domknięty zakres funkcjonalny na `develop`, zmieniający to, co widzi klient lub operator**
  — WYMAGA `docs/releases/RRRR-MM-DD-zakres.md`. **Odstępstwo od pierwotnej reguły, świadome:**
  między `develop` a produkcją stoi maszyna, której nie kupiono, więc czekanie na tag
  produkcyjny oznaczałoby czekanie w nieskończoność, a zakres domyka się już teraz.

**BEZWZGLĘDNIE: notatka mówi, GDZIE ten kod jest.** Nie „wydane", tylko tabela środowisk:
`develop` / `staging` / UAT / produkcja. Wpis sugerujący, że coś działa u klienta, kiedy leży
tylko na gałęzi integracyjnej, jest **gorszy niż brak wpisu**. Status aktualizuje się w tym
samym pliku, gdy kod idzie dalej — nie zakłada się nowego.

**Pułapka trigera (częściowo rozbrojona 2026-08-30):** ta reguła ma `paths: docs/releases/**`.
Do 2026-08-30 katalog nie istniał, więc `paths` nie mogło trafić **nigdy** — reguła była
martwym wpisem TIER 2 przez 13 dni od wprowadzenia. Katalog już jest, więc reguła ładuje się
przy edycji notatki. Nadal jednak samo cięcie taga zwykle nie dotyka żadnego pliku tam. Punkt wejścia
dla operatora/agenta jest w `.github/workflows/RELEASE_PROCESS.md` (krok "Tag a Release"), nie tutaj
— stamtąd trafiasz do tego pliku PRZED wypchnięciem tagu produkcyjnego, nie po.

## Struktura dokumentu release

Każdy dokument release (`docs/releases/vX.Y.Z.md`) MUSI zawierać trzy oddzielne sekcje zmian:

### 1. Features (Nowe funkcjonalności)
**Całkowicie nowe elementy** - coś czego wcześniej nie było w aplikacji.

Przykłady:
- Nowa zakładka w panelu admina
- Nowy endpoint API
- Nowa strona/widok
- Nowy komponent UI
- Nowa integracja z zewnętrznym serwisem

### 2. Fixes (Naprawy)
**Naprawy błędów** - coś działało źle i zostało naprawione.

Przykłady:
- Naprawa hover state który nie działał
- Naprawa błędu walidacji
- Naprawa broken layoutu
- Naprawa błędnej logiki biznesowej
- Naprawa security issue

### 3. Improvements (Ulepszenia)
**Rozbudowa istniejących funkcji** - coś już istniało ale zostało ulepszone.

Przykłady:
- Zwiększenie rozmiaru czcionki
- Lepsze kolory/kontrast
- Refaktoring kodu (bez zmiany funkcjonalności)
- Optymalizacja wydajności
- Lepszy UX istniejącej funkcji

---

## Szablon dokumentu release

```markdown
# Release vX.Y.Z

**Data:** YYYY-MM-DD
**Branch:** `main` (promowany z `staging` przez PR)
**Poprzedzające rc\*:** vX.Y.Z-rc.1, vX.Y.Z-rc.2, ... (tagi na `staging`, jeśli były)
**Status:** W przygotowaniu | Wydany

---

## Podsumowanie

Krótki opis (1-2 zdania) głównych zmian w tym release.

---

## Features (Nowe funkcjonalności)

### [Nazwa feature]
- Opis co zostało dodane
- Jakie pliki zostały utworzone

---

## Fixes (Naprawy)

### [Nazwa fix]
- Co było zepsute
- Jak zostało naprawione

---

## Improvements (Ulepszenia)

### [Nazwa improvement]
- Co zostało ulepszone
- Jaka była poprzednia wersja vs nowa

---

## Pliki zmienione

```
path/to/file.php (NEW|MODIFIED|DELETED)
```

---

## Migracja

Kroki do wykonania po deploy (jeśli wymagane).

---

## Weryfikacja

- [ ] Testy przeszły (`deploy-production.yml`'s `test` job, na tagu rc* poprzedzającym ten release)
- [ ] Pint przeszedł
- [ ] rc* zweryfikowany na UAT (`staging` → UAT, patrz `RELEASE_PROCESS.md`)
- [ ] Zgoda użytkownika na production deploy — patrz ZASADA 0, `.claude/rules/self-improvement.md`
- [ ] Production wdrożony (kiedy PreProd istnieje; dziś: brak maszyny, patrz `deployment.md`)
```

---

## Jak klasyfikować zmiany

| Pytanie | Odpowiedź | Sekcja |
|---------|-----------|--------|
| Czy to coś zupełnie nowego? | Tak | Features |
| Czy naprawiasz błąd? | Tak | Fixes |
| Czy ulepszasz coś istniejącego? | Tak | Improvements |

### Przykłady graniczne:

**"Dodałem hover effect do przycisku"**
- Jeśli przycisk NIE MIAŁ hover → **Feature**
- Jeśli hover był zepsuty → **Fix**
- Jeśli hover był ale zmieniłem kolor → **Improvement**

**"Zmieniłem rozmiar czcionki"**
- Czcionka była za mała (bug UX) → **Fix**
- Czcionka była OK ale chcę większą → **Improvement**

**"Przeniosłem folder z A do B"**
- Refaktoring struktury → **Improvement** (lub pomiń jeśli nieistotne)

---

## Wersjonowanie semantyczne

```
vMAJOR.MINOR.PATCH

MAJOR - Breaking changes, duże zmiany architektury
MINOR - Nowe Features (backwards compatible)
PATCH - Fixes i małe Improvements
```

**Przykład:**
- v4.17.0 → Nowa zakładka "Wygląd" (Feature) = MINOR
- v4.17.1 → Naprawa hover w footer (Fix) = PATCH
- v5.0.0 → Zmiana struktury API (Breaking) = MAJOR
