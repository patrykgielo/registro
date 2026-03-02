# ADR-018: CI/CD Unification - Docker/curl-based Deployment Strategy

**Date:** 2026-01-08
**Status:** Accepted
**Context:** Production & Staging Deployment
**Supersedes:** Partial implementation from ADR-010

---

## Context and Problem Statement

### The Problem

During v4.8.1 deployment, production deployment failed because the workflow attempted `git fetch` on a server without a Git repository. This revealed architectural inconsistency:

**Production (72.60.17.138):**
- ✅ Uses GHCR pre-built images (`ghcr.io/patrykgielo/paradocks:v4.8.1`)
- ✅ No Git repository
- ❌ Workflow incorrectly added `git fetch` in v4.8.1 → **deployment failure**

**Staging (45.93.138.193):**
- ❌ Has Git repository
- ❌ Uses local Docker builds (`build: context: .`)
- ❌ Workflow uses `git fetch` + `git reset --hard`
- ❌ **NOT consistent with production**

### Why This Matters

1. **Testing doesn't match production:** Staging builds locally, production uses pre-built images
2. **Deployment failures:** Adding production-incompatible steps (like `git fetch`) breaks deployments
3. **Maintenance overhead:** Two different deployment strategies to maintain
4. **Rollback complexity:** Different rollback procedures for staging vs production
5. **Security surface:** Git repository on production servers is unnecessary attack surface

---

## Decision Drivers

### Technical Requirements

1. **Staging = Production parity:** Staging must test exact same Docker images that deploy to production
2. **No Git on servers:** Application code lives in Docker images, not Git repos
3. **Config flexibility:** docker-compose.yml and nginx configs downloaded via curl (can be updated without full rebuild)
4. **Fast rollbacks:** Deploy previous Docker image tag (30 seconds)
5. **Zero build time on servers:** No local `docker build`, only `docker pull`

### Business Requirements

1. **Deployment reliability:** Eliminate manual steps and inconsistencies
2. **Cost efficiency:** Continue using GitHub Actions free tier (2000 min/month)
3. **Developer experience:** Single deployment strategy to learn and maintain
4. **Audit trail:** All deployments tracked in GitHub Actions logs

---

## Decision Outcome

**Chosen Strategy:** **Docker/curl-based deployment for BOTH production AND staging**

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ GitHub Repository (patrykgielo/paradocks)                   │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  develop branch          │         main branch (tags)       │
│       ↓                  │              ↓                   │
│  build-develop.yml       │      deploy-production.yml       │
│       ↓                  │              ↓                   │
│  Build Docker image      │      Build Docker image          │
│       ↓                  │              ↓                   │
│  Push to GHCR            │      Push to GHCR                │
│    :develop tag          │        :v4.8.x + :latest         │
│       ↓                  │              ↓                   │
│  deploy-staging.yml      │      Manual approval gate        │
│       ↓                  │              ↓                   │
└───────┼──────────────────┴──────────────┼──────────────────┘
        │                                 │
        ▼                                 ▼
┌───────────────────┐           ┌────────────────────┐
│ Staging Server    │           │ Production Server  │
│ 45.93.138.193     │           │ 72.60.17.138       │
├───────────────────┤           ├────────────────────┤
│                   │           │                    │
│ 1. curl download  │           │ 1. curl download   │
│    - docker-      │           │    - docker-       │
│      compose.yml  │           │      compose.yml   │
│    - nginx config │           │    - nginx config  │
│                   │           │                    │
│ 2. docker login   │           │ 2. docker login    │
│    ghcr.io        │           │    ghcr.io         │
│                   │           │                    │
│ 3. docker pull    │           │ 3. docker pull     │
│    :develop       │           │    :v4.8.x         │
│                   │           │                    │
│ 4. docker         │           │ 4. docker          │
│    compose up -d  │           │    compose up -d   │
│                   │           │                    │
│ ✅ NO Git repo    │           │ ✅ NO Git repo     │
│ ✅ NO local build │           │ ✅ NO local build  │
└───────────────────┘           └────────────────────┘
```

---

## Implementation Details

### 1. Docker Image Strategy

#### Production Images
- **Tag format:** `ghcr.io/patrykgielo/paradocks:v4.8.1` (semantic version)
- **Additional tag:** `:latest` (always points to latest stable release)
- **Trigger:** Git tag push (`v*.*.*`)
- **Workflow:** `.github/workflows/deploy-production.yml`

#### Staging Images
- **Tag format:** `ghcr.io/patrykgielo/paradocks:develop` (rolling tag)
- **Additional tag:** `:develop-abc1234` (commit SHA for rollbacks)
- **Trigger:** Push to `develop` branch
- **Workflow:** `.github/workflows/build-develop.yml` (NEW)

### 2. Config File Download Strategy

Both environments download config files via curl during deployment:

```bash
# Download docker-compose.yml
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
  -o docker-compose.prod.yml \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/$VERSION/docker-compose.prod.yml"

