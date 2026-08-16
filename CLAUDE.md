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

**BEFORE starting ANY work:** Read `.claude/rules/_INDEX.md`

Rules are organized in TIERs:
- **TIER 1** (CRITICAL): self-improvement, git-workflow, deployment, security, agent-usage
- **TIER 2** (Implementation): models, services, filament, tests
- **TIER 3** (Enhancement): frontend, animations, api

**Hooks (deterministic enforcement):**
- **PreToolUse** — blocks dangerous git operations
- **UserPromptSubmit** — injects agent-first reminder on implementation tasks
- **Stop** — blocks completion without documentation updates
- **Notification** — re-injects TIER 1 rules after context compaction

**Self-Learning Rules:**
- DOCUMENT every resolved error immediately (rules/docs)
- RESEARCH with web-research-specialist when unsure

---

## Project Stack

- **Laravel 12**, PHP 8.3+, MySQL 8.0
- **Filament v4** (namespace breaking changes - see filament.md)
- **Tailwind CSS 4.0**, Vite 7+
- **Docker Compose** (8 services — `docker compose config --services`)

**URLs:**
- Local: https://registro.local:8444

**Repo:** `patrykgielo/registro`

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
```
ALL docs are in: app/docs/
NOT in: /docs/ (root)
Archived legacy docs: docs/archive/
```

---

## Skills (slash commands)

| Skill | Effort | Purpose |
|-------|--------|---------|
| `/implement <task>` | high | Gated workflow: agent → branch → code → test → docs |
| `/ship <task>` | medium | Lightweight loop for small fixes: builder → bounded retry (max 3) → code-reviewer |
| `/review [scope]` | high | Code review (architecture, security, docs) |
| `/deep-research <topic>` | high | Web research with Firecrawl |
| `/commit [msg]` | low | Stage, Pint, test, conventional commit |
| `/pr [title]` | low | Push + create PR to develop |
| `/test [--filter]` | low | Run Pint + PHPUnit in Docker |
| `/catchup` | low | Session start briefing (recent changes, PRs) |
| `/browser-use` | — | Browser automation (visible Chrome, user profile) |

### Kiedy Workflow / Agent Team / `/ship` / `/implement`

- **Mały fix, jeden-kilka plików** (typo, drobna poprawka, jeden bug) → `/ship` — builder → bounded retry (max 3 cykle) → code-reviewer, bez pełnego 7-gate procesu.
- **Nowy feature / zmiana architektury / 5+ plików** → `/implement` — pełny gated proces, dokumentacja obowiązkowa (Stop hook i tak to wymusi).
- **Duże, wieloobszarowe zadanie** (audyt bezpieczeństwa całego API, redesign wielu ekranów) → Agent Team, 3-5 teammates — patrz `docs/guides/agent-teams.md`.
- **Dziesiątki niezależnych, równoległych sprawdzeń** (audyt N endpointów, deep research, przegląd wielu plików tym samym wzorcem) → Workflow tool.
- Nie odpalaj Workflow ani Agent Team na drobną poprawkę — to overkill i marnowanie tokenów na coś, co `/ship` załatwia w minutę.

## Quick Commands

```bash
# Development
composer run dev

# OBOWIĄZKOWE po każdej zmianie Blade/CSS/JS — bez wyjątków!
docker compose exec -T app npm run build
# Jeśli po build nadal są stare style → plik public/hot blokuje → usuń go:
docker compose exec -T app rm -f public/hot

# Tests (in Docker — .env.testing forces SQLite)
docker compose exec -T app ./vendor/bin/pint --test && docker compose exec -T app php artisan test
```

---

## Git Workflow

```
feature/* → develop (PR) → staging (PR) → main (PR)
```

| Gałąź | Rola | Środowisko |
|-------|------|------------|
| `develop` | integracyjna, **gałąź domyślna repo**, nigdzie nie wdrażana | własny VPS — kiedyś |
| `staging` | **stąd tnie się tagi `rc*`** | UAT (`registrolabs.com`) |
| `main` | wydania produkcyjne | PreProd — po zakupie maszyny |

**Gałąź domyślna to `develop`, nie `main`** — GitHub rejestruje workflowy z gałęzi domyślnej, więc
nowy plik workflowa poza nią daje `HTTP 404` przy `gh workflow run`.

**CI/CD:** wszystkie workflowy na `workflow_dispatch`. **Nic nie odpala się samo** — ani po merge,
ani po wypchnięciu taga. Nieodwracalny jest dopiero `gh workflow run deploy-production.yml`.

**Egzekwuje PreToolUse hook** (`.claude/hooks/pre-tool-use.sh`):
- brak bezpośrednich commitów na `develop`, `staging`, `main`
- `gh pr create` musi celować w `develop` lub `staging`; ze `staging` w `main`

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

## Definition of Done

- [ ] Agent used BEFORE implementation
- [ ] Tests pass: `./vendor/bin/pint --test && php artisan test`
- [ ] Created feature branch (NOT direct to develop)
- [ ] Documentation/rules updated (Stop hook enforces this)

---

## REMINDER (bottom anchor — do not remove)

- **AGENT FIRST** before any code
- **DOCS/RULES AFTER** every implementation (Stop hook blocks you otherwise)
- **FILESYSTEM_DISK=public** always (never local)
- **User model:** first_name/last_name (no `name` column)
