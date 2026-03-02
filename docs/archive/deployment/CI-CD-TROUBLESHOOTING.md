# CI/CD Troubleshooting Guide

Quick reference for common CI/CD issues and their solutions.

## Common Errors

### 1. `curl: (23) Failure writing output to destination`

**Symptom:** Deploy job fails when downloading config files to server.

**Cause:** File ownership mismatch. Files are owned by `root` but CI uses `deploy` user.

**Diagnosis:**
```bash
ssh deploy@server "ls -la /var/www/paradocks/docker/nginx/"
# Look for files owned by root:root instead of deploy:deploy
```

**Fix:**
```bash
ssh root@server "chown deploy:deploy /var/www/paradocks/docker/nginx/app.*.conf"
```

**Prevention:** Never SSH as root for app operations. See [ADR-021](ADR-021-staging-file-permissions.md).

---

### 2. `This request has been automatically failed because it uses a deprecated version of actions/cache`

**Symptom:** Job fails at "Set up job" step.

**Cause:** GitHub Actions deprecated old action versions.

**Diagnosis:** Check error message for which action SHA is deprecated.

**Fix:** Update to latest SHA:
```yaml
# Find latest: https://github.com/actions/cache/releases
uses: actions/cache@5a3ec84eff668545956fd18022155c47e93e2684  # v4.2.3
```

**Prevention:** Periodically update action SHAs in workflows.

---

### 3. `Permission denied (publickey)`

**Symptom:** Deploy job can't SSH to server.

**Cause:** SSH key missing or wrong key for environment.

**Diagnosis:**
```bash
# Test staging
ssh -i ~/.ssh/id_ed25519_staging_deploy deploy@45.93.138.193

# Test production
ssh -i ~/.ssh/id_rsa_github deploy@72.60.17.138
```

**Fix:**
1. Check GitHub Secrets: `STAGING_VPS_SSH_KEY` / `PRODUCTION_VPS_SSH_KEY`
2. Verify key is in server's `~/.ssh/authorized_keys`

---

### 4. `Health check failed after 3 minutes`

**Symptom:** Deploy completes but health check fails.

**Cause:** Application not responding on `/health` endpoint.

**Diagnosis:**
```bash
# Check container status
ssh deploy@server "docker compose -f docker-compose.staging.yml ps"

# Check app logs
ssh deploy@server "docker compose -f docker-compose.staging.yml logs app --tail=50"

# Check nginx logs
ssh deploy@server "docker compose -f docker-compose.staging.yml logs nginx --tail=50"
```

**Common causes:**
- MySQL not ready (increase wait time)
- Redis password mismatch
- PHP OPcache stale (restart app container)
- Missing .env variables

---

### 5. `REDIS_PASSWORD is empty`

**Symptom:** Application returns 500 errors after deploy.

**Cause:** Environment variable not set or CI overwrote .env.

**Diagnosis:**
```bash
ssh deploy@server "cd /var/www/paradocks && source .env && echo \$REDIS_PASSWORD"
```

**Fix:** See [ADR-019](ADR-019-env-file-management.md) - never modify .env in CI.

---

## Workflow Structure

### Staging (ci-staging.yml)
```
push to develop
    ↓
test (PHPUnit, Pint) → build (Docker) → deploy (SSH)
    ~3 min              ~2 min           ~2 min
```

### Production (deploy-production.yml)
```
git tag v*.*.*
    ↓
test → build → approval gate (5 min + reviewer) → deploy
```

## Useful Commands

```bash
# Check workflow runs
gh run list --branch develop --limit 5

# Watch running workflow
gh run watch <run-id>

# View failed logs
gh run view <run-id> --log-failed

# Re-run failed workflow
gh workflow run "CI/CD Staging" --ref develop

# Check job statuses
gh run view <run-id> --json jobs --jq '.jobs[] | "\(.name): \(.conclusion)"'
```

## Related Documentation

- [ADR-019: Environment File Management](ADR-019-env-file-management.md)
- [ADR-020: Staging Deploy User Migration](ADR-020-staging-deploy-user-migration.md)
- [ADR-021: Staging File Permissions](ADR-021-staging-file-permissions.md)
- [Git Workflow](GIT_WORKFLOW.md)
