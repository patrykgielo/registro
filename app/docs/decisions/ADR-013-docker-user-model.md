# ADR-013: Docker User Model (laravel:laravel UID 1000)

**Status:** ✅ Accepted
**Date:** 2025-12-09
**Context:** v0.6.1 deployment failure - Dockerfile USER vs entrypoint chown mismatch
**Decision Maker:** DevOps Team

---

## Problem Statement

v0.6.1 deployment caused 2-hour production outage due to inconsistent user model:
- Dockerfile: `USER laravel` (UID 1000)
- Entrypoint: `chown www-data:www-data` (user doesn't exist in Alpine container!)
- Result: Container restart loop, production down

## Decision

**We will use `laravel:laravel` (UID 1000, GID 1000) for ALL container processes.**

### Rationale

1. **Development Parity:** UID 1000 matches typical developer's primary UID
2. **Consistent Ownership:** Same user in dev, staging, and production
3. **Security:** Non-root user reduces attack surface (best practice)
4. **Simplicity:** Single user model, no complex permission mapping
5. **Docker Volumes:** Named volumes can be chown'd by root on VPS (one-time setup)

### Implementation

**Dockerfile (lines 129-134):**
```dockerfile
RUN addgroup -g 1000 laravel && \
    adduser -D -u 1000 -G laravel laravel && \
    chown -R laravel:laravel /var/www

USER laravel  # ← Container runs as this user
```

**Entrypoint.sh (CORRECT):**
```bash
# Files already owned by laravel:laravel
echo "ℹ️  Production mode: Files owned by $(id -un):$(id -gn)"
# NO chown needed!
```

**Entrypoint.sh (INCORRECT - v0.6.1 mistake):**
```bash
# ❌ WRONG - www-data doesn't exist in Alpine!
chown -R www-data:www-data /var/www/storage
```

## CRITICAL: What NOT To Do

### ❌ DO NOT chown to www-data

```bash
# WRONG - Causes restart loop!
if [ "$APP_ENV" = "production" ]; then
    chown -R www-data:www-data /var/www/storage  # USER DOESN'T EXIST!
fi
```

**Why this fails:**
1. Alpine PHP-FPM containers don't have `www-data` user by default
2. Even if they did, container runs as `laravel` (non-root), can't chown
3. `set -e` in entrypoint causes script to exit on chown failure
4. PHP-FPM never starts → Docker restarts container → infinite loop

### ✅ DO verify user at runtime

```bash
# CORRECT - Validate expected user
EXPECTED_USER="laravel"
CURRENT_USER=$(whoami)
if [ "$CURRENT_USER" != "$EXPECTED_USER" ]; then
    echo "❌ CRITICAL: Running as '$CURRENT_USER' but expected '$EXPECTED_USER'"
    exit 1
fi
```

## Validation Checklist

**Before ANY deployment:**
- [ ] Dockerfile `USER` directive is `laravel`
- [ ] Entrypoint.sh does NOT contain `chown www-data`
- [ ] CI/CD validates container startup with `APP_ENV=production`
- [ ] Pre-commit hook prevents user mismatches

## Emergency Recovery

If restart loop occurs in production:

```bash
ssh deployer@VPS_IP
cd /var/www/registro

# Fix ownership on VPS (as root)
sudo chown -R 1000:1000 /var/lib/docker/volumes/registro_storage-app-public/_data/
sudo chown -R 1000:1000 /var/lib/docker/volumes/registro_storage-framework/_data/
sudo chown -R 1000:1000 /var/lib/docker/volumes/registro_storage-logs/_data/

# Restart containers
docker compose -f docker-compose.prod.yml restart app horizon scheduler
```

## References

- Deployment History: [app/docs/deployment/deployment-history.md](../deployment/deployment-history.md)
- Known Issues: [app/docs/deployment/known-issues.md](../deployment/known-issues.md)
- Docker Guide: [app/docs/guides/docker.md](../guides/docker.md)
