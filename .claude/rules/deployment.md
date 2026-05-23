# Deployment Rules - CRITICAL

**No staging/production servers.** Local only via Docker Compose. All GitHub Actions: `workflow_dispatch` only.

---

## BEZWZGLĘDNY ZAKAZ — NIGDY:

1. `.env`, `.env.production`, `.env.staging` w git — są w .gitignore
2. `db:seed` w deploy scripts — nadpisuje dane admina
3. `migrate:fresh / migrate:reset / migrate:refresh / db:wipe` — NIGDY! Hook blokuje. Tylko deweloper ręcznie.
4. Testów bez `.env.testing` — Docker OS-env `DB_HOST=mysql` ma wyższy priorytet niż phpunit.xml → RefreshDatabase uderzy w dev MySQL!

### Incident 2026-03-17: RefreshDatabase wyczyściła dev MySQL
Docker OS-level env (`DB_HOST=mysql`, priorytet 3) wygrywa z phpunit.xml `<env>` (priorytet 4) i Dotenv (priorytet 5). Testy trafiły w MySQL → `migrate:fresh` → utrata danych. Fix: `.env.testing` committed w repo (Laravel ładuje zamiast `.env` gdy `APP_ENV=testing`) → SQLite `:memory:`.

## Critical Variables

```bash
FILESYSTEM_DISK=public  # ZAWSZE — nigdy 'local'!
APP_DEBUG=false         # Produkcja
APP_KEY=base64:...      # Non-empty
```
