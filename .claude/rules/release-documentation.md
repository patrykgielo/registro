---
paths:
  - "docs/releases/**"
---

# Release Documentation Rules

## Zakres (od 2026-08-16, model trzywarstwowy: `feature/* → develop → staging → main`)

Do tego dnia `docs/releases/` nie istniało w ogóle — zero dokumentów przy 19 dotychczasowych tagach,
mimo że ta reguła istniała. Nieprzestrzegana, nie do naśladowania. **Od dzisiaj obowiązuje, ale nie
wstecz** — nie twórz dokumentów dla historycznych tagów, tylko dla każdego NOWEGO tagu od
2026-08-16, i to zróżnicowane wg tego, skąd tag jest cięty:

- **Tag `rc*`, cięty z `staging`** (`vX.Y.Z-rc.N`) — **adnotowany tag gita wystarczy.**
  `git tag -a vX.Y.Z-rc.N -m "..."` z treścią wiadomości opisującą zmiany jako listę (nie jedno
  zdanie) — bez osobnego pliku w `docs/releases/`. Uzasadnienie: rc-tagi tną się często (osiem w
  jeden dzień 2026-08-16) — pełny dokument na każdy byłby szumem, nie dyscypliną, i szybko
  zniechęciłby do pisania czegokolwiek.
- **Tag produkcyjny (`vX.Y.Z`, bez `-rc`), cięty z `main` po promocji `staging → main`** — WYMAGA
  `docs/releases/vX.Y.Z.md` wg szablonu poniżej, PRZED wypchnięciem tagu.

**Pułapka trigera:** ta reguła ma `paths: docs/releases/**`, więc ładuje się dopiero gdy Claude już
dotyka pliku w tym katalogu — samo cięcie taga zwykle nie dotyka żadnego pliku tam. Punkt wejścia
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
