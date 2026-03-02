# ADR-010: CI/CD Deployment Strategy with GitHub Actions and Docker

**Date:** 2025-11-29
**Status:** Accepted
**Context:** Production Deployment, VPS Infrastructure
**Authors:** Development Team

---

## Context and Problem Statement

The Paradocks application requires a modern, automated deployment system for production VPS (72.60.17.138). The deployment system must:

1. **Automate deployments** - Reduce manual steps, human error, deployment time
2. **Maintain zero downtime** - Use MaintenanceService for professional maintenance pages
3. **Version control** - Semantic versioning with Git tags (v1.0.0, v1.1.0, etc.)
4. **Rollback capability** - Quick rollback to previous versions
5. **Security** - Automated vulnerability scanning, secure credentials management
6. **Approval workflow** - Manual approval gate for production deployments
7. **Observability** - Health checks, deployment logs, status monitoring

**Current State (Before ADR-010):**
- Manual SSH deployments
- Git-based code pulling (git pull)
- Local Docker image rebuilds on VPS
- No automated testing in deployment pipeline
- No rollback strategy
- No deployment history/audit trail

**Problems with Current Approach:**
- ❌ High deployment time (~15-20 minutes manual)
- ❌ Human error risk (missed migrations, cache clearing)
- ❌ No automated testing before production
- ❌ Difficult rollbacks (manual git reset + rebuild)
- ❌ No deployment notifications
- ❌ No security scanning

---

## Decision Drivers

### Technical Drivers
- **Existing Infrastructure** - Single VPS (72.60.17.138), Docker Compose setup
- **Laravel 12 Stack** - PHP 8.2, MySQL 8.0, Redis 7.2, Horizon
- **MaintenanceService** - Already implemented (ADR-006) for professional maintenance pages
- **Docker Architecture** - Already containerized (app, nginx, mysql, redis, horizon, scheduler)
- **Private Repository** - GitHub private repository (patrykgielo/paradocks)

### Business Drivers
- **Cost Efficiency** - Use free tier options (GitHub Actions free minutes, GHCR free for private repos)
- **Developer Experience** - Single command deployment (`./scripts/release.sh minor`)
- **Reliability** - Automated testing, health checks, rollback capability
- **Scalability** - Design for future growth (staging, multi-server, blue-green)
- **Time to Market** - Fast, reliable deployments (3-5 minutes vs 15-20 manual)

### Compliance & Security
- **Audit Trail** - Track who deployed what and when
- **Approval Process** - Manual gate for production (prevent accidental deploys)
- **Vulnerability Scanning** - Automated security scans before production
- **Secret Management** - Secure credential storage (no .env in repository)

---

## Considered Options

### Option 1: GitHub Actions + Docker + GHCR (SELECTED)

**Architecture:**
```
Git Tag (v1.2.3)
    ↓
GitHub Actions Workflow
    ↓
Build Docker Image → Push to GHCR
    ↓
Manual Approval Gate
    ↓
SSH to VPS → Pull Image → Restart Containers
    ↓
Health Check → Rollback on Failure
```

**Components:**
- **CI/CD Platform:** GitHub Actions (free 2000 minutes/month for private repos)
- **Container Registry:** GitHub Container Registry (GHCR) - free for private repos
- **Version Strategy:** Semantic versioning with Git tags
- **Deployment Script:** Enhanced `deploy-update.sh` with MaintenanceService
- **Rollback:** Version-based Docker image reversion

**Pros:**
- ✅ **Native Integration** - GitHub Actions integrated with repository
- ✅ **Free Tier** - 2000 free minutes/month, GHCR free storage
- ✅ **Docker-first** - Pre-built images, consistent environments
- ✅ **Fast Rollback** - Deploy previous image version (30 seconds)
- ✅ **Manual Approval** - Production environment protection
- ✅ **Secrets Management** - GitHub Secrets (encrypted at rest)
- ✅ **Audit Trail** - Deployment history in GitHub Actions
- ✅ **Health Checks** - Automated verification with rollback
- ✅ **Scalable** - Easy to add staging, multi-server deployments

**Cons:**
- ⚠️ **GitHub Lock-in** - Migration to other platforms requires rework
- ⚠️ **Minutes Limit** - Free tier has 2000 minutes/month (sufficient for small teams)
- ⚠️ **Cold Builds** - First build slower than cached (mitigated with layer caching)

**Cost Analysis:**
- GitHub Actions: FREE (2000 min/month sufficient for ~100 deployments)
- GHCR Storage: FREE (private repositories)
- VPS: Existing cost (no additional cost)
- **Total Additional Cost:** $0/month

---

### Option 2: Laravel Deployer (deployer.org)

**Pros:**
- ✅ PHP-based deployment tool
- ✅ Laravel-specific recipes
- ✅ Atomic deployments

