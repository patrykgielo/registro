# CLAUDE.md - Registro Project

## MANDATORY PROCESS (enforced by hooks)

**EVERY implementation task — hooks WILL block you if you skip:**

1. **AGENT FIRST** — `laravel-senior-architect` (PHP) or `frontend-ui-architect` (UI) before ANY code
2. **feature/* branch ONLY** — PreToolUse hook blocks commits to develop/main
3. **Pint + tests** — before commit: `./vendor/bin/pint --test && php artisan test`
4. **Docs/rules AFTER** — Stop hook blocks completion if 5+ source files changed with 0 docs/rules updates

**Use `/implement <task>` for guided workflow with mandatory gates.**

---

## Rules System

**BEFORE starting ANY work:** Read `.claude/rules/_INDEX.md` (TIER map + `paths` triggers).
Hooks that enforce this: `.claude/settings.local.json` (gitignored — recovery JSON in `claude-code-config.md`).

**Self-Learning Rules:**
- DOCUMENT every resolved error immediately (rules/docs)
- RESEARCH with web-research-specialist when unsure

---

## Project Stack

Stack is in `composer.json` / `package.json`; services in `docker compose config --services`.
**Filament v4 has namespace breaking changes** — see `filament.md`.

**Local:** https://registro.local:8444

**Note:** CI/CD workflows are disabled (workflow_dispatch only). **UAT is live** — `srv1342834.hstgr.cloud`, app domain `registrolabs.com`. PreProd (`registroapps.com`) is a machine not yet bought. See `app/docs/deployment/instalacja-tenanta-od-zera.md`.

---

## Critical Rules (Universal)

### FILESYSTEM_DISK
```
ALWAYS: FILESYSTEM_DISK=public
NEVER:  FILESYSTEM_DISK=local (breaks uploads!)
```

### User Model
```php
$user->first_name  // OK
$user->last_name   // OK
$user->name        // OK (accessor)
$user->name = "x"  // FORBIDDEN (column doesn't exist!)
```

### Documentation Location

**Dwa żywe drzewa. Wybierz po RODZAJU treści, nie po pierwszym pasującym katalogu.**

| Rodzaj | Katalog |
|---|---|
| Techniczna, wdrożeniowa, architektura, ADR | `app/docs/` — żywy indeks w `app/docs/README.md` |
| **Biznesowa** — ścieżki klienta / pracownika / właściciela | `docs/business/` |
| Opisy funkcji utrzymywane obok biznesowych | `docs/features/` |
| Archiwum, nie dopisywać | `docs/archive/` |

`app/docs/business/` **nie istnieje** — nie twórz go. Dokument biznesowy to zawsze
para `.md` + `.en.md`, wpis w `docs/business/README.md` (i `README.en.md`) oraz wpis
w `nav` w `docs-site/mkdocs.yml`. Bez tego ostatniego plik nie jest publikowany przez
portal i dla czytelnika po prostu nie istnieje — build nie ostrzega.

> Poprzednie brzmienie („ALL docs are in: app/docs/, NOT in: /docs/") było wykonywane
> dosłownie i 2026-08-27 wysłało całą dokumentację wielooddziałowości w złe drzewo.

---

## Kiedy Workflow / Agent Team / `/ship` / `/implement`

- **Mały fix, jeden-kilka plików** (typo, drobna poprawka, jeden bug) → `/ship` — builder → bounded retry (max 3 cykle) → code-reviewer, bez pełnego 7-gate procesu.
- **Nowy feature / zmiana architektury / 5+ plików** → `/implement` — pełny gated proces, dokumentacja obowiązkowa (Stop hook i tak to wymusi).
- **Duże, wieloobszarowe zadanie** (audyt bezpieczeństwa całego API, redesign wielu ekranów) → Agent Team, 3-5 teammates — patrz `docs/guides/agent-teams.md`.
- **Dziesiątki niezależnych, równoległych sprawdzeń** (audyt N endpointów, deep research, przegląd wielu plików tym samym wzorcem) → Workflow tool.
- Nie odpalaj Workflow ani Agent Team na drobną poprawkę — to overkill i marnowanie tokenów na coś, co `/ship` załatwia w minutę.

## Quick Commands

```bash
# OBOWIĄZKOWE po każdej zmianie Blade/CSS/JS — bez wyjątków!
docker compose exec -T app npm run build
# Jeśli po build nadal są stare style → plik public/hot blokuje → usuń go:
docker compose exec -T app rm -f public/hot

# Tests (in Docker — .env.testing forces SQLite)
docker compose exec -T app ./vendor/bin/pint --test && docker compose exec -T app php artisan test
```

---

## Key Documentation

| Topic | Location |
|-------|----------|
| Rules Index | `.claude/rules/_INDEX.md` |
| Full Docs | `app/docs/README.md` |
| Features | `app/docs/features/` |
| Filament v4 | `app/docs/guides/filament-v4-*.md` |
| Archived (legacy) | `docs/archive/` |

---

## REMINDER (bottom anchor — do not remove)

- **AGENT FIRST** before any code
- **DOCS/RULES AFTER** every implementation (Stop hook blocks you otherwise)
- **FILESYSTEM_DISK=public** always (never local)
- **User model:** first_name/last_name (no `name` column)
