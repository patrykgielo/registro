# Deployment Rules - CRITICAL

**UAT żyje**: `srv1342834.hstgr.cloud`, domena `registrolabs.com`, jeden tenant.
**PreProd** (`registroapps.com`) — maszyna niekupiona, nic z niej nigdy nie uruchomione.
Wszystkie GitHub Actions: `workflow_dispatch` (zweryfikowane 2026-08-16, 6/6 plików workflow).

Procedury operatora: `app/docs/deployment/instalacja-tenanta-od-zera.md`.
Docker/stacki tenantów/testy powłoki (szczegóły) + kroniki incydentów: `ci-cd-troubleshooting.md`.
Plan: `~/.claude/plans/dwie-maszyny-uat-preprod.md`.

---

## BEZWZGLĘDNY ZAKAZ — NIGDY:

1. `.env`, `.env.production`, `.env.staging` w git — są w .gitignore (zweryfikowane: tylko
   `.example`/`.testing` są trackowane).
2. `db:seed` BEZ `--class=` w deploy scripts — bare `db:seed` uruchamia `DatabaseSeeder`, które
   nadpisuje dane admina. Seedery scoped (`--class=RolePermissionSeeder` itp., jak w
   `deploy-init.sh`) są bezpieczne i już używane.
3. `migrate:fresh / migrate:reset / migrate:refresh / db:wipe` — hook blokuje (zweryfikowane
   empirycznie). Tylko deweloper ręcznie.
4. Testów bez `.env.testing` — Docker OS-env bije phpunit.xml i Dotenv, więc `RefreshDatabase`
   uderzy w dev MySQL. Incydent 2026-03-17: utrata całej bazy dev.
5. `/goal` ani `/loop` wokół migracji, tagowania release, deployu lub operacji na produkcyjnej
   bazie — bug CC #67665: agent w takiej pętli złamał jawne ograniczenie, żeby z niej wyjść.

## Docker: plik ≠ rzeczywistość

**Edycja `docker-compose*.yml` NIE zmienia działających kontenerów** — jedyny fakt tej sekcji,
który szkodzi natychmiast, przez goły `docker`/`docker compose` w Bashu, bez dotknięcia żadnego
pliku. Reconciluj jawnie, potem porównaj `docker ps -a` z `docker compose config --services`.
Mechanika orphanów, `NIGDY queue z Horizonem`, `docker run --entrypoint sh`, zakaz `docker compose
run`/`config` w recovery path z `${VAR:?}`: `ci-cd-troubleshooting.md`.

`TENANT_PREFIX` (osobna od `TENANT_SLUG`) — puste = `registro-*`, jak dziś (legacy). Reszta
mechaniki stacków tenantów i przenoszenia między maszynami: `tenant-apply.md`,
`tenant-compose-stack.md`, `ci-cd-troubleshooting.md`.

## Testy warstwy powłoki

Tysiące linii skryptów wdrożeniowych w `scripts/**`. **Każdy naprawiony błąd w nich = trwały
test**: `bash tests/shell/run.sh`. Piaskownica wyrzucona po użyciu niczego nie chroni — tak
właśnie poprawka przechodziła w regresję dwa PR-y później.

## Critical Variables

```bash
FILESYSTEM_DISK=public  # ZAWSZE — nigdy 'local'! Hook blokuje 'local', ale nie samo pominięcie
                         # zmiennej — .env psuje się bez dotknięcia żadnego pliku ścieżkowego.
APP_DEBUG=false          # Produkcja
APP_KEY=base64:...       # Non-empty — szyfruje audit_logs (EncryptedJsonCast, zweryfikowane)
TRUSTED_PROXIES_CIDR=    # PUSTE bez brzegu. NIGDY '*'. Patrz middleware.md.
```
