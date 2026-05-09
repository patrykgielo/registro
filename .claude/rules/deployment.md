# Deployment Rules - CRITICAL

## Current Status

**No staging/production servers configured.** Development is local only via Docker Compose.

All GitHub Actions workflows are set to `workflow_dispatch` (manual trigger only).

---

## BEZWZGLĘDNY ZAKAZ - NIGDY NIE RÓB TEGO:

1. **NIGDY nie commituj .env, .env.production, .env.staging** - są w .gitignore
2. **NIGDY nie uruchamiaj seederów w CI/CD pipeline** - seedery nadpisują dane edytowane przez admina
3. **NIGDY nie uruchamiaj `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`** — w ŻADNYM środowisku! Hook blokuje. Tylko deweloper ręcznie.
4. **NIGDY nie uruchamiaj testów bez `.env.testing`** — Docker `.env` nadpisuje phpunit.xml i RefreshDatabase uderzy w dev MySQL!

### Incident 2026-03-17: RefreshDatabase wyczyściła dev MySQL

**Problem:** `docker compose exec -T app php artisan test` uruchomił testy z `RefreshDatabase` trait na dev MySQL zamiast SQLite in-memory.

**Przyczyna:** Docker ustawia `DB_HOST=mysql` jako OS-level env variable (priorytet 3). phpunit.xml `<env>` tagi mają priorytet 4 (niższy). `.env` Dotenv = priorytet 5-6. OS-level wygrywa → testy trafiły w MySQL `registro` → `RefreshDatabase` zrobiło `migrate:fresh` → utrata danych.

**Rozwiązanie:** `.env.testing` — Laravel ładuje go ZAMIAST `.env` gdy `APP_ENV=testing`. Ustawia `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.

**Zapobieganie:**
- `.env.testing` w repo (committed) — wymusza SQLite dla testów
- PreToolUse hook blokuje `migrate:fresh/reset/refresh/db:wipe` w KAŻDYM środowisku
- Rule: Claude NIGDY nie uruchamia destrukcyjnych DB operacji

## .env File Management

### Forbidden in Workflows

```yaml
# ZABRONIONE - Download .env from GitHub
- name: Download .env
  run: curl https://raw.githubusercontent.com/.../env.production

# ZABRONIONE - Write .env from secrets
- name: Create env
  run: echo "APP_KEY=${{ secrets.APP_KEY }}" > .env
```

## Critical Variables (MUST be validated)

```bash
APP_KEY          # Non-empty, base64:... format
REDIS_PASSWORD   # Non-empty
DB_PASSWORD      # Non-empty
FILESYSTEM_DISK  # Must be 'public' (not 'local')
APP_DEBUG        # Must be 'false' for production
```

## Seeder Management (CRITICAL)

**NIGDY nie uruchamiaj `php artisan db:seed` w deploy scripts!**

| Kontekst | Dozwolone? |
|----------|------------|
| Lokalne dev (`php artisan migrate:fresh --seed`) | Tylko deweloper ręcznie! Claude NIGDY! |
| CI testy (ephemeral DB) | Tak |
| Deploy staging/production | NIGDY |

## CI/CD Workflows (GitHub Actions)

All workflows disabled (workflow_dispatch only). Will be reconfigured when servers are set up.

| Workflow | Status |
|----------|--------|
| `ci-staging.yml` | Disabled |
| `test.yml` | Disabled |
| `deploy-production.yml` | Disabled |
| `fix-styling.yml` | Disabled |
| `changelog.yml` | Disabled |
| `cleanup-cache.yml` | Disabled |
