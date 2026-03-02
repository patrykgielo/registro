# Staging Deployment Rules

## Current Status

**No staging server configured.** This file contains general rules for when staging is set up.

---

## BEZWZGLĘDNY ZAKAZ

**NIGDY** nie wykonuj bezpośrednio na serwerze staging/production:

```bash
# ZAKAZANE KOMENDY NA SERWERZE:
git pull
git checkout
git fetch
git merge
git reset
git stash
```

## SSH User Rules

**ZAWSZE używaj `deploy` user, NIGDY `root` dla operacji aplikacyjnych.**

Root tworzy pliki owned by `root:root`. CI/CD używa `deploy` user i nie może nadpisać plików root.

## Prawidłowy workflow deploy na staging

1. **Lokalne zmiany** → commit → push do `develop`
2. **CI automatycznie deployuje** (when configured)

## Dozwolone akcje na serwerze

```bash
# OK - diagnostyka
docker compose -f docker-compose.staging.yml logs
docker compose -f docker-compose.staging.yml ps

# OK - cache/config
docker compose -f docker-compose.staging.yml exec -T app php artisan optimize:clear

# OK - migracje (po deploy)
docker compose -f docker-compose.staging.yml exec -T app php artisan migrate --force
```
