# CI/CD Unification Summary - v4.8.2

**Date:** 2026-01-08
**Issue:** Production deployment broken in v4.8.1 (Git operations on server without Git repo)
**Solution:** Unified Docker/curl-based deployment for both production AND staging

---

## What Was Changed

### 1. New Workflow: Build Develop Images

**File:** `.github/workflows/build-develop.yml` (NEW)

**Purpose:** Build Docker images from `develop` branch for staging deployments

**Triggers:**
- Push to `develop` branch
- Manual workflow dispatch

**Output:**
- `ghcr.io/patrykgielo/paradocks:develop` (rolling tag)
- `ghcr.io/patrykgielo/paradocks:develop-$SHA` (commit-specific tag for rollbacks)

### 2. Fixed Production Workflow

**File:** `.github/workflows/deploy-production.yml` (MODIFIED)

**Changes:**
- ❌ **REMOVED:** `git fetch origin main` + `git reset --hard origin/main`
- ✅ **ADDED:** curl downloads for docker-compose.prod.yml and nginx config
- ✅ **KEPT:** All v4.8.1 improvements (retry logic, health check, concurrency control)

**Why:** Production server has NO Git repository (works with GHCR images only)

### 3. Updated Staging Workflow

**File:** `.github/workflows/deploy-staging.yml` (MODIFIED)

**Changes:**
- ❌ **REMOVED:** `git fetch origin develop` + `git reset --hard origin/develop`
- ✅ **ADDED:** curl downloads for docker-compose.staging.yml and nginx config
- ✅ **CHANGED:** Now pulls pre-built `:develop` image from GHCR (no local builds)

**Why:** Align staging with production deployment strategy (Docker/curl-based)

### 4. Updated Staging Docker Compose

**File:** `docker-compose.staging.yml` (MODIFIED)

**Changes:**
- ❌ **REMOVED:** `build: context: .` (local builds)
- ✅ **CHANGED:** `image: ghcr.io/patrykgielo/paradocks:develop`
- ✅ **ADDED:** Storage volumes (match production architecture)
- ✅ **UPDATED:** All services (app, horizon, scheduler) to use GHCR images

**Why:** Staging must test same Docker images that deploy to production

### 5. Documentation

**New Files:**
- `docs/deployment/ADR-018-cicd-unification-docker-curl.md` - Complete ADR
- `docs/deployment/staging-server-cleanup.md` - Server cleanup guide
- `docs/deployment/CICD-UNIFICATION-SUMMARY.md` - This file

---

## Deployment Strategy Comparison

### Before (v4.8.1 - BROKEN)

**Production:**
```
Git tag → Build image → Push GHCR → SSH → git fetch ❌ → FAIL
```

**Staging:**
```
Push develop → SSH → git fetch → local build → restart containers
```

**Problems:**
- Production: Git operations fail (no Git repo)
- Staging: Different from production (local builds)

### After (v4.8.2+)

**Production:**
```
Git tag → Build image → Push GHCR → SSH → curl configs → docker pull → restart
```

**Staging:**
```
Push develop → Build image → Push GHCR → SSH → curl configs → docker pull → restart
```

**Benefits:**
- ✅ Identical deployment strategy
- ✅ No Git dependencies on servers
- ✅ Staging tests production architecture
- ✅ Fast rollbacks (deploy previous image tag)

---

## Architecture Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                     GitHub Repository                             │
│                                                                   │
│  develop branch              │          main branch (tags)       │
│       ↓                      │               ↓                   │
│  build-develop.yml           │       deploy-production.yml       │
│  (NEW workflow)              │       (FIXED workflow)            │
│       ↓                      │               ↓                   │
│  Build Docker image          │       Build Docker image          │
│       ↓                      │               ↓                   │
│  ghcr.io/.../:develop        │       ghcr.io/.../:v4.8.2         │
│       ↓                      │               ↓                   │
│  deploy-staging.yml          │       Manual approval             │
│  (UPDATED workflow)          │               ↓                   │
└───────┼──────────────────────┴───────────────┼──────────────────┘
        │                                      │
        ▼                                      ▼
