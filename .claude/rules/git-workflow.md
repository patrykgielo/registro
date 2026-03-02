# Git Workflow Rules - CRITICAL

## AI Attribution - Wyłączone przez settings

AI markery (Co-Authored-By, Generated with Claude) są **wyłączone** przez:
- `.claude/settings.local.json` → `"attribution": {"commit": "", "pr": ""}`

Nie musisz się tym martwić - system automatycznie NIE dodaje markerów.

---

## PreToolUse Hook - Automatyczna ochrona

Hook `.claude/hooks/pre-tool-use.sh` **BLOKUJE** niebezpieczne operacje:
- Commit bezpośrednio do `develop` lub `main`
- Push do `main` z nie-release/hotfix brancha
- `migrate:fresh` w produkcji
- `FILESYSTEM_DISK=local`
- **`gh pr create` bez `--base develop`** (zapobiega PR do main)

**Hook działa automatycznie** - nie musisz pamiętać!

### Znane ograniczenia hooka

Hook używa prostego string matching, co może powodować **false positives**:

```bash
# ZABLOKOWANE (zawiera "main" w komendzie merge)
git merge main -m "..."

# WORKAROUND: użyj origin/main lub rozdziel komendy
git fetch origin && git merge origin/main -m "..."
```

---

## BEZWZGLĘDNY ZAKAZ - NIGDY NIE RÓB TEGO:

1. **NIGDY nie commituj bezpośrednio do `main`** - ZAWSZE przez release/* lub hotfix/*
2. **NIGDY nie commituj bezpośrednio do `develop`** - ZAWSZE przez feature/*
3. **NIGDY nie pushuj do main bez przejścia przez cały workflow**

## Obowiązkowy Flow

```
feature/* → develop (PR) → main (PR)
```

**CI/CD:** All workflows disabled (workflow_dispatch only). No auto-deploy.

### Nowa funkcjonalność:

```bash
# 1. Utwórz branch z develop
git checkout develop
git pull origin develop
git checkout -b feature/nazwa-funkcji

# 2. Pracuj na feature branch
git commit -m "feat(scope): opis"

# 3. Push i utwórz PR do develop (ZAWSZE --base develop!)
git push -u origin feature/nazwa-funkcji
gh pr create --base develop --title "feat: opis"
```

### Hotfix (krytyczne błędy):

```bash
git checkout main
git checkout -b hotfix/vX.Y.Z-opis
# ... fix ...
# PR do main
# Po merge: merge back do develop
```

## Weryfikacja przed KAŻDYM commitem

```bash
git branch  # Upewnij się że NIE jesteś na main lub develop
```

Jeśli jesteś na `main` lub `develop` - ZATRZYMAJ SIĘ i utwórz feature branch!

## Nazewnictwo branchy

- `feature/*` - nowe funkcje (z develop)
- `bugfix/*` - poprawki błędów (z develop)
- `release/*` - przygotowanie release (z develop)
- `hotfix/*` - krytyczne poprawki (z main)

## Przed rozpoczęciem pracy - ZAWSZE:

1. `git fetch origin`
2. `git checkout develop && git pull`
3. `git checkout -b feature/nazwa-funkcji`

## GitHub CLI (`gh`) - PREFEROWANY

**Zawsze używaj `gh` CLI zamiast GitHub UI gdy to możliwe.**

```bash
# Pull Requests (ZAWSZE z --base!)
gh pr create --base develop --title "feat: opis" --body "## Summary\n..."
gh pr list --state open
gh pr view 123
gh pr merge 123 --squash

# Issues
gh issue create --title "Bug: opis" --label "bug"
gh issue list --state open
```
