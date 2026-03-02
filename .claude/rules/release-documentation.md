# Release Documentation Rules

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
**Branch:** `release/vX.Y.Z`
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

- [ ] Testy przeszły
- [ ] Pint przeszedł
- [ ] Staging zweryfikowany
- [ ] Zgoda użytkownika na production deploy
- [ ] Production wdrożony
- [ ] Merge back do develop
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