**Cons:**
- ❌ Manual CI/CD setup (Jenkins, GitLab CI)
- ❌ Limited Docker support
- ❌ No container registry integration
- ❌ Requires separate runner infrastructure
- ❌ Weak rollback strategy (symlink-based)

**Rejected because:** Poor Docker integration, requires additional infrastructure

---

### Option 3: GitLab CI/CD + GitLab Container Registry

**Pros:**
- ✅ Integrated CI/CD + Container Registry
- ✅ Docker-first approach
- ✅ Free tier available

**Cons:**
- ❌ Requires GitLab migration (code, issues, PRs)
- ❌ Free tier limits (400 CI/CD minutes/month)
- ❌ Learning curve for team
- ❌ Less integrated with GitHub ecosystem

**Rejected because:** GitHub migration overhead, lower free tier limits

---

### Option 4: Jenkins + Self-hosted Runner

**Pros:**
- ✅ Full control and customization
- ✅ No vendor lock-in
- ✅ Unlimited build minutes

**Cons:**
- ❌ Requires dedicated infrastructure (runner server)
- ❌ Maintenance overhead (updates, security patches)
- ❌ Complex setup (Java, plugins, webhooks)
- ❌ Additional cost ($5-10/month for runner VPS)
- ❌ Single point of failure

**Rejected because:** Infrastructure overhead, maintenance burden, additional cost

---

### Option 5: Blue-Green Deployment

**Architecture:**
- Maintain 2 identical environments (blue + green)
- Deploy to inactive environment
- Switch traffic with zero downtime

**Pros:**
- ✅ True zero downtime
- ✅ Instant rollback (switch traffic back)

**Cons:**
- ❌ Requires 2x infrastructure (2x VPS or Kubernetes)
- ❌ Database migration complexity (shared DB or dual DB?)
- ❌ Session management issues (sticky sessions)
- ❌ 2x cost

**Rejected because:** Resource overhead, complexity not justified for single-VPS setup

---

## Decision Outcome

**Chosen Option:** **GitHub Actions + Docker + GHCR** (Option 1)

### Rationale

1. **Cost-Effective:** $0 additional cost (free tier sufficient)
2. **Native Integration:** Already using GitHub for code hosting
3. **Docker-First:** Aligns with existing containerized architecture
4. **Fast Rollback:** Docker image versioning enables 30-second rollbacks
5. **Scalable:** Easy migration to multi-server, staging environments
6. **Security:** Built-in secrets management, vulnerability scanning (Trivy)
7. **Developer Experience:** Single command deployment (`./scripts/release.sh minor`)
8. **Maintenance Integration:** Uses existing MaintenanceService (ADR-006)

### Implementation Overview

#### 1. Semantic Versioning Strategy

**Format:** `vMAJOR.MINOR.PATCH` (e.g., v1.2.3)

- **MAJOR:** Breaking changes (v2.0.0)
- **MINOR:** New features, backward-compatible (v1.1.0)
- **PATCH:** Bug fixes, backward-compatible (v1.0.1)

**Script:** `scripts/release.sh`
- Automated version bumping
- Git tag creation and push
- Triggers GitHub Actions workflow

#### 2. GitHub Actions Workflow

**File:** `.github/workflows/deploy-production.yml`

**Trigger:** Git tag push matching `v*.*.*`

**Jobs:**

1. **Build & Test** (5-7 minutes)
   - Checkout code
   - Build Docker image
   - Run PHPUnit tests
   - Run Laravel Pint (code formatting)
   - Scan with Trivy (vulnerability detection)
   - Push to GHCR: `ghcr.io/patrykgielo/paradocks:v1.2.3`

2. **Deploy to Production** (3-5 minutes)
   - **Manual approval required** (GitHub environment protection)
   - SSH to VPS (72.60.17.138)
   - Enable MaintenanceService (Deployment type, 2 min estimate)
   - Backup database (automatic timestamped backup)
   - Pull Docker image from GHCR
   - Restart containers (app, horizon, scheduler)
   - Run migrations (`php artisan migrate --force`)
   - Rebuild caches (config, routes, views, Filament)
   - Disable MaintenanceService

3. **Verify Deployment** (30 seconds)
   - Check container status
   - Query health endpoint: `GET /health`
   - Verify `{"status":"healthy"}`
   - **Automatic rollback on failure**

#### 3. Docker Image Versioning

**Registry:** GitHub Container Registry (GHCR)
**Image:** `ghcr.io/patrykgielo/paradocks:$VERSION`

**Tags:**
- `v1.2.3` - Specific version
- `latest` - Latest stable release

**Benefits:**
- Pre-built images (no build time on VPS)
- Immutable deployments (v1.2.3 always identical)
- Fast rollback (pull previous tag)
- Version history (all images retained)

#### 4. MaintenanceService Integration

**Enable During Deployment:**
```bash
php artisan maintenance:enable \
    --type=deployment \
    --message="Deploying version v1.2.3" \
    --estimated-duration="2 minutes"
```

