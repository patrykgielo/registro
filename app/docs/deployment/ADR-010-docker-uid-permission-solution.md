# ADR-010: Docker UID Permission Solution

**Status:** Accepted
**Date:** 2025-11-29
**Decision Makers:** Development Team
**Related Issues:** GitHub Actions deployment failures, storage permission denied errors

## Context

Production VPS deployment via GitHub Actions was failing with permission errors:

```
ERROR  Failed to enter maintenance mode: file_put_contents(storage/framework/views/xxx.php): Permission denied
```

### Root Cause Analysis

**Problem 1: UID Mismatch**
- Docker container runs as `laravel` user with uid=1000 (hardcoded in Dockerfile)
- VPS storage files owned by `deploy` user with uid=1002
- Container cannot write to files owned by different UID

**Problem 2: Docker Layer Cache**
- Initial fix added `ARG USER_ID` to Dockerfile and passed `--build-arg USER_ID=1002`
- Build appeared successful but container still had uid=1000
- **Root cause**: Docker cached the `RUN useradd -u ${USER_ID}` layer from previous build
- Even though build-arg changed, Docker reused cached layer (optimization)

**Problem 3: Silent Failures**
- No verification that build actually applied new UID
- Deployments proceeded with wrong UID, failing at runtime
- No clear error messages pointing to cache issue

### Investigation Timeline

1. **Nov 29, 2025 - Initial Issue**: Permission denied on maintenance mode
2. **First Fix Attempt**: Added dynamic UID matching (commit b195dec)
   - Workflow detects VPS UID
   - Passes to Docker build via `--build-arg`
   - **Result**: Still failed with same error
3. **Root Cause Discovery**: Docker cache was reusing old layers
4. **Final Solution**: Added `--no-cache` flag + verification (commit 8e311e8)

## Decision

Implement **Docker UID Matching with Cache Invalidation and Verification**:

### 1. UID Detection with Fallback Chain

```yaml
# .github/workflows/deploy-production.yml
VPS_UID=$(ssh "stat -c '%u' /var/www/registro 2>/dev/null || stat -c '%u' /var/www 2>/dev/null || echo '1000'")
```

**Rationale:**
- Primary: Check project directory (most reliable)
- Fallback 1: Check parent directory (/var/www)
- Fallback 2: Safe default (1000)
- Reject root UID (0) - never correct for application

### 2. Force Docker Rebuild with --no-cache

```yaml
docker compose build --no-cache --build-arg USER_ID=${DETECTED_UID} app
```

**Rationale:**
- Bypasses Docker layer cache entirely
- Forces fresh execution of `RUN useradd -u ${USER_ID}`
- **Trade-off**: Build time increases by 15-20 seconds (acceptable)
- **Benefit**: Guarantees correct UID is applied

### 3. Build Verification Step

```yaml
# After build completes
BUILT_UID=$(docker compose run --rm app id -u laravel)
if [ "$BUILT_UID" != "$DETECTED_UID" ]; then
  exit 1  # Fail deployment
fi
```

**Rationale:**
- Creates temporary container from built image
- Confirms UID matches expected value
- Fails fast before deployment proceeds
- Clear error message for debugging

## Alternatives Considered

### Option A: Host-Level Permission Fix (Rejected)

```bash
# One-time on VPS
sudo chown -R 1000:1000 /var/www/registro
```

**Pros:**
- Simplest solution
- No build-time complexity
- Fast deployment

**Cons:**
- Only works if VPS user is exactly 1000:1000
- Not portable to different VPS environments
- Requires manual host setup
- If directories recreated, permissions break again

**Decision:** Rejected - not production-grade, not portable

### Option B: Container Entrypoint Permission Fix (Rejected)

```dockerfile
ENTRYPOINT ["fix-permissions.sh"]
CMD ["php-fpm"]
```

**Pros:**
- Container self-heals on startup
- Works with any host UID
- No build-time complexity

**Cons:**
- Startup cost (chown on every container start)
- Requires container privilege escalation
- Multiple containers (app, queue, horizon) each run chown
- Security implication: container needs elevated permissions

**Decision:** Rejected - startup overhead, security concerns

### Option C: Multi-Tag Image Registry (Rejected)

Build separate images for each UID, push to registry with tags:

```
ghcr.io/user/app:v1.0.0-uid1000
ghcr.io/user/app:v1.0.0-uid1002
```

