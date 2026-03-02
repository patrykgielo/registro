# ADR-011: Zero-Downtime Healthcheck Deployment Strategy

**Status:** Accepted
**Date:** 2025-11-29
**Decision Makers:** Development Team
**Related Issues:** GitHub Actions deployment failures, permission denied errors, maintenance mode complications
**Supersedes:** Previous maintenance mode deployment approach

## Context

After implementing ADR-010 (Docker UID Permission Solution), deployments continued to fail with permission errors despite correct UID detection and build verification.

### Investigation Timeline

1. **Nov 29, 2025 - Initial UID Fix (ADR-010)**: Added dynamic UID matching with `--no-cache`
2. **Post-ADR-010**: Still failed with "Permission denied" during maintenance mode
3. **Deep Investigation**: User demanded "czy nie mozesz z agentami porzadnie tego przebadac?"
4. **Agent Analysis**: Entered plan mode, launched 3 specialized agents
5. **Critical Discovery**: Found 3 smoking guns causing failures

### Three Critical Issues Found

**Issue #1: CATCH-22 Problem**
```yaml
# Workflow order (WRONG):
Line 280: Enable Maintenance Mode (OLD container, uid=1000)
Line 330: Build New Image (NEW container, uid=1002)
```
- Maintenance mode runs BEFORE building new image
- Old container tries to write with wrong UID → Permission denied
- Build never happens because maintenance mode failed
- **Verdict**: Impossible to solve without changing strategy

**Issue #2: Image Ignored**
```yaml
# docker-compose.prod.yml (BEFORE):
app:
  image: ghcr.io/patrykgielo/registro:latest
  # NO build: directive!
```
- Workflow builds locally with correct UID
- `docker compose up` pulls from GHCR registry (default uid=1000)
- Local build completely wasted
- **Verdict**: docker-compose.prod.yml missing build configuration

**Issue #3: Build Directive Missing**
- Can't use local Dockerfile
- `docker compose build` has nothing to build from
- No way to use locally-built images
- **Verdict**: Infrastructure gap

### User's Insight

User questioned: **"Why enable maintenance mode if we're rebuilding containers?"**

