# ADR-019: Environment File Management Policy

**Status:** Accepted
**Date:** 2026-01-07
**Deciders:** Development Team
**Related:** ADR-016 (Domain Migration), ADR-017 (Emergency Hotfix)

## Context

On 2026-01-07, a production deployment incident occurred that caused Redis to crash and resulted in 500 errors across the application.

### Incident Timeline

1. **Deployment initiated** on production (72.60.17.138)
2. **GitHub workflow** attempted to download `.env.production` from repository
3. **File not found (404)** - `.env.production` doesn't exist in repo (correctly excluded by .gitignore)
4. **Workflow created empty `.env`** or overwrote existing with defaults
5. **`REDIS_PASSWORD` became empty** → Redis authentication failed
6. **Redis crash** → Application 500 errors
7. **Manual recovery** required to restore `.env` and restart services

### Root Cause Analysis

**Primary Cause:**
Deployment workflow (`deploy-production.yml`) contained steps to download environment files from GitHub repository:

```yaml
# BAD PATTERN - This was in the workflow
- name: Download production environment
  run: |
    curl -H "Authorization: token ${{ secrets.GITHUB_TOKEN }}" \
         -o .env.production \
         https://raw.githubusercontent.com/org/repo/main/.env.production
```

**Why This Fails:**
- `.env` files are (correctly) excluded from version control via `.gitignore`
- GitHub returns 404 for non-existent files
- Workflow continues despite download failure
- Either creates empty `.env` or overwrites with `.env.example`
- Critical secrets like `REDIS_PASSWORD` become empty or default values

**Secondary Issues:**
1. No validation of `.env` integrity before deploying
2. No pre-deployment check for critical variables (REDIS_PASSWORD, APP_KEY, DB_PASSWORD)
3. Staging environment had incorrect SSL certificate paths (copy-paste from production)

## Decision

**Environment files (.env) MUST be managed manually on servers and NEVER downloaded from version control.**

### Core Principles

1. **`.env` files are server-specific secrets** - not code artifacts
2. **Each environment maintains its own `.env`** - managed by server administrators
3. **Deployments update code only** - never touch `.env` files
4. **Validation before deployment** - check critical variables exist and are non-empty

### Implementation Rules

#### 1. GitHub Workflows - Forbidden Actions

**NEVER do these in workflows:**
```yaml
# ❌ FORBIDDEN - Download .env from repo
curl https://raw.githubusercontent.com/.../env.production

# ❌ FORBIDDEN - Copy .env.example to .env
cp .env.example .env

# ❌ FORBIDDEN - Write .env from secrets
echo "APP_KEY=${{ secrets.APP_KEY }}" > .env

# ❌ FORBIDDEN - SSH commands that modify .env
ssh user@server "echo 'VAR=value' >> .env"
```

#### 2. Allowed Deployment Actions

**✅ SAFE - Code and asset deployment:**
```yaml
# ✅ Pull latest code
git pull origin main

# ✅ Install dependencies
composer install --no-dev --optimize-autoloader

# ✅ Build assets
npm ci && npm run build

# ✅ Run migrations
php artisan migrate --force

# ✅ Clear caches
php artisan optimize

# ✅ Restart services
systemctl reload php8.2-fpm nginx
```

#### 3. Environment File Management

**Initial Setup (once per environment):**
```bash
# On server, as deploy user
cd /var/www/paradocks
cp .env.example .env
nano .env  # Configure all variables manually
chmod 640 .env
chown www-data:www-data .env
```

**When new variables are added:**
1. Update `.env.example` in repository (commit to `develop`)
2. Notify server administrators in PR description
3. Admins manually add new variables to server `.env` files
4. Deployment proceeds only after confirmation

#### 4. Pre-Deployment Validation

**REQUIRED: Run validation before every deployment:**

```bash
# Use existing validation script
source .env && ./scripts/validate-env.sh production
```

**Critical variables that MUST be validated:**
- `APP_KEY` (non-empty, 32+ chars)
- `REDIS_PASSWORD` (non-empty)
- `DB_PASSWORD` (non-empty)
- `FILESYSTEM_DISK=public` (not 'local')
- `APP_DEBUG=false` (production only)

**Validation script exit codes:**
- `0` - All checks passed, safe to deploy
- `1` - Validation failed, STOP deployment
- `2` - Script usage error

