# Release Notes

Ten katalog zawiera dokumentację wszystkich wydań aplikacji Paradocks.

## Struktura

```
releases/
├── README.md           # Ten plik
├── v4.13.0.md         # Najnowszy release (przygotowywany)
├── v4.12.1.md         # Poprzedni release
└── ...
```

## Konwencja wersjonowania

Używamy [Semantic Versioning](https://semver.org/):

```
vMAJOR.MINOR.PATCH

MAJOR - Breaking changes, duże zmiany architektury
MINOR - Nowe funkcjonalności (backwards compatible)
PATCH - Bugfixy, małe poprawki
```

## Proces Release

### 1. Przygotowanie (przed merge)

```bash
# Sprawdź co wchodzi w release
git log origin/main..origin/staging --oneline

# Utwórz dokumentację release
# app/docs/releases/vX.Y.Z.md
```

### 2. Tworzenie Release Branch

```bash
git checkout staging
git pull origin staging
git checkout -b release/vX.Y.Z
```

### 3. Finalizacja

- [ ] Zaktualizuj CHANGELOG.md (jeśli istnieje)
- [ ] Sprawdź dokumentację release notes
- [ ] Utwórz PR do main
- [ ] Po merge: utwórz tag

```bash
git checkout main
git pull origin main
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

### 4. Post-release

```bash
# Merge release branch back do develop
git checkout develop
git merge --no-ff release/vX.Y.Z
git push origin develop
```

## Szablon Release Notes

```markdown
# Release vX.Y.Z

**Data:** YYYY-MM-DD
**Branch:** release/vX.Y.Z
**Status:** Przygotowywany | Wydany

## Podsumowanie

Krótki opis głównych zmian.

## Nowe funkcjonalności

- feat: opis (#PR)

## Poprawki

- fix: opis (#PR)

## Zmiany techniczne

- refactor/chore: opis (#PR)

## Breaking Changes

Brak / Lista zmian wymagających migracji

## Migracja

Kroki do wykonania po deploy (jeśli wymagane).

## Testowanie

Checklist do weryfikacji po deploy.
```

## Historia Wydań

| Wersja | Data | Status | Główne zmiany |
|--------|------|--------|---------------|
| v4.17.0 | 2026-01-22 | Przygotowywany | Zakładka "Wygląd" - logo management |
| v4.16.0 | 2026-01-22 | Wydany | CMS Page Menu Management |
| v4.15.0 | 2026-01-22 | Wydany | Inicjalizacja dokumentacji releases |
| v4.14.1 | 2026-01-22 | Wydany | Hotfix |
| v4.14.0 | 2026-01-22 | Wydany | - |
| v4.13.0 | 2026-01-21 | Wydany | CMS block styling, dark theme |
