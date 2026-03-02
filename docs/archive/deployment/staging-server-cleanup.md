# Staging Server Cleanup Guide

**Date:** 2026-01-08
**Purpose:** Migrate staging server from Git-based to Docker/curl-based deployment (ADR-010 compliance)
**Server:** srv1203357.hstgr.cloud (45.93.138.193)

---

## Overview

Staging server currently uses Git repository + local Docker builds. This guide migrates it to GHCR pre-built images + curl downloads, matching production architecture.

**Before:** Git repo → local build → run containers
**After:** curl configs → pull GHCR image `:develop` → run containers

---

## Pre-Cleanup Checklist

### 1. Verify GHCR Access

Ensure staging server can pull from GitHub Container Registry:

```bash
# SSH to staging server
ssh root@45.93.138.193

# Test GHCR login
echo "$GITHUB_TOKEN" | docker login ghcr.io -u patrykgielo --password-stdin

# Test image pull
docker pull ghcr.io/patrykgielo/paradocks:develop
```

**If login fails:**
- Generate new GitHub Personal Access Token with `read:packages` scope
- Add to staging server environment or GitHub Secrets

### 2. Backup Current State

```bash
# SSH to staging server
ssh root@45.93.138.193
cd /var/www/paradocks

# Create backup directory with timestamp
BACKUP_DIR="/root/paradocks-git-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Backup Git repository
cp -r .git "$BACKUP_DIR/"
echo "Git backup: $BACKUP_DIR/.git"

# Backup .env file (CRITICAL - contains secrets!)
cp .env "$BACKUP_DIR/.env"
echo ".env backup: $BACKUP_DIR/.env"

# Verify backup
ls -lah "$BACKUP_DIR"
```

**Expected output:**
```
drwxr-xr-x 8 root root 4.0K Jan  8 12:00 .git
-rw-r--r-- 1 root root 2.1K Jan  8 12:00 .env
```

---

## Cleanup Steps

### Step 1: Stop All Containers

```bash
cd /var/www/paradocks
docker compose -f docker-compose.staging.yml down
```

**Verify:** `docker ps` should show no `paradocks-staging-*` containers.

### Step 2: Remove Git Repository

```bash
cd /var/www/paradocks

# Remove .git directory (Git history)
rm -rf .git

# Remove application code (will be replaced by Docker volumes)
rm -rf app/ bootstrap/ config/ database/ lang/ public/ resources/ routes/ storage/ tests/

# Remove build artifacts
rm -rf node_modules/ vendor/

# Remove local config files
rm -f .gitignore .editorconfig phpunit.xml composer.json composer.lock package.json package-lock.json
```

**Keep these files/directories:**
- `.env` (production secrets)
- `docker/nginx/` (nginx configs - will be overwritten by curl)
- `docker-compose.staging.yml` (will be overwritten by curl)

### Step 3: Verify Clean State

```bash
ls -la /var/www/paradocks
```

**Expected structure:**
```
drwxr-xr-x  3 root root 4096 Jan  8 12:05 .
drwxr-xr-x  5 root root 4096 Jan  8 12:05 ..
-rw-r--r--  1 root root 2100 Jan  8 12:05 .env
drwxr-xr-x  3 root root 4096 Jan  8 12:05 docker
-rw-r--r--  1 root root 5432 Jan  8 12:05 docker-compose.staging.yml
```

---

## Post-Cleanup Setup

### Step 1: Download Fresh Configs

```bash
cd /var/www/paradocks

# Create nginx directory
mkdir -p docker/nginx

# Download docker-compose.staging.yml from develop branch
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
  -o docker-compose.staging.yml \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/develop/docker-compose.staging.yml"

# Download nginx config
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
  -o docker/nginx/app.staging.conf \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/develop/docker/nginx/app.staging.conf"

# Verify downloads
ls -lh docker-compose.staging.yml docker/nginx/app.staging.conf
```

### Step 2: Pull Docker Image

```bash
# Login to GHCR
echo "$GITHUB_TOKEN" | docker login ghcr.io -u patrykgielo --password-stdin

# Pull latest develop image
docker compose -f docker-compose.staging.yml pull
```

**Expected output:**
```
[+] Pulling 5/5
 ✔ app Pulled
 ✔ horizon Pulled
 ✔ scheduler Pulled
 ✔ mysql Pulled
 ✔ redis Pulled
```

### Step 3: Start Containers

```bash
docker compose -f docker-compose.staging.yml up -d
```

**Verify:**
```bash
docker compose -f docker-compose.staging.yml ps
```

**Expected output:**
```
NAME                           STATUS          PORTS
paradocks-staging-app          Up 30 seconds
paradocks-staging-horizon      Up 30 seconds
paradocks-staging-mysql        Up 30 seconds   0.0.0.0:3306->3306/tcp
paradocks-staging-nginx        Up 30 seconds   0.0.0.0:80->80/tcp, 0.0.0.0:443->443/tcp
paradocks-staging-redis        Up 30 seconds   0.0.0.0:6379->6379/tcp
paradocks-staging-scheduler    Up 30 seconds
```

### Step 4: Run Migrations & Storage Setup