**Benefits:**
- Professional maintenance page (not generic Laravel page)
- Estimated completion time
- Admin bypass (secret token)
- Redis-based state (survives container restarts)

**Disable After Success:**
```bash
php artisan maintenance:disable
```

#### 5. Health Check Endpoint

**Endpoint:** `GET /health`

**Response:**
```json
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

**Status Codes:**
- `200` - Healthy (all checks passed)
- `503` - Degraded (some checks failed)
- `500` - Unhealthy (exception occurred)

**Usage:**
- CI/CD verification (GitHub Actions)
- Uptime monitoring (external services)
- Load balancer health checks (future)

#### 6. Rollback Strategy

**Quick Rollback (3 minutes):**
```bash
./scripts/deploy-update.sh v1.2.2  # Previous working version
```

**Emergency Rollback (10 minutes):**
1. Restore database from backup
2. Deploy previous Docker image
3. Verify health checks

**Backup Retention:**
- All deployment backups: Indefinite
- Daily backups: 7 days
- Weekly backups: 4 weeks

---

## Consequences

### Positive Consequences

1. **Deployment Speed** - 3-5 minutes (vs 15-20 manual)
2. **Reduced Errors** - Automated testing catches issues before production
3. **Fast Rollback** - 30 seconds to revert Docker image
4. **Audit Trail** - Complete deployment history in GitHub Actions
5. **Security** - Automated vulnerability scanning (Trivy)
6. **Developer Experience** - Single command: `./scripts/release.sh minor`
7. **Zero Downtime** - MaintenanceService professional maintenance pages
8. **Cost Savings** - $0 additional infrastructure cost

### Negative Consequences

1. **GitHub Lock-in** - Migration to other platforms requires workflow rewrite
2. **Docker Dependency** - All deployments require Docker (not a PHP-only deploy)
3. **Learning Curve** - Team needs to understand GitHub Actions YAML syntax
4. **Build Time** - Initial Docker image build ~5-7 minutes (mitigated with layer caching)

### Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| GitHub Actions outage | Cannot deploy | Manual deployment via `deploy-update.sh` |
| GHCR outage | Cannot pull images | Use `latest` tag cached on VPS |
| SSH key compromised | Unauthorized deployments | Rotate SSH keys, audit logs |
| Failed migration | Database corruption | Automatic backup before deployment |
| Health check false positive | Failed rollback | Manual verification, disable auto-rollback |

---

## Implementation Notes

### Phase 1: Core CI/CD (COMPLETED)

- [x] GitHub Actions workflow (`.github/workflows/deploy-production.yml`)
- [x] Semantic versioning script (`scripts/release.sh`)
- [x] Health check endpoint (`/health`)
- [x] Deploy script upgrade (`scripts/deploy-update.sh v2.0.0`)
- [x] Docker Compose update (GHCR images)
- [x] README.md documentation
- [x] Deployment runbook (`docs/deployment/runbooks/ci-cd-deployment.md`)

### Phase 2: VPS Setup (MANUAL STEPS REQUIRED)

- [ ] Create `deploy` user on VPS with sudo privileges
- [ ] Generate ed25519 SSH key pair for deployments
- [ ] Configure GitHub Secrets (VPS_HOST, VPS_USER, VPS_SSH_KEY, VPS_PORT)
- [ ] Authenticate VPS with GHCR (`docker login ghcr.io`)
- [ ] Generate GitHub Personal Access Token for GHCR (GHCR_TOKEN)
- [ ] Test first deployment with `v1.0.0` tag

### Phase 3: Future Enhancements (OPTIONAL)

- [ ] Staging environment (separate VPS or namespace)
- [ ] Slack/Discord deployment notifications
- [ ] Performance metrics tracking (deployment duration)
- [ ] Multi-server deployments (horizontal scaling)
- [ ] Blue-green deployment option (if budget allows)
- [ ] Automated database migration dry-run
- [ ] Integration tests in CI/CD pipeline

---

## References

### Documentation

- **Deployment Runbook:** `docs/deployment/runbooks/ci-cd-deployment.md`
- **VPS Setup Guide:** `docs/deployment/vps-setup.md` (to be created)
- **GitHub Secrets Guide:** `docs/deployment/github-secrets.md` (to be created)
- **ADR-006:** 24-Hour Calendar Blocking (MaintenanceService architecture)

### External Resources

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [GitHub Container Registry](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)
- [Semantic Versioning 2.0.0](https://semver.org/)
- [Docker Best Practices](https://docs.docker.com/develop/dev-best-practices/)
- [Trivy Security Scanner](https://aquasecurity.github.io/trivy/)

### Related ADRs

- **ADR-006:** 24-Hour Calendar Blocking (MaintenanceService implementation)
- **ADR-007:** Staff Role Enforcement (Production security)
- **ADR-009:** SMS System Implementation (Production notification strategy)

---

**Review Date:** February 2026
**Supersedes:** N/A (Initial CI/CD ADR)
**Superseded By:** N/A