# Download nginx config
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" \
  -o docker/nginx/app.prod.conf \
  "https://raw.githubusercontent.com/patrykgielo/paradocks/$VERSION/docker/nginx/app.prod.conf"
```

**Why curl instead of Git?**
- ✅ No Git dependencies on servers
- ✅ Fetches only needed files (not entire repo)
- ✅ Works with private repositories (GitHub token auth)
- ✅ Consistent with "Docker-first" architecture

### 3. Workflow Changes

#### Production Workflow (FIXED)

**Before (v4.8.1 - BROKEN):**
```yaml
- git fetch origin main          # ❌ FAILS - no Git repo!
- git reset --hard origin/main
- docker compose pull
```

**After (v4.8.2+):**
```yaml
- curl download docker-compose.prod.yml
- curl download nginx config
- docker login ghcr.io
- docker compose pull            # Uses image: ghcr.io/.../paradocks:v4.8.2
- docker compose up -d --force-recreate
```

#### Staging Workflow (NEW)

**Before (Git-based):**
```yaml
- git fetch origin develop       # Requires Git repo
- git reset --hard origin/develop
- docker compose up -d --build   # Local build from source
```

**After (Docker/curl-based):**
```yaml
- curl download docker-compose.staging.yml
- curl download nginx config
- docker login ghcr.io
- docker compose pull            # Uses image: ghcr.io/.../paradocks:develop
- docker compose up -d --force-recreate
```

### 4. Server Directory Structure

**Before (Git-based staging):**
```
/var/www/paradocks/
├── .git/                    # Git repository (large, unnecessary)
├── app/                     # Laravel source code
├── vendor/                  # Composer dependencies (built locally)
├── node_modules/            # NPM dependencies (built locally)
├── docker-compose.staging.yml
├── docker/nginx/
└── .env
```

**After (Docker/curl-based):**
```
/var/www/paradocks/
├── docker-compose.staging.yml  # Downloaded via curl
├── docker/nginx/
│   └── app.staging.conf        # Downloaded via curl
└── .env                        # Secrets (NOT in Git)

# Application code is INSIDE Docker image, not on filesystem
```

**Benefits:**
- ⚡ **90% smaller directory** (no Git history, no source code)
- 🔒 **Reduced attack surface** (no .git directory to expose)
- ⚙️ **Simpler maintenance** (only configs on server, code in container)

---

## Deployment Workflows

### Production Deployment (v4.8.2+)

```bash
# Developer creates release
./scripts/release.sh minor  # v4.8.1 → v4.8.2

# GitHub Actions automatically:
# 1. Build Docker image
# 2. Run tests (Pint + PHPUnit)
# 3. Push to GHCR as :v4.8.2 + :latest
# 4. Wait for manual approval
# 5. SSH to production:
#    - curl download docker-compose.prod.yml
#    - curl download nginx config
#    - docker login ghcr.io
#    - docker pull ghcr.io/.../paradocks:v4.8.2
#    - docker compose up -d --force-recreate
# 6. Run migrations
# 7. Clear caches
# 8. Health check
```

**Deployment time:** ~5-7 minutes (3-4 min build, 2-3 min deploy)

### Staging Deployment (Auto on develop push)

```bash
# Developer merges to develop
git push origin develop