```bash
# Wait for MySQL to be ready
until docker compose -f docker-compose.staging.yml exec -T mysql mysqladmin ping -h localhost --silent; do
  echo "Waiting for MySQL..."
  sleep 2
done

# Run migrations
docker compose -f docker-compose.staging.yml exec -T app php artisan migrate --force

# Seed essential data
docker compose -f docker-compose.staging.yml exec -T app php artisan db:seed --force --class=RolesAndPermissionsSeeder
docker compose -f docker-compose.staging.yml exec -T app php artisan db:seed --force --class=EmailTemplateSeeder

# Create storage symlink
docker compose -f docker-compose.staging.yml exec -T app php artisan storage:link

# Optimize Laravel
docker compose -f docker-compose.staging.yml exec -T app php artisan optimize
docker compose -f docker-compose.staging.yml exec -T app php artisan filament:optimize
```

---

## Verification Checklist

### ✅ Health Check

```bash
# Test health endpoint
curl -s https://srv1203357.hstgr.cloud/health | jq
```

**Expected:**
```json
{
  "status": "healthy",
  "checks": {
    "database": true,
    "redis": "PONG"
  }
}
```

### ✅ Container Status

```bash
docker compose -f docker-compose.staging.yml ps
```

All containers should show `Up` status.

### ✅ Storage Configuration

```bash
# Verify FILESYSTEM_DISK
docker compose -f docker-compose.staging.yml exec -T app printenv FILESYSTEM_DISK
```

**Expected:** `public`

```bash
# Verify storage symlink
docker compose -f docker-compose.staging.yml exec -T app ls -la public/storage
```

**Expected:** Symlink to `/var/www/storage/app/public`

### ✅ Nginx Static Files

```bash
# Test CSS/JS assets (if any exist)
curl -I https://srv1203357.hstgr.cloud/build/assets/app.css
```

**Expected:** HTTP 200 or 404 (if assets not built yet)

### ✅ Mailpit (Email Testing)

```bash
curl -I http://srv1203357.hstgr.cloud:8025
```

**Expected:** HTTP 200

### ✅ Test Deployment Flow

Trigger GitHub Actions workflow manually to verify curl-based deployment:

1. Go to GitHub Actions: https://github.com/patrykgielo/paradocks/actions
2. Select "Deploy to Staging" workflow
3. Click "Run workflow" on `develop` branch
4. Monitor logs - should NOT see `git fetch` commands
5. Should see curl downloads + Docker pull

---

## Rollback Plan

If cleanup fails, restore from backup:

```bash
# Stop containers
docker compose -f docker-compose.staging.yml down

# Restore Git repository
cp -r "$BACKUP_DIR/.git" /var/www/paradocks/

# Restore .env
cp "$BACKUP_DIR/.env" /var/www/paradocks/

# Reset to develop branch
cd /var/www/paradocks
git reset --hard origin/develop

# Rebuild containers (old way)
docker compose -f docker-compose.staging.yml up -d --build
```

---

## Post-Cleanup Notes

### What Changed

**Before:**
- Git repo in `/var/www/paradocks`
- Local Docker builds from source code
- `git fetch` + `git reset --hard` deployments
- Application code in mounted volumes

**After:**
- No Git repo (configs downloaded via curl)
- Pre-built GHCR images (`:develop` tag)
- curl downloads + Docker pull deployments
- Application code inside Docker image

### Deployment Flow

**Old:**
```
GitHub Actions → SSH → git fetch → local docker build → restart containers
```

**New:**
```
GitHub Actions → Build :develop image → Push to GHCR → SSH → curl configs → docker pull :develop → restart containers
```

### Benefits

1. **Parity with Production:** Staging now matches production architecture (ADR-010)
2. **Faster Deployments:** No local builds (pull pre-built images)
3. **Consistent Testing:** Staging tests same Docker images that go to production
4. **Simpler Server:** No Git dependencies, smaller attack surface
5. **Easier Rollback:** Deploy previous `:develop-$SHA` tag

---

## Troubleshooting

### Issue: GHCR Login Fails

**Symptom:** `Error response from daemon: login attempt failed`

**Solution:**
```bash
# Generate new GitHub PAT with read:packages scope
# https://github.com/settings/tokens

# Test login
echo "$NEW_TOKEN" | docker login ghcr.io -u patrykgielo --password-stdin
```

### Issue: Health Check Returns 503

**Symptom:** `curl https://srv1203357.hstgr.cloud/health` returns 503

**Solution:**
```bash
# Check container logs
docker compose -f docker-compose.staging.yml logs app

# Check database connection
docker compose -f docker-compose.staging.yml exec -T app php artisan tinker
>>> DB::connection()->getPdo();
```

### Issue: Storage Symlink Missing

**Symptom:** Uploaded images return 404

**Solution:**
```bash
# Recreate symlink
docker compose -f docker-compose.staging.yml exec -T app php artisan storage:link

# Verify
docker compose -f docker-compose.staging.yml exec -T app ls -la public/storage
```

---

## Related Documentation

- **ADR-010:** CI/CD Deployment Strategy (`docs/decisions/ADR-010-cicd-deployment-strategy.md`)
- **Git Workflow:** `docs/deployment/GIT_WORKFLOW.md`
- **Production Deployment:** `docs/deployment/runbooks/ci-cd-deployment.md`

---

**Last Updated:** 2026-01-08
**Next Review:** After first successful staging deployment with new architecture
