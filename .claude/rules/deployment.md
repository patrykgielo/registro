# Deployment Rules - CRITICAL

**No staging/production servers.** Local only via Docker Compose. All GitHub Actions: `workflow_dispatch` only.

---

## BEZWZGLĘDNY ZAKAZ — NIGDY:

1. `.env`, `.env.production`, `.env.staging` w git — są w .gitignore
2. `db:seed` w deploy scripts — nadpisuje dane admina
3. `migrate:fresh / migrate:reset / migrate:refresh / db:wipe` — NIGDY! Hook blokuje. Tylko deweloper ręcznie.
4. Testów bez `.env.testing` — Docker OS-env `DB_HOST=mysql` ma wyższy priorytet niż phpunit.xml → RefreshDatabase uderzy w dev MySQL!
5. `/goal` ani `/loop` owinięte wokół migracji, tagowania release, deploy lub operacji na produkcyjnej bazie — znany bug Claude Code (#67665): agent utknięty w takiej pętli złamał jawną instrukcję czasową, żeby z niej wyjść. Te zadania ZAWSZE wykonuj interaktywnie, krok po kroku, bez automatycznego "dokończ do warunku X".

### Incident 2026-03-17: RefreshDatabase wyczyściła dev MySQL
Docker OS-level env (`DB_HOST=mysql`, priorytet 3) wygrywa z phpunit.xml `<env>` (priorytet 4) i Dotenv (priorytet 5). Testy trafiły w MySQL → `migrate:fresh` → utrata danych. Fix: `.env.testing` committed w repo (Laravel ładuje zamiast `.env` gdy `APP_ENV=testing`) → SQLite `:memory:`.

## Docker: plik ≠ rzeczywistość

**Edycja `docker-compose*.yml` NIE zmienia działających kontenerów.** Kontener z własnym restart policy przeżyje usunięcie service'u z pliku w nieskończoność. Po każdym usunięciu service'u: `docker stop <nazwa> && docker rm <nazwa>` albo `docker compose down && up -d`. Potem porównaj `docker ps -a` z `docker compose config --services` — cokolwiek działa, a nie jest na liście, to orphan.

**NIGDY service `queue` (`queue:work`) gdy działa Horizon** — Horizon musi być jedynym konsumentem kolejek, inaczej joby są niewidoczne w dashboardzie, a failed list i metryki martwe.

Pełne kroniki obu incydentów (2026-06-29, 2026-07-07): `ci-cd-troubleshooting.md`.

## Critical Variables

```bash
FILESYSTEM_DISK=public  # ZAWSZE — nigdy 'local'!
APP_DEBUG=false         # Produkcja
APP_KEY=base64:...      # Non-empty
TRUSTED_PROXIES_CIDR=   # ZOSTAW PUSTE — brak edge network dziś. NIGDY '*'. Patrz middleware.md.
```
