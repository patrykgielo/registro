# Deployment Rules - CRITICAL

**UAT żyje**: `srv1342834.hstgr.cloud`, domena `registrolabs.com`, jeden tenant.
**PreProd** (`registroapps.com`) — maszyna niekupiona, nic z niej nigdy nie uruchomione.
Wszystkie GitHub Actions: `workflow_dispatch`.

Procedury operatora: `app/docs/deployment/instalacja-tenanta-od-zera.md`.
Kroniki incydentów: `ci-cd-troubleshooting.md`. Plan: `~/.claude/plans/dwie-maszyny-uat-preprod.md`.

---

## BEZWZGLĘDNY ZAKAZ — NIGDY:

1. `.env`, `.env.production`, `.env.staging` w git — są w .gitignore
2. `db:seed` w deploy scripts — nadpisuje dane admina
3. `migrate:fresh / migrate:reset / migrate:refresh / db:wipe` — hook blokuje. Tylko deweloper ręcznie.
4. Testów bez `.env.testing` — Docker OS-env (priorytet 3) bije phpunit.xml (4) i Dotenv (5),
   więc `RefreshDatabase` uderzy w dev MySQL. Incydent 2026-03-17: utrata całej bazy dev.
5. `/goal` ani `/loop` wokół migracji, tagowania release, deployu lub operacji na produkcyjnej
   bazie — bug CC #67665: agent w takiej pętli złamał jawne ograniczenie, żeby z niej wyjść.

## Docker: plik ≠ rzeczywistość

**Edycja `docker-compose*.yml` NIE zmienia działających kontenerów.** Po usunięciu service'u:
`docker stop && docker rm` albo `down && up -d`. Potem porównaj `docker ps -a` z
`docker compose config --services` — co działa, a nie jest na liście, to orphan.

**NIGDY service `queue` gdy działa Horizon** — musi być jedynym konsumentem kolejek.

**NIGDY `docker compose run`/`config` w forced-command recovery path**, gdy plik ma `${VAR:?}` —
Compose interpoluje CAŁY plik przed wyborem usługi, więc zepsuty `.env` (dokładnie ten scenariusz,
dla którego recovery istnieje) wywala komendę przed jej wykonaniem.

**`docker run` na naszym obrazie ZAWSZE `--entrypoint sh`** — `docker/entrypoint.sh` odmawia startu
jako root. Bez tego `stage_volume()` cicho robił pusty backup obu wolumenów storage (2026-08-10).

## Dwie maszyny i stacki tenantów

`docker-compose.prod.yml` jest DWUCELOWY: legacy shared stack ORAZ szablon per-tenant.
`TENANT_PREFIX` (osobna od `TENANT_SLUG`) steruje `container_name` i prefiksami
Redis/Cache/Session/Horizon — **puste = `registro-*`, jak dziś**. Port 80 domyślnie loopback-only;
legacy dostaje publiczny bind z `docker-compose.legacy-public-ports.override.yml`, doklejanego
automatycznie przez `deploy.sh`.

**PreProd jest legacy-free**: `/var/www/registro` istnieje TYLKO jako katalog sterujący
(`.git` + `.env` z `APP_DOMAIN`, `CERT_DIR`, `NGINX_RELOAD_CONTAINER=registro-edge-nginx`).
`docker-compose.prod.yml` tam NIGDY nie wstaje. `validate-env.sh production` i `deploy-init.sh`
są wyłącznie dla maszyny ze stackiem legacy.

**Przenoszenie tenanta między maszynami** — procedura, nie skrypt (granica SSH do maszyny, której
możemy nie kontrolować). `.env.secrets` kopiuje się ZAWSZE bajt w bajt: `APP_KEY` szyfruje
`audit_logs`. Hasła DB/Redis mogą być inne, bo migracja idzie przez dump+restore.
**Pułapka: `apply.sh` regeneruje `.env` w całości przy KAŻDYM uruchomieniu** — pominięcie `[hosts]`
przy kolejnym release cicho cofa zmianę domeny.

## Testy warstwy powłoki

3 935 linii skryptów wdrożeniowych. **Każdy naprawiony błąd w nich = trwały test**:
`bash tests/shell/run.sh`. Piaskownica wyrzucona po użyciu niczego nie chroni — tak właśnie
poprawka przechodziła w regresję dwa PR-y później.

## Critical Variables

```bash
FILESYSTEM_DISK=public  # ZAWSZE — nigdy 'local'!
APP_DEBUG=false         # Produkcja
APP_KEY=base64:...      # Non-empty
TRUSTED_PROXIES_CIDR=   # PUSTE bez brzegu. NIGDY '*'. Patrz middleware.md.
TENANT_PREFIX=          # PUSTE na legacy. Tenant: tenant-<slug>.
```