# GitHub Actions automatically:
# 1. Build Docker image
# 2. Run tests (Pint + PHPUnit)
# 3. Push to GHCR as :develop + :develop-$SHA
# 4. SSH to staging:
#    - curl download docker-compose.staging.yml
#    - curl download nginx config
#    - docker login ghcr.io
#    - docker pull ghcr.io/.../paradocks:develop
#    - docker compose up -d --force-recreate
# 5. Run migrations + seeders
# 6. Clear caches
# 7. Health check
```

**Deployment time:** ~4-5 minutes

---

## Consequences

### Positive Consequences

1. **✅ Production = Staging parity**
   - Both use GHCR pre-built images
   - Same deployment workflow (curl + docker pull)
   - Staging tests exact production architecture

2. **✅ Faster deployments**
   - No local Docker builds (save 2-3 minutes)
   - Pre-built images pulled from GHCR
   - Only config files downloaded via curl

3. **✅ Simpler servers**
   - No Git dependencies
   - No source code on filesystem
   - Smaller attack surface (no .git to expose)

4. **✅ Better rollbacks**
   - Production: Deploy previous `:v4.8.1` tag
   - Staging: Deploy previous `:develop-$SHA` tag
   - Rollback time: ~30 seconds

5. **✅ Consistent testing**
   - Staging tests same Docker image build process as production
   - CI/CD tests run on same MySQL 8.0 + Redis 7.2 stack
   - No "works in staging but fails in production" issues

### Negative Consequences

1. **⚠️ Staging server cleanup required**
   - Manual cleanup of Git repository
   - One-time migration effort (~30 minutes)
   - Risk of data loss if .env not backed up properly

2. **⚠️ Two Docker image tags to maintain**
   - `:develop` for staging (rolling tag)
   - `:v4.8.x` for production (versioned tag)
   - Requires new build workflow for develop branch

3. **⚠️ GHCR storage usage**
   - More images stored in GHCR (develop + production tags)
   - Still within free tier limits (GitHub private repos)

### Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Cleanup data loss | HIGH | Backup .env file before cleanup (documented in guide) |
| GHCR rate limits | MEDIUM | Free tier: unlimited storage for private repos |
| Staging downtime | LOW | Cleanup during off-hours, ~30 min estimated downtime |
| Config download fails | LOW | Retry logic in workflows (3 attempts with backoff) |

---

## Migration Plan

### Phase 1: Fix Production (IMMEDIATE - v4.8.2)

- [x] Remove `git fetch` / `git reset --hard` from production workflow
- [x] Add curl downloads for docker-compose.prod.yml
- [x] Add curl downloads for nginx config
- [x] Keep all v4.8.1 improvements (retry, health check, concurrency)
- [x] Deploy hotfix v4.8.2 to restore production deployments

### Phase 2: Create Develop Build Workflow (COMPLETED)

- [x] Create `.github/workflows/build-develop.yml`
- [x] Build image from develop branch
- [x] Push to GHCR as `:develop` + `:develop-$SHA`
- [x] Trigger on push to develop

### Phase 3: Update Staging Workflow (COMPLETED)

- [x] Update `.github/workflows/deploy-staging.yml`
- [x] Remove `git fetch` / `git reset --hard`
- [x] Add curl downloads for docker-compose.staging.yml
- [x] Add curl downloads for nginx config
- [x] Pull `:develop` image from GHCR

### Phase 4: Update Staging Docker Compose (COMPLETED)

- [x] Update `docker-compose.staging.yml`
- [x] Change `build: context: .` to `image: ghcr.io/.../paradocks:develop`
- [x] Add storage volumes (match production)
- [x] Update all services to use GHCR images

### Phase 5: Staging Server Cleanup (MANUAL - NEXT)

- [ ] SSH to staging server
- [ ] Backup .env and Git repo
- [ ] Remove Git repository
- [ ] Remove application code
- [ ] Download fresh configs via curl
- [ ] Pull `:develop` image
- [ ] Test deployment

**Guide:** `docs/deployment/staging-server-cleanup.md`

---

## Testing & Validation

### Pre-Deployment Tests (CI/CD)

✅ **Both workflows run identical tests:**
- Laravel Pint (code style)
- PHPUnit Feature tests
- MySQL 8.0 + Redis 7.2 services
- Build frontend assets
- Build Docker image

### Post-Deployment Verification

**Production:**
```bash
# Health check
curl https://paradocks.pl/health

# Container status
ssh root@72.60.17.138 "docker compose -f docker-compose.prod.yml ps"

# Image verification
ssh root@72.60.17.138 "docker image ls | grep paradocks"
# Should show: ghcr.io/patrykgielo/paradocks:v4.8.2
```

**Staging:**
```bash
# Health check
curl https://srv1203357.hstgr.cloud/health

