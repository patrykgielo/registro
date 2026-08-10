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

`docker-compose.prod.yml` jest teraz DWUCELOWY (task 4, stack-per-tenant epic): legacy shared stack
(dziś na serwerze, `TENANT_PREFIX`/`TENANT_SLUG` puste) I szablon per-tenant. `TENANT_PREFIX` (NOWA,
osobna od `TENANT_SLUG`) steruje `container_name`/prefiksami Redis-Cache-Session-Horizon — puste =
`registro-*`, identycznie jak dziś. Port 80 domyślnie loopback-only (tenant-safe); legacy dostaje
publiczny bind z DRUGIEGO pliku, `docker-compose.legacy-public-ports.override.yml`, który
`scripts/server/deploy.sh` dokleja automatycznie (`COMPOSE_ARGS`) — zero zmian w `.env`. NIGDY
`docker compose run`/`config` w forced-command recovery path gdy plik ma `${VAR:?}` — patrz
`ci-cd-troubleshooting.md`. Pełny opis i kroki operatora: `app/docs/deployment/tenant-compose-stack.md`.

## Przenoszenie tenanta między maszynami (Faza 3, dwie maszyny)

Jedna procedura na zmianę domeny (ta sama maszyna) i przeniesienie na inną maszynę — nie skrypt,
świadomie (granica SSH do maszyny, której możemy nie kontrolować albo która już nie istnieje).
Pełna procedura + 6-punktowa weryfikacja: `app/docs/deployment/instalacja-tenanta-od-zera.md` →
Część 9. `.env.secrets` (zwłaszcza `APP_KEY` — szyfruje `audit_logs`) kopiuje się ZAWSZE bajt w
bajt; `DB_PASSWORD`/`DB_ROOT_PASSWORD`/`REDIS_PASSWORD` mogą być inne na maszynie docelowej, bo
migracja idzie przez dump+restore, nie przez surowy wolumen MySQL/Redis. Pułapka: `apply.sh`
regeneruje `.env` w całości na KAŻDYM uruchomieniu — pominięcie `[hosts]` przy kolejnym release
cicho cofa zmianę domeny, bez błędu.

`docker run` na WŁASNYM obrazie projektu (`ghcr.io/patrykgielo/registro`) zawsze wymaga
`--entrypoint sh` — obraz ma własny `docker/entrypoint.sh` odmawiający startu jako root. Bez tego
`stage_volume()` w `apply.sh`/`tenant-backup.sh` cicho tworzyło pusty backup obu wolumenów storage
(znalezione 2026-08-10, patrz `ci-cd-troubleshooting.md`).

## Dwie maszyny (plan `dwie-maszyny-uat-preprod.md`)

UAT (`srv1342834`, dziś) = `registrolabs.com`, stack legacy + apply.sh. PreProd (do kupienia,
Faza 4 — **nigdy nic z niej nie uruchomione**) = `registroapps.com`, **legacy-free**: `/var/www/
registro` istnieje TYLKO jako katalog sterujący (`.git` + minimalny `.env`:
`APP_DOMAIN`/`CERT_DIR`/`NGINX_RELOAD_CONTAINER=registro-edge-nginx`) — `docker-compose.prod.yml`
tam NIGDY nie wstaje. Pełny `.env.production.example`/`validate-env.sh production`/`deploy-init.sh`
— tylko dla maszyny ze stackiem legacy. Procedura: `instalacja-tenanta-od-zera.md` Część 10.

## Critical Variables

```bash
FILESYSTEM_DISK=public  # ZAWSZE — nigdy 'local'!
APP_DEBUG=false         # Produkcja
APP_KEY=base64:...      # Non-empty
TRUSTED_PROXIES_CIDR=   # ZOSTAW PUSTE — brak edge network dziś. NIGDY '*'. Patrz middleware.md.
TENANT_PREFIX=          # PUSTE na legacy stacku. Tenant: TENANT_PREFIX=tenant-<slug>, patrz wyżej.
```