## Environment Separation

### Staging Environment
- **IP:** 45.93.138.193
- **Domain:** srv1203357.hstgr.cloud
- **SSH Key:** `~/.ssh/id_rsa_staging` (deploy user)
- **Deployment:** Auto-deploy on push to `develop` branch
- **Purpose:** Test deployments, QA, client preview
- **.env location:** `/var/www/paradocks/.env` (managed manually)

### Production Environment
- **IP:** 72.60.17.138
- **Domain:** paradocks.pl
- **SSH Key:** `~/.ssh/id_rsa_production` (deploy user)
- **Deployment:** Manual trigger on git tag (main branch)
- **Purpose:** Live application
- **.env location:** `/var/www/paradocks/.env` (managed manually)

### Separation Rules

1. **NEVER use production credentials on staging**
2. **NEVER use production SSH key for staging operations**
3. **NEVER copy `.env` between environments** (different secrets, domains, certificates)
4. **ALWAYS verify IP before SSH commands** (`ssh user@45.93.138.193` vs `ssh user@72.60.17.138`)

## Consequences

### Positive

- ✅ **Security:** Secrets never stored in version control
- ✅ **Stability:** Deployments can't accidentally wipe critical config
- ✅ **Flexibility:** Each environment can have different settings without code changes
- ✅ **Compliance:** Meets security best practices for secret management

### Negative

- ❌ **Manual overhead:** Admins must manually sync new variables
- ❌ **Documentation:** Must keep `.env.example` up-to-date
- ❌ **Onboarding:** New team members need server access to configure environments

### Mitigations for Negatives

1. **Maintain `.env.example`:**
   - Update whenever new variables are added
   - Add comments explaining each variable's purpose
   - Include default/example values (never real secrets)

2. **Document all new variables:**
   - In PR descriptions when adding new config
   - In `docs/deployment/environment-variables.md`
   - In release notes

3. **Validation automation:**
   - `validate-env.sh` checks for missing variables
   - CI runs validation in test environment
   - Pre-deployment hooks prevent incomplete configs

## Recovery Procedure

**If `.env` is corrupted during deployment:**

```bash
# 1. SSH to affected server
ssh deploy@72.60.17.138  # or 45.93.138.193 for staging

# 2. Check current .env integrity
cd /var/www/paradocks
source .env
echo $REDIS_PASSWORD  # Should NOT be empty
echo $APP_KEY         # Should be base64:... format

# 3. If corrupted, restore from backup
cp .env.backup .env  # Assumes you created backup before deployment

# 4. If no backup, manually recreate critical variables
nano .env
# Set: APP_KEY, DB_*, REDIS_PASSWORD, MAIL_*, etc.

# 5. Validate restored .env
source .env && ./scripts/validate-env.sh production

# 6. Restart services
systemctl reload php8.2-fpm nginx
docker compose restart redis  # If using Docker
```

## Prevention Checklist

**Before every deployment:**

- [ ] Validated `.env` exists and is readable: `test -r .env && echo "OK"`
- [ ] Validated critical variables: `./scripts/validate-env.sh production`
- [ ] Confirmed REDIS_PASSWORD is non-empty: `source .env && test -n "$REDIS_PASSWORD"`
- [ ] Reviewed workflow for `.env` download attempts (should have NONE)
- [ ] Verified correct environment IP (staging vs production)
- [ ] Checked SSL certificate paths (staging vs production have different paths)

**After deployment:**

- [ ] Verified `.env` still intact: `./scripts/validate-env.sh production`
- [ ] Tested Redis connection: `redis-cli -a $REDIS_PASSWORD ping` (should return PONG)
- [ ] Checked application health: `curl -I https://paradocks.pl`
- [ ] Reviewed logs for errors: `tail -n 50 storage/logs/laravel.log`

## Related Documentation

- [Environment Variables Guide](./environment-variables.md)
- [Deployment History](./deployment-history.md)
- [Git Workflow Guide](./GIT_WORKFLOW.md)
- [Validation Script](../../scripts/validate-env.sh)

## References

- Laravel Documentation: [Environment Configuration](https://laravel.com/docs/11.x/configuration#environment-configuration)
- Twelve-Factor App: [III. Config](https://12factor.net/config)
- OWASP: [Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html)