**Pros:**
- Images pre-built with correct UID
- Fast deployment (just pull)
- No cache issues

**Cons:**
- Complex tagging strategy
- Multiple images to manage
- Still need to detect UID before deployment
- Doesn't solve the fundamental problem

**Decision:** Rejected - over-engineered, doesn't simplify deployment

## Consequences

### Positive

- ✅ **Reliability**: Deployment succeeds with correct permissions
- ✅ **Portability**: Works on any VPS with any UID
- ✅ **Verification**: Catches silent failures before deployment
- ✅ **Clarity**: Clear error messages guide troubleshooting
- ✅ **Production-grade**: Follows Docker best practices

### Negative

- ❌ **Build Time**: +15-20 seconds per deployment (acceptable trade-off)
- ❌ **Complexity**: 3 workflow steps instead of 1
- ❌ **Verification Cost**: +5 seconds for temp container check

### Trade-offs Accepted

- **Build time vs Correctness**: Slower build (30s total) for guaranteed correct UID
- **Complexity vs Reliability**: More workflow steps for better error detection
- **Storage vs Speed**: No layer cache reuse (more disk I/O) for correctness

## Implementation

### Files Modified

1. **`.github/workflows/deploy-production.yml`**
   - Lines 260-278: Improved UID detection with fallback chain
   - Line 331: Added `--no-cache` flag to docker compose build
   - Lines 347-368: New verification step

2. **`DEPLOYMENT.md`**
   - Added troubleshooting section for permission errors
   - Documented manual fix procedure
   - Explained why `--no-cache` is required

3. **`CLAUDE.md`**
   - Added troubleshooting entry
   - Cross-referenced DEPLOYMENT.md
   - Documented local development fix

### Verification Process

**Pre-Deployment:**
1. Detect VPS UID: `stat -c '%u' /var/www/registro`
2. Validate: Reject UID=0 (root), log detected value
3. Build with `--no-cache --build-arg USER_ID=X`

**Post-Build:**
1. Run temp container: `docker compose run --rm app id -u laravel`
2. Compare: BUILT_UID == DETECTED_UID
3. Fail if mismatch, log clear error

**Post-Deployment:**
1. Enable maintenance mode (write test)
2. Run migrations (write test)
3. Application health check

## Success Criteria

- [x] Detect UID=1002 from VPS
- [x] Build with --no-cache (no layer reuse)
- [x] Verify container has uid=1002
- [x] Enable maintenance mode without permission errors
- [x] Deploy successfully
- [x] Application accessible at production URL

## Monitoring

**Metrics to Track:**
- UID detection success rate
- Build time with/without cache
- Verification success rate
- Permission error frequency

**Alerting:**
- Deployment failure at verification step
- UID detection fallback to default (1000)
- Root UID detected (should never happen)

## References

- **Commit**: 8e311e8 - "fix(ci): Force Docker rebuild and add verification"
- **Plan**: `/home/patrick/.claude/plans/glimmering-mapping-galaxy.md`
- **Docker Docs**: [ARG and build-time variables](https://docs.docker.com/engine/reference/builder/#arg)
- **Best Practice**: [Running Docker containers as non-root](https://docs.docker.com/develop/develop-images/dockerfile_best-practices/#user)

## Future Considerations

### If Build Time Becomes Critical

**Option**: Selective cache invalidation
```yaml
# Only invalidate cache if UID changed
CACHE_KEY="uid-${USER_ID}"
PREVIOUS_KEY=$(cat .docker/cache-key 2>/dev/null || echo "NONE")

if [ "$CACHE_KEY" != "$PREVIOUS_KEY" ]; then
  BUILD_FLAGS="--no-cache"
else
  BUILD_FLAGS=""
fi
```

**Trade-off**: More complex workflow, same reliability

### If Multiple VPS Environments

**Option**: Store UID in VPS-specific config
```bash
# .env.vps-production
DOCKER_USER_ID=1002
DOCKER_GROUP_ID=1002
```

**Trade-off**: Manual configuration per environment

## Approval

- [x] Tested in production VPS deployment
- [x] Documentation updated (DEPLOYMENT.md, CLAUDE.md)
- [x] Plan reviewed and approved
- [x] Implemented and committed (8e311e8)

**Approved by:** Development Team
**Date:** 2025-11-29