# Container status
ssh root@45.93.138.193 "docker compose -f docker-compose.staging.yml ps"

# Image verification
ssh root@45.93.138.193 "docker image ls | grep paradocks"
# Should show: ghcr.io/patrykgielo/paradocks:develop
```

---

## Rollback Procedures

### Production Rollback

```bash
# From local machine - deploy previous version
./scripts/release.sh rollback v4.8.1

# OR manually via SSH
ssh root@72.60.17.138
cd /var/www/paradocks
docker compose -f docker-compose.prod.yml pull  # Use cached :v4.8.1
docker compose -f docker-compose.prod.yml up -d --force-recreate
```

**Rollback time:** ~30 seconds

### Staging Rollback

```bash
# SSH to staging
ssh root@45.93.138.193
cd /var/www/paradocks

# Edit docker-compose.staging.yml - change image tag
sed -i 's/:develop/:develop-abc1234/g' docker-compose.staging.yml

# Pull specific commit SHA image
docker compose -f docker-compose.staging.yml pull
docker compose -f docker-compose.staging.yml up -d --force-recreate
```

**Rollback time:** ~1 minute

---

## Documentation Updates

### New Documentation

- [x] `docs/deployment/ADR-018-cicd-unification-docker-curl.md` (this file)
- [x] `docs/deployment/staging-server-cleanup.md` (cleanup guide)
- [x] `.github/workflows/build-develop.yml` (new workflow)

### Updated Documentation

- [x] `.github/workflows/deploy-production.yml` (remove git, add curl)
- [x] `.github/workflows/deploy-staging.yml` (remove git, add curl)
- [x] `docker-compose.staging.yml` (use GHCR images)

### To Be Updated (After Migration)

- [ ] `docs/deployment/GIT_WORKFLOW.md` (document new staging deployment)
- [ ] `README.md` (update deployment instructions)
- [ ] `docs/deployment/runbooks/ci-cd-deployment.md` (staging runbook)

---

## Related ADRs

- **ADR-010:** CI/CD Deployment Strategy (original Docker/GHCR decision)
- **ADR-013:** Docker User Model (laravel:laravel UID 1000)
- **ADR-015:** Production Optimization Quick Wins (OPcache, caching)
- **ADR-017:** Emergency Hotfix Process (when to bypass workflow)

---

## Review & Maintenance

**Review Schedule:** Quarterly (April 2026, July 2026, October 2026)

**Success Metrics:**
- ✅ Zero "staging works but production fails" incidents
- ✅ Deployment time < 5 minutes (staging and production)
- ✅ Rollback time < 1 minute
- ✅ No manual server maintenance required

**Next Review Date:** April 2026

---

## Issues Found During Migration (2026-01-11)

### Issue 1: Staging Deploy Race Condition

**Problem:** `deploy-staging.yml` and `build-develop.yml` both triggered on `push to develop`, causing deploy to fail because image wasn't built yet.

**Solution:** Changed `deploy-staging.yml` trigger from `push` to `workflow_run`:
```yaml
on:
  workflow_run:
    workflows: ["Build Develop Image"]
    types: [completed]
    branches: [develop]
```

**Commit:** `9cb9e91` (PR #55)

### Issue 2: Entrypoint Hardcoded Database Hostname

**Problem:** `docker/entrypoint.sh` had hardcoded `paradocks-mysql` hostname. This worked for production but failed for staging where container is named `paradocks-staging-mysql`.

**Solution:** Changed to use `$DB_HOST` environment variable:
```bash
DB_HOST="${DB_HOST:-mysql}"
while ! nc -z "$DB_HOST" "$DB_PORT"; do
```

**Commit:** `0c97357`

### Issue 3: Missing app_public Volume in Staging

**Problem:** `docker-compose.staging.yml` was missing shared `app_public` volume between app and nginx containers. Nginx returned 404 because it couldn't access `/var/www/public`.

**Solution:** Added `app_public` volume to both app and nginx services:
```yaml
app:
  volumes:
    - app_public:/var/www/public
nginx:
  volumes:
    - app_public:/var/www/public:ro
```

**Commit:** `0c97357`

---

**Last Updated:** 2026-01-11
**Status:** Accepted
**Implementation Status:** 95% complete (staging fully operational, pending server cleanup)
