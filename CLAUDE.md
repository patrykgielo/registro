# CLAUDE.md - Registro Project

## Rules System

**BEFORE starting ANY work:** Read `.claude/rules/_INDEX.md`

Rules are organized in TIERs:
- **TIER 1** (CRITICAL): self-improvement, git-workflow, deployment, security
- **TIER 2** (Implementation): models, services, filament, tests
- **TIER 3** (Enhancement): frontend, animations, api

**PreToolUse Hook** automatically blocks dangerous git operations.

**Self-Learning Rules:**
- DOCUMENT every resolved error immediately (rules/docs)
- RESEARCH with web-research-specialist when unsure

---

## Project Stack

- **Laravel 12**, PHP 8.2+, MySQL 8.0
- **Filament v4** (namespace breaking changes - see filament.md)
- **Tailwind CSS 4.0**, Vite 7+
- **Docker Compose** (9 services)

**URLs:**
- Local: https://registro.local:8444

**Repo:** `patrykgielo/registro`

**Note:** CI/CD workflows are disabled (workflow_dispatch only). No staging/production servers configured yet.

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

## Quick Commands

```bash
# Development
composer run dev

# Tests
./vendor/bin/pint --test && php artisan test
```

---

## Git Workflow

```
feature/* → develop (PR) → main (PR)
```

**CI/CD:** All workflows set to `workflow_dispatch` only (manual trigger). No auto-deploy configured.

**Protected by PreToolUse hook:**
- Can't commit to develop/main directly
- Can't `gh pr create` without `--base develop`

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

- [ ] Tests pass: `./vendor/bin/pint --test && php artisan test`
- [ ] Created feature branch (NOT direct to develop)
- [ ] Documentation updated if needed
