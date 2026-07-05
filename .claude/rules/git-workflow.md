# Git Workflow Rules - CRITICAL

## AI Attribution

Wyłączone przez `.claude/settings.local.json` → `"attribution": {"commit": "", "pr": ""}`. System automatycznie NIE dodaje markerów.

## PreToolUse Hook — Auto-Blokuje

- Commit do `develop`/`main` bezpośrednio
- Push `main` z nie-release/hotfix brancha
- `gh pr create` bez `--base develop`
- `migrate:fresh`, `FILESYSTEM_DISK=local`

**False positive workaround:** `git merge main` blokowane (string match). Użyj `git merge origin/main`.

## BEZWZGLĘDNY ZAKAZ

1. NIGDY commit bezpośrednio do `main` — przez release/* lub hotfix/*
2. NIGDY commit bezpośrednio do `develop` — przez feature/*

## Flow

```
feature/* → develop (PR) → main (PR)
```

```bash
# Nowy feature
git checkout develop && git pull
git checkout -b feature/nazwa
git push -u origin feature/nazwa
gh pr create --base develop --title "feat: opis"
```

## Nazewnictwo

`feature/*` | `bugfix/*` | `release/*` (z develop) | `hotfix/*` (z main)

## GitHub CLI

```bash
gh pr create --base develop --title "feat: opis" --body "## Summary\n..."
gh pr merge 123 --squash
gh pr list --state open
```
