# CI/CD Deployment Runbook

**Version:** 1.0.0
**Last Updated:** November 2025
**Target Environment:** Production VPS (72.60.17.138)

## Table of Contents

1. [Overview](#overview)
2. [Pre-Deployment Checklist](#pre-deployment-checklist)
3. [Creating a Release](#creating-a-release)
4. [Deployment Workflow](#deployment-workflow)
5. [Manual Deployment](#manual-deployment)
6. [Rollback Procedures](#rollback-procedures)
7. [Monitoring & Verification](#monitoring--verification)
8. [Troubleshooting](#troubleshooting)
9. [Emergency Procedures](#emergency-procedures)

---

## Overview

### Deployment Architecture

- **CI/CD Platform:** GitHub Actions
- **Container Registry:** GitHub Container Registry (GHCR)
- **Deployment Method:** Docker image pull + restart
- **Maintenance System:** MaintenanceService (Redis-based)
- **Approval Process:** Manual approval required for production
- **Rollback Strategy:** Version-based Docker image reversion

### Key Components

```
GitHub Tag (v1.2.3)
    ↓
GitHub Actions Workflow
    ↓
Docker Image Build → GHCR (ghcr.io/patrykgielo/paradocks:v1.2.3)
    ↓
Manual Approval Gate
    ↓
SSH to VPS → Enable MaintenanceService
    ↓
Pull Docker Image → Restart Containers
    ↓
Run Migrations → Rebuild Caches
    ↓
Health Check → Disable MaintenanceService
```

---

## Pre-Deployment Checklist

### Before Creating a Release

- [ ] All tests passing locally: `composer run test`
- [ ] Code formatted with Pint: `./vendor/bin/pint`
- [ ] `.env.production.example` updated with new config if needed
- [ ] Database migrations tested locally
- [ ] Changelog/release notes prepared
- [ ] Stakeholders notified (if major release)
- [ ] Backup schedule verified (automatic before deployment)

### Environment Verification

```bash
# Verify VPS is accessible
ssh deploy@72.60.17.138 "docker compose -f /var/www/paradocks/docker-compose.prod.yml ps"

# Check disk space (need ~2GB for Docker images)
ssh deploy@72.60.17.138 "df -h /var/www"

# Verify GHCR authentication
ssh deploy@72.60.17.138 "docker login ghcr.io -u patrykgielo --password-stdin < /home/deploy/.ghcr-token"
```

---

## Creating a Release

### Semantic Versioning Strategy

Follow [Semantic Versioning 2.0.0](https://semver.org/):

- **MAJOR** (v2.0.0): Breaking changes, incompatible API changes
- **MINOR** (v1.1.0): New features, backward-compatible
- **PATCH** (v1.0.1): Bug fixes, backward-compatible

### Release Commands

```bash
# Navigate to project root
cd /var/www/projects/paradocks/app

# Create a patch release (v1.0.0 → v1.0.1)
./scripts/release.sh patch

# Create a minor release (v1.0.0 → v1.1.0)
./scripts/release.sh minor

# Create a major release (v1.0.0 → v2.0.0)
./scripts/release.sh major
```

### What Happens After Tag Push

1. **GitHub Actions triggered** by `v*.*.*` tag
2. **Build job runs:**
   - Build Docker image
   - Run PHPUnit tests + Laravel Pint
   - Scan for vulnerabilities (Trivy)
   - Push to GHCR: `ghcr.io/patrykgielo/paradocks:v1.2.3`
3. **Deploy job waits** for manual approval
4. **Notification sent** to approvers via email

---

## Deployment Workflow

### Automatic Deployment (via GitHub Actions)

#### Step 1: Monitor Build Progress

```
GitHub Actions URL: https://github.com/patrykgielo/paradocks/actions
```

**Build & Test job tasks:**
- Checkout code
- Build Docker image
- Run tests (PHPUnit)
- Code formatting check (Pint)
- Security scan (Trivy)
- Push to GHCR

**Expected duration:** 5-10 minutes

#### Step 2: Approve Deployment

When build completes, deployment job requires manual approval:

1. Go to: https://github.com/patrykgielo/paradocks/actions
2. Click on the running workflow
3. Click "Review deployments" button
4. Select "production" environment
5. Click "Approve and deploy"

**Approval timeout:** 24 hours (after that, workflow expires)

#### Step 3: Monitor Deployment

**Deploy job tasks:**
- SSH to VPS (72.60.17.138)
- Enable MaintenanceService (Deployment type, 2 min estimate)
- Create database backup
- Pull Docker image from GHCR
- Restart app, horizon, scheduler containers
- Run database migrations
- Clear and rebuild caches
- Verify health endpoint
- Disable MaintenanceService

**Expected duration:** 3-5 minutes

**Live logs:** GitHub Actions → Deploy to Production job

#### Step 4: Automatic Verification

**Verify job tasks:**
- Check container status (all running)
- Query health endpoint: `GET /health`
- Verify response: `{"status":"healthy"}`

**On failure:** Automatic rollback triggered

---

## Manual Deployment

### When to Use Manual Deployment

- CI/CD pipeline unavailable
- Emergency hotfix needed immediately
- Testing deployment script changes
- Rollback to previous version

### SSH Access

```bash
# Connect to VPS
ssh deploy@72.60.17.138

# Navigate to app directory
cd /var/www/paradocks
```

### Deploy Specific Version

```bash
# Deploy latest version
./scripts/deploy-update.sh latest

# Deploy specific version
./scripts/deploy-update.sh v1.2.3

# Deploy with options
./scripts/deploy-update.sh v1.2.3 --skip-backup --force
```

### Deploy Script Options

```
--skip-backup       Skip database backup (NOT recommended)
--skip-migrations   Skip running migrations
--force             Skip all confirmation prompts
```

### Manual Deployment Steps (Internal Script Flow)

1. **Prerequisites check** - Verify Docker containers running
2. **User confirmation** - Prompt to continue (unless `--force`)
3. **Enable maintenance mode** - MaintenanceService (Deployment type)
4. **Database backup** - Automatic timestamped backup
5. **Pull Docker image** - From GHCR: `ghcr.io/patrykgielo/paradocks:$VERSION`
6. **Restart services** - App, Horizon, Scheduler containers
7. **Run migrations** - `php artisan migrate --force` (~15s downtime)
   - **Note:** Migrations handle BOTH schema changes AND reference data updates (email/SMS templates)
   - Schema migrations: create/alter tables
   - Data migrations: seed email templates (30), SMS templates (14)
   - Idempotent: tracked in migrations table, safe to run multiple times
8. **Rebuild caches** - Config, routes, views, Filament
9. **Verify deployment** - Check containers, logs, health
10. **Disable maintenance mode** - MaintenanceService disabled

---

## Rollback Procedures

### Quick Rollback (Recommended)

Deploy the previous working version:

```bash
# SSH to VPS
ssh deploy@72.60.17.138
cd /var/www/paradocks

# Deploy previous version (example: rollback v1.2.3 → v1.2.2)
./scripts/deploy-update.sh v1.2.2
```

**Duration:** ~3 minutes (same as deployment)

### Emergency Rollback (Database Restore)

If migrations failed and database is corrupted:

```bash
# 1. Stop application
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:enable \
    --type=emergency \
    --message="Database restoration in progress"

# 2. List backups
ls -lht /var/www/paradocks/backups/

# 3. Restore latest backup
docker compose -f docker-compose.prod.yml exec -T mysql mysql \
    -u paradocks \
    -p${DB_PASSWORD} \
    paradocks < /var/www/paradocks/backups/db-v1.2.2-20251128_143022.sql

# 4. Rollback Docker image
./scripts/deploy-update.sh v1.2.2 --skip-backup --skip-migrations

# 5. Verify and bring back online
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:disable
```

### Rollback Decision Matrix

| Scenario | Action | Command |
|----------|--------|---------|
| Code bug, DB unchanged | Quick rollback | `./scripts/deploy-update.sh v1.2.2` |
| Migration failed, DB safe | Quick rollback | `./scripts/deploy-update.sh v1.2.2` |
| Migration corrupt DB | Emergency rollback | Manual DB restore + deploy |
| Config error | Quick rollback | `./scripts/deploy-update.sh v1.2.2` |

---

## Monitoring & Verification

### Health Check Endpoint

```bash
# Check application health
curl -s https://paradocks.local:8444/health | jq

# Expected response
{
  "status": "healthy",
  "checks": {
    "database": true,
    "redis": "PONG"
  },
  "timestamp": "2025-11-29T14:30:22Z",
  "version": "v1.2.3"
}
```

### Container Status

```bash
# Check all containers
docker compose -f docker-compose.prod.yml ps

# Expected output (all "Up")
NAME                        STATUS
paradocks-app-prod          Up (healthy)
paradocks-mysql-prod        Up (healthy)
paradocks-nginx-prod        Up (healthy)
paradocks-redis-prod        Up (healthy)
paradocks-horizon-prod      Up (healthy)
paradocks-scheduler-prod    Up
```

### Application Logs

```bash
# View recent app logs
docker compose -f docker-compose.prod.yml logs --tail=50 app

# Follow logs in real-time
docker compose -f docker-compose.prod.yml logs -f app

# Check for errors
docker compose -f docker-compose.prod.yml logs app | grep -i error
```

### Laravel Horizon Dashboard

```
URL: https://paradocks.local:8444/horizon
```

**Check:**
- All queues processing
- No failed jobs
- Recent jobs completing successfully

### Maintenance Mode Status

```bash
# Check if maintenance mode is active
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:status

# Expected output (after deployment)
Maintenance Mode: DISABLED
```

---

## Troubleshooting

### Deployment Failed During Build

**Symptom:** GitHub Actions build job fails

**Common Causes:**
1. Tests failing
2. Pint formatting violations
3. Trivy security vulnerabilities

**Resolution:**
```bash
# Run tests locally
cd app && composer run test

# Fix formatting
cd app && ./vendor/bin/pint

# Address security issues
# Review Trivy report in GitHub Actions logs
```

### Deployment Stuck at Approval

**Symptom:** Workflow waiting for approval, no notification

**Resolution:**
1. Check GitHub notifications settings
2. Manually navigate to Actions tab
3. Click "Review deployments"
4. Approve production environment

### Docker Image Pull Failed

**Symptom:** `Failed to pull Docker image` error

**Common Causes:**
1. GHCR authentication expired
2. Image tag doesn't exist
3. Network connectivity issues

**Resolution:**
```bash
# Re-authenticate with GHCR
ssh deploy@72.60.17.138
echo $GHCR_TOKEN | docker login ghcr.io -u patrykgielo --password-stdin

# Verify image exists
docker pull ghcr.io/patrykgielo/paradocks:v1.2.3

# Check network
ping ghcr.io
```

### Migrations Failed

**Symptom:** `Migrations failed!` error during deployment

**Resolution:**
```bash
# Check migration error logs
docker compose -f docker-compose.prod.yml logs app | grep -A 20 "Migration"

# Manually run migrations with verbose output
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force --verbose

# If corrupt, restore backup and rollback
# See: Emergency Rollback section
```

### Containers Exiting After Restart

**Symptom:** App/Horizon/Scheduler containers exit immediately

**Common Causes:**
1. .env configuration error
2. Missing dependencies in Docker image
3. Database connection failure

**Resolution:**
```bash
# Check container logs for errors
docker compose -f docker-compose.prod.yml logs app
docker compose -f docker-compose.prod.yml logs horizon

# Verify .env configuration
cat .env | grep -E "DB_|REDIS_|APP_"

# Test database connection
docker compose -f docker-compose.prod.yml exec mysql mysql \
    -u paradocks \
    -p${DB_PASSWORD} \
    -e "SELECT 1"

# Restart all containers
docker compose -f docker-compose.prod.yml restart
```

### Health Check Failing

**Symptom:** `/health` endpoint returns 503 or 500

**Resolution:**
```bash
# Check which service is failing
curl -s https://paradocks.local:8444/health | jq '.checks'

# Database failing
docker compose -f docker-compose.prod.yml restart mysql
docker compose -f docker-compose.prod.yml logs mysql

# Redis failing
docker compose -f docker-compose.prod.yml restart redis
docker compose -f docker-compose.prod.yml logs redis

# Clear application cache
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
```

### Maintenance Mode Stuck Enabled

**Symptom:** Application still shows maintenance page after deployment

**Resolution:**
```bash
# Force disable maintenance mode
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:disable --force

# Verify status
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:status

# Check Redis keys (should be empty)
docker compose -f docker-compose.prod.yml exec redis redis-cli KEYS "maintenance:*"
```

---

## Emergency Procedures

### Complete Service Outage

**Symptom:** Application completely unavailable, containers down

**Emergency Response:**

```bash
# 1. SSH to VPS
ssh deploy@72.60.17.138
cd /var/www/paradocks

# 2. Start all containers
docker compose -f docker-compose.prod.yml up -d

# 3. Check status
docker compose -f docker-compose.prod.yml ps

# 4. If still failing, check disk space
df -h

# 5. Check logs for root cause
docker compose -f docker-compose.prod.yml logs --tail=100

# 6. Notify stakeholders
```

### Database Corruption

**Symptom:** Database queries failing, data inconsistency

**Emergency Response:**

```bash
# 1. Enable emergency maintenance
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:enable \
    --type=emergency \
    --message="Database restoration in progress"

# 2. Stop application containers
docker compose -f docker-compose.prod.yml stop app horizon scheduler

# 3. Restore latest backup (see Rollback Procedures)

# 4. Verify database integrity
docker compose -f docker-compose.prod.yml exec mysql mysqlcheck \
    -u paradocks \
    -p${DB_PASSWORD} \
    --auto-repair \
    --check \
    --all-databases

# 5. Restart containers
docker compose -f docker-compose.prod.yml start app horizon scheduler

# 6. Disable maintenance
docker compose -f docker-compose.prod.yml exec app php artisan maintenance:disable
```

### Disk Space Full

**Symptom:** Containers failing, "no space left on device"

**Emergency Response:**

```bash
# 1. Check disk usage
df -h
du -sh /var/www/paradocks/*

# 2. Clean Docker system
docker system prune -af --volumes

# 3. Remove old images
docker images | grep paradocks | grep -v latest | awk '{print $3}' | xargs docker rmi

# 4. Clean Laravel logs
rm -f /var/www/paradocks/storage/logs/*.log

# 5. Archive old backups
tar -czf /tmp/old-backups.tar.gz /var/www/paradocks/backups/*.sql
rm -f /var/www/paradocks/backups/*.sql

# 6. Verify space available
df -h
```

---

## Best Practices

### Deployment Timing

- **Preferred:** Outside business hours (evenings/weekends)
- **Avoid:** Friday afternoons, before holidays
- **Notify:** Users 24-48 hours in advance for major releases

### Testing

- Always run tests locally before creating release
- Use staging environment for final verification (if available)
- Test rollback procedure at least once per quarter

### Communication

- Update changelog with each release
- Notify stakeholders of deployment schedule
- Document any breaking changes or migration notes
- Post-deployment announcement with version deployed

### Backup Retention

- **Daily backups:** Keep 7 days
- **Weekly backups:** Keep 4 weeks
- **Release backups:** Keep indefinitely (created before each deployment)

---

## Appendix

### Environment Variables Reference

```bash
# VPS Production Environment
APP_ENV=production
APP_DEBUG=false
APP_URL=https://paradocks.local:8444

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=paradocks
DB_USERNAME=paradocks
DB_PASSWORD=<PRODUCTION_PASSWORD>

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=<PRODUCTION_PASSWORD>
QUEUE_CONNECTION=redis

# Docker Image Version
VERSION=v1.2.3  # Set by deploy-update.sh
```

### GitHub Secrets Configuration

Required secrets in GitHub repository settings:

```
VPS_HOST=72.60.17.138
VPS_USER=deploy
VPS_SSH_KEY=<ed25519 private key>
VPS_PORT=22
GHCR_TOKEN=<GitHub Personal Access Token>
```

### Useful Commands Cheatsheet

```bash
# Check deployment version
docker compose -f docker-compose.prod.yml exec app php artisan --version

# View current Docker image
docker compose -f docker-compose.prod.yml images app

# Manually trigger cache rebuild
docker compose -f docker-compose.prod.yml exec app php artisan optimize

# Check queue workers
docker compose -f docker-compose.prod.yml exec app php artisan queue:work --once

# View horizon status
docker compose -f docker-compose.prod.yml exec app php artisan horizon:status
```

---

**Document Owner:** DevOps Team
**Review Cycle:** Quarterly
**Last Review:** November 2025
**Next Review:** February 2026
