# Git Workflow Rules - CRITICAL

## AI Attribution

Wyłączone przez `.claude/settings.local.json` → `"attribution":{"commit":"","pr":""}`.

## Model trzywarstwowy (od 2026-08-16)

```
feature/* → develop (PR) → staging (PR) → main (PR)
```

`develop` = integracyjna, gałąź DOMYŚLNA repo (workflow spoza niej → `HTTP 404` na `gh workflow
run`). `staging` = tnie tagi `rc*`, dziś UAT. `main` = produkcja, PreProd po zakupie. `hotfix/*` =
łatka awaryjna z `main`, omija `staging`. `release/*` superseded przez stałą `staging`. Flow:
`.github/workflows/RELEASE_PROCESS.md`.

## PreToolUse Hook — Auto-Blokuje

- Commit do `develop`/`staging`/`main`
- Push `main` z gałęzi innej niż `release/*`/`hotfix/*`
- `gh pr create` ze `staging` bez `--base main`; z innej gałęzi bez `--base develop`/`staging`
- `migrate:fresh`, `FILESYSTEM_DISK=local`

**False positive:** `git merge main` blokowane (string match), użyj `git merge origin/main`.

```bash
git checkout -b feature/nazwa develop
gh pr create --base develop --title "feat: opis"
```