┌──────────────────┐                  ┌──────────────────┐
│ Staging Server   │                  │ Production Server│
│ 45.93.138.193    │                  │ 72.60.17.138     │
├──────────────────┤                  ├──────────────────┤
│                  │                  │                  │
│ curl download    │                  │ curl download    │
│ ├─ docker-       │                  │ ├─ docker-       │
│ │  compose.yml   │                  │ │  compose.yml   │
│ └─ nginx config  │                  │ └─ nginx config  │
│                  │                  │                  │
│ docker pull      │                  │ docker pull      │
│ :develop         │                  │ :v4.8.2          │
│                  │                  │                  │
│ ✅ NO Git repo   │                  │ ✅ NO Git repo   │
│ ✅ NO builds     │                  │ ✅ NO builds     │
└──────────────────┘                  └──────────────────┘
```

---

## Quick Deployment Guide

### Deploy to Production (v4.8.2+)

```bash
# 1. Create release tag
./scripts/release.sh patch  # v4.8.1 → v4.8.2

# 2. GitHub Actions automatically:
#    - Builds Docker image
#    - Runs tests
#    - Pushes to GHCR
#    - Waits for manual approval
#    - Deploys to production (curl + docker pull)

# 3. Verify deployment
curl https://paradocks.pl/health
```

### Deploy to Staging (Auto)

```bash
# 1. Merge to develop
git checkout develop
git merge feature/my-feature
git push origin develop

# 2. GitHub Actions automatically:
#    - Builds Docker image (:develop tag)
#    - Runs tests
#    - Pushes to GHCR
#    - Deploys to staging (curl + docker pull)

# 3. Verify deployment
curl https://srv1203357.hstgr.cloud/health
```

---

## Staging Server Cleanup (REQUIRED - One-time)

**Status:** 📋 **MANUAL STEP REQUIRED**

Staging server currently has Git repository and uses local builds. Must migrate to Docker/curl-based deployment.

### Quick Steps

```bash
# 1. SSH to staging
ssh root@45.93.138.193

# 2. Backup .env and Git repo
cd /var/www/paradocks
cp .env /root/env-backup-$(date +%Y%m%d).env
cp -r .git /root/git-backup-$(date +%Y%m%d)

# 3. Stop containers
docker compose -f docker-compose.staging.yml down

# 4. Remove Git repo and application code
rm -rf .git app bootstrap config database lang public resources routes storage tests
rm -rf node_modules vendor
rm -f composer.json composer.lock package.json package-lock.json

# 5. Download fresh configs (replace $GITHUB_TOKEN)
export GITHUB_TOKEN="your_github_token_here"

curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
  -o docker-compose.staging.yml \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/develop/docker-compose.staging.yml"

mkdir -p docker/nginx
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
  -o docker/nginx/app.staging.conf \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/develop/docker/nginx/app.staging.conf"

# 6. Pull Docker image and start
echo "$GITHUB_TOKEN" | docker login ghcr.io -u patrykgielo --password-stdin
docker compose -f docker-compose.staging.yml pull
docker compose -f docker-compose.staging.yml up -d

# 7. Run migrations
docker compose -f docker-compose.staging.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.staging.yml exec -T app php artisan storage:link

# 8. Verify
curl https://srv1203357.hstgr.cloud/health
```

**Full Guide:** `docs/deployment/staging-server-cleanup.md`

---

## Rollback Procedures

### Production Rollback

```bash
# Deploy previous version (v4.8.1)
ssh root@72.60.17.138
cd /var/www/paradocks

# Change image tag in docker-compose.prod.yml
sed -i 's/:latest/:v4.8.1/g' docker-compose.prod.yml

# Pull and restart
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --force-recreate

# OR use release script
./scripts/release.sh rollback v4.8.1
```

### Staging Rollback

```bash
# Deploy specific commit SHA
ssh root@45.93.138.193
cd /var/www/paradocks

# Change to commit-specific tag
sed -i 's/:develop/:develop-abc1234/g' docker-compose.staging.yml