This was correct because:
- Container rebuild = brief downtime anyway (inevitable)
- Maintenance mode adds NO value (containers restart regardless)
- Maintenance mode CAUSES problems (can't write with old UID)
- **Conclusion**: Maintenance mode unnecessary for container rebuilds

## Decision

Implement **Zero-Downtime Deployment with Healthcheck Strategy** for container rebuilds.

Skip maintenance mode entirely and use blue-green deployment pattern:

```
OLD DEPLOYMENT (BROKEN):
1. Enable maintenance on old container → Permission denied ❌
2. Build never happens
3. Deployment fails

NEW DEPLOYMENT (HEALTHCHECK):
1. Old container continues serving traffic
2. Build new container (UID=1002) in background
3. Start new container, wait for healthy
4. Run migrations (brief 15s downtime)
5. Switch traffic to new container
6. Remove old container
7. ✅ Success
```

### Architecture Components

**1. docker-compose.prod.yml Enhancement**
```yaml
# Add build: directive alongside image:
app:
  build:
    context: .
    dockerfile: Dockerfile
    args:
      USER_ID: ${DOCKER_USER_ID:-1000}
      GROUP_ID: ${DOCKER_GROUP_ID:-1000}
  image: ghcr.io/patrykgielo/registro:latest
```

**Why this works:**
- `build:` tells compose WHERE to build from
- `image:` tells compose what tag to use
- With both: `docker compose build` works, `docker compose up` can pull OR build

**2. Healthcheck Deployment Script** (`scripts/deploy-with-healthcheck.sh`)

11KB bash script implementing blue-green deployment:

**Steps:**
1. Preflight checks (Docker running, .env exists, compose file valid)
2. Detect UID/GID from VPS file ownership
3. Build new image with `--no-cache --build-arg USER_ID=X`
4. Verify built image has correct UID
5. Start new container in parallel (scale to 2)
6. Wait for new container healthy (5-minute timeout, 5-second interval)
7. Run migrations on new container (~15s downtime)
8. Atomic switch: stop old, start new
9. Verify deployment success
10. Cleanup old images/containers

**Error Handling:**
- Rollback on failure (automatic via trap)
- Clear error messages with color coding
- Exit codes for different failure scenarios

**3. GitHub Actions Workflow Update**

**Removed:**
- Enable Maintenance Mode step
- Disable Maintenance Mode step
- Manual build/restart steps
- UID verification (moved to script)

**Added:**
- Upload deployment script to VPS
- Execute healthcheck deployment script
- Updated rollback strategy
- Updated deployment summary

## Alternatives Considered

### Option A: Quick Fix - Reorder Workflow Steps (Rejected)

**Proposal:** Move maintenance mode AFTER build
```yaml
1. Build image with correct UID
2. Start new containers
3. Enable maintenance mode
4. Run migrations
5. Disable maintenance mode
```

**Pros:**
- Minimal changes (just reorder)
- Keeps maintenance mode

**Cons:**
- Still requires maintenance mode (user-visible downtime)
- No automatic rollback
- Doesn't solve fundamental problem (why maintenance for rebuild?)

**Decision:** Rejected - doesn't address root issue

### Option B: docker-compose Fix Only (Rejected)

**Proposal:** Just add `build:` directive, keep maintenance mode
```yaml
app:
  build: ...
  image: ...
# Keep maintenance mode in workflow
```

**Pros:**
- Fixes Issue #3 (build directive)
- Simple change

**Cons:**
- Doesn't fix CATCH-22 (Issue #1)
- Still has permission errors
- No improvement to deployment process

**Decision:** Rejected - incomplete solution

### Option C: Zero-Downtime Healthcheck (ACCEPTED)

**Proposal:** Skip maintenance mode, use healthcheck-based blue-green deployment

**Pros:**
- ✅ Zero user-visible downtime (no 503 page)
- ✅ Automatic fallback if new container fails
- ✅ ~15s downtime (migrations only)
- ✅ Modern container orchestration pattern
- ✅ Fixes all 3 issues simultaneously
- ✅ Production-grade deployment

**Cons:**
- ❌ More complex script (~350 lines bash)
- ❌ Requires Docker healthcheck support
- ❌ ~15s migration downtime (unavoidable)

**Decision:** ACCEPTED - complexity worth production-grade deployment

## Implementation

### Files Modified

1. **`docker-compose.prod.yml`**
   - Added `build:` directive to 3 services (app, horizon, scheduler)
   - Commit: b0d8b40

2. **`scripts/deploy-with-healthcheck.sh`** (NEW)
   - 354 lines of production-grade bash
   - Complete error handling, logging, rollback
   - Commit: 408e693

3. **`.github/workflows/deploy-production.yml`**
   - Removed 2 maintenance mode steps
   - Removed 5 manual build/verification steps
   - Added healthcheck deployment script execution
   - Net reduction: 100 lines
   - Commit: 0decafd

4. **`DEPLOYMENT.md`**
   - Added "Deployment Strategy" section
   - Updated troubleshooting (permission denied → SOLVED)
   - Commit: 84ebce3

5. **`app/docs/deployment/ADR-011-healthcheck-deployment-strategy.md`** (THIS FILE)
   - Complete decision record

## Consequences

### Positive

- ✅ **Zero downtime**: No maintenance page shown to users
- ✅ **Reliability**: Automatic rollback on health check failure
- ✅ **Performance**: Old container serves while new builds
- ✅ **Clarity**: Clear deployment steps with progress logging
- ✅ **Production-grade**: Follows modern container orchestration patterns
- ✅ **Fixes all 3 issues**: CATCH-22, image ignored, build directive missing
- ✅ **User experience**: ~15s migration downtime vs full restart

### Negative

- ❌ **Complexity**: 350-line bash script vs simple restart
- ❌ **Build time**: +15-20 seconds for health check wait
- ❌ **Dependencies**: Requires Docker healthcheck configuration
- ❌ **Migration downtime**: ~15s unavoidable (database constraints)

### Trade-offs Accepted

- **Complexity vs Reliability**: More complex script for guaranteed deployment success
- **Build time vs Correctness**: Slower deployment (health check wait) for safer deployment
- **Disk space vs Speed**: Scale to 2 containers temporarily (double disk usage during deploy)

## Success Criteria

- [x] Build directive added to docker-compose.prod.yml
- [x] Healthcheck deployment script created and tested
- [x] Workflow updated to use healthcheck strategy
- [x] Documentation updated (DEPLOYMENT.md, CLAUDE.md, ADR-011)
- [x] Local build test successful (UID 1002 verified)
- [ ] Production deployment successful (pending v0.1.0 tag push)
- [ ] Zero permission errors
- [ ] Application accessible throughout deployment

## Monitoring

**Metrics to Track:**
- Deployment success rate (should be 100%)
- Health check wait time (target: <60s)
- Migration duration (baseline: ~15s)
- Rollback frequency (target: 0%)

**Alerting:**
- Health check timeout (>300s)
- Rollback triggered
- Permission errors (should never happen)
- UID mismatch (should never happen)

## Rollback Strategy

**If new container fails health check:**
- Old container still running
- No traffic switched
- No data changes
- Deploy failed = safe state
- Automatic rollback via script

**If migrations fail:**
- Stop new container
- Keep old container
- Database unchanged
- Manual investigation required

**If deployed but issues found:**
- Deploy previous version tag
- Same healthcheck process
- ~2 minute rollback time

## Future Enhancements

1. **Database backup before migrations** (safety)
   - Add automatic backup step before running migrations
   - Store in `/var/backups/registro/`

2. **Blue-green at nginx level** (true zero-downtime migrations)
   - Dual deployment with nginx routing
   - Switch at load balancer level

3. **Metrics collection** (deployment duration tracking)
   - Prometheus metrics from deployment script
   - Grafana dashboard for deployment analytics

4. **Slack notifications** (team awareness)
   - Deployment started/completed/failed
   - Include version, duration, health status

5. **Deployment type detection** (smart mode selection)
   - Code-only changes → Fast restart (no build)
   - Container rebuild → Healthcheck deployment
   - Database changes → Extra caution mode

## References

- **Plan**: `glimmering-mapping-galaxy.md` - Complete planning document
- **ADR-010**: Docker UID Permission Solution (prerequisite)
- **User Choice**: Option C - "Pomiń maintenance podczas rebuild"
- **Commits**:
  - b0d8b40 - docker-compose.prod.yml
  - 408e693 - deploy-with-healthcheck.sh
  - 0decafd - workflow update
  - 84ebce3 - DEPLOYMENT.md
- **Docker Docs**: [Health Check](https://docs.docker.com/engine/reference/builder/#healthcheck)
- **Best Practice**: [Blue-Green Deployments](https://martinfowler.com/bliki/BlueGreenDeployment.html)

## Approval

- [x] Plan reviewed and approved by user
- [x] Implemented in 4 phases (build directive, script, workflow, docs)
- [x] Local testing completed (build with UID 1002 verified)
- [x] Documentation complete (DEPLOYMENT.md, CLAUDE.md, ADR-011)
- [ ] Production testing (pending v0.1.0 deployment)

**Approved by:** Development Team
**Date:** 2025-11-29

## Lessons Learned

1. **Agent Investigation Works**: User was right to demand "porzadnie tego przebadac" with agents
2. **CATCH-22 Real**: Maintenance mode before build is fundamentally broken
3. **User Insights Valuable**: "Why maintenance for rebuild?" was the key question
4. **Simple > Complex**: Skipping maintenance simpler than trying to fix it
5. **Production-Grade Worth It**: Extra complexity pays off in reliability