# Pull and restart
docker compose -f docker-compose.staging.yml pull
docker compose -f docker-compose.staging.yml up -d --force-recreate
```

---

## Verification Checklist

### After Production Deployment

- [ ] Health check returns 200: `curl https://paradocks.pl/health`
- [ ] All containers running: `ssh root@72.60.17.138 "docker compose -f docker-compose.prod.yml ps"`
- [ ] Correct image tag: `ssh root@72.60.17.138 "docker image ls | grep paradocks"`
- [ ] Storage uploads work (test in admin panel)
- [ ] Horizon dashboard accessible: `https://paradocks.pl/horizon`

### After Staging Deployment

- [ ] Health check returns 200: `curl https://srv1203357.hstgr.cloud/health`
- [ ] All containers running: `ssh root@45.93.138.193 "docker compose -f docker-compose.staging.yml ps"`
- [ ] Correct image tag: `:develop`
- [ ] Mailpit accessible: `http://srv1203357.hstgr.cloud:8025`
- [ ] No Git repository: `ssh root@45.93.138.193 "ls -la /var/www/paradocks/.git"` → should fail

---

## Key Benefits

### 1. Production = Staging Parity
- Both use GHCR pre-built images
- Same deployment workflow (curl + docker pull)
- Staging tests production architecture

### 2. Faster Deployments
- No local Docker builds (save 2-3 minutes)
- Pre-built images pulled from GHCR
- Only config files downloaded via curl

### 3. Simpler Servers
- No Git dependencies
- No source code on filesystem
- Smaller attack surface

### 4. Better Rollbacks
- Production: Deploy previous `:v4.8.x` tag (30 seconds)
- Staging: Deploy previous `:develop-$SHA` tag (30 seconds)

### 5. Cost Savings
- $0 additional cost (GitHub Actions free tier)
- No extra infrastructure needed

---

## Troubleshooting

### Issue: curl download fails with 401

**Symptom:** `curl: (22) The requested URL returned error: 401`

**Cause:** Missing or invalid GITHUB_TOKEN

**Solution:**
```bash
# Generate new GitHub PAT with read:packages + repo scope
# https://github.com/settings/tokens

# Test download
curl -fsSL -H "Authorization: token $NEW_TOKEN" \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/develop/docker-compose.staging.yml"
```

### Issue: Docker pull fails with authentication error

**Symptom:** `Error response from daemon: pull access denied`

**Solution:**
```bash
# Login to GHCR
echo "$GITHUB_TOKEN" | docker login ghcr.io -u patrykgielo --password-stdin

# Test pull
docker pull ghcr.io/patrykgielo/paradocks:develop
```

### Issue: Health check fails after deployment

**Symptom:** `curl https://paradocks.pl/health` returns 503 or 500

**Solution:**
```bash
# Check container logs
docker compose -f docker-compose.prod.yml logs app

# Check database connection
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker
>>> DB::connection()->getPdo();

# Check Redis connection
docker compose -f docker-compose.prod.yml exec -T redis redis-cli -a "$REDIS_PASSWORD" ping
```

---

## Next Steps

### Immediate (Required for v4.8.2 deployment)

1. ✅ **Review this summary** - Understand all changes
2. ✅ **Test locally** - Verify workflows work
3. ⬜ **Deploy v4.8.2** - Fix production deployment
4. ⬜ **Clean staging server** - Follow `staging-server-cleanup.md`
5. ⬜ **Test staging deployment** - Verify curl-based deployment works

### Future Improvements (Optional)

- [ ] Add Slack/Discord deployment notifications
- [ ] Implement blue-green deployment for zero downtime
- [ ] Add performance metrics tracking
- [ ] Create rollback automation script
- [ ] Add deployment status dashboard

---

## Related Documentation

- **ADR-010:** CI/CD Deployment Strategy (original Docker/GHCR decision)
- **ADR-018:** CI/CD Unification - Docker/curl-based (this change)
- **Staging Cleanup Guide:** `docs/deployment/staging-server-cleanup.md`
- **Git Workflow:** `docs/deployment/GIT_WORKFLOW.md`

---

## Support & Questions

If you encounter issues:

1. Check workflow logs in GitHub Actions
2. Check container logs: `docker compose logs -f app`
3. Review ADR-018 for detailed architecture explanation
4. Check `docs/deployment/staging-server-cleanup.md` for cleanup steps

---

**Last Updated:** 2026-01-08
**Status:** Ready for deployment
**Version:** v4.8.2
