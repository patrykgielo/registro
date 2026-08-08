# Registro - Car Detailing Booking System

[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql)](https://mysql.com)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker)](https://docker.com)

**A modern, feature-rich booking platform for car detailing services built with Laravel 12, Filament Admin Panel, and Docker.**

---

## 🚀 Quick Start

### Docker (Recommended)

```bash
# 1. Clone & setup
git clone https://github.com/patrykgielo/registro.git
cd registro
./docker-init.sh

# 2. Add to hosts
sudo ./add-hosts-entry.sh

# 3. Access
# Development: https://registro.local:8444
# Admin Panel: https://registro.local:8444/admin
# Default credentials: admin@example.com / password
```

### Production Deployment

See comprehensive guide: **[`docs/deployment/VPS_SETUP.md`](docs/deployment/VPS_SETUP.md)**

---

## 📚 Documentation

### User & Developer Guides
- **[Complete Documentation](docs/README.md)** - All project documentation
- **[Development Guide](CLAUDE.md)** - Setup, commands, and best practices
- **[Deployment Guide](docs/deployment/VPS_SETUP.md)** - VPS production setup

### Features
- **[Email System](docs/features/email-system/README.md)** - Transactional emails with queue
- **[Booking System](docs/features/booking-system/README.md)** - Multi-step booking wizard
- **[Vehicle Management](docs/features/vehicle-management/README.md)** - Vehicle types & models
- **[Google Maps](docs/features/google-maps/README.md)** - Address autocomplete integration

### Architecture
- **[ADRs](docs/decisions/)** - Architecture Decision Records
- **[Troubleshooting](docs/features/email-system/troubleshooting.md)** - Common issues & solutions

---

## 🛠️ Tech Stack

| Component | Technology |
|-----------|-----------|
| **Backend** | Laravel 12, PHP 8.2+ |
| **Admin Panel** | Filament 3.3 |
| **Frontend** | Tailwind CSS 4.0, Vite 7 |
| **Database** | MySQL 8.0 |
| **Queue** | Redis 7.2 + Laravel Horizon |
| **Containers** | Docker + Docker Compose |
| **Testing** | PHPUnit 11.5+ |

---

## 🚢 Deployment

[![Deploy](https://github.com/patrykgielo/registro/actions/workflows/deploy-production.yml/badge.svg)](https://github.com/patrykgielo/registro/actions/workflows/deploy-production.yml)

### CI/CD Deployment

> **Status (2026-08-01): nothing here has ever run.** There is no staging or production
> server yet, and **every** workflow is `workflow_dispatch`-only — pushing a tag deploys
> nothing. The pipeline below is inherited infrastructure that is being brought up for the
> first time; see `app/docs/deployment/production-readiness-checklist.md` for what is still
> open and `~/.claude/plans/vps-bootstrap-registro-first-deploy.md` for the order of work.

Deployment is a manual `workflow_dispatch` run of `deploy-production.yml`, which takes the
release tag as an input, builds and pushes the image to GHCR, then invokes
`/opt/registro/deploy.sh` on the server over SSH (source: `scripts/server/deploy.sh`).

#### Creating a Release

```bash
# Feature release (v1.0.0 → v1.1.0)
./scripts/release.sh minor

# Bug fix (v1.1.0 → v1.1.1)
./scripts/release.sh patch

# Breaking change (v1.1.1 → v2.0.0)
./scripts/release.sh major
```

`release.sh` only creates and pushes the tag. Nothing is triggered by it — deployment is a
separate, manual `workflow_dispatch` run against that tag.

**What that run does:**
1. Runs PHPUnit + Laravel Pint against the tag
2. Builds the Docker image and pushes `:${VERSION}` and `:latest` to GHCR
3. Waits for approval on the `production` GitHub environment
4. Calls `deploy <tag>` on the server, which checks out the tag, pulls the image,
   migrates behind `artisan down`, rebuilds caches, restarts Horizon, health-checks

**What it does NOT do** (contrary to earlier versions of this README): no vulnerability
scanning, and **no automatic rollback on failure**. A failed deploy leaves the previous tag
in the server's `.env`; recovery is a manual `rollback <tag>` — see the checklist's
break-glass section.

#### Monitor Deployment

- **GitHub Actions:** https://github.com/patrykgielo/registro/actions
- **Production Health:** https://registro.local:8444/health
- **Manual Approval:** Actions → Deploy to Production → Review deployments

#### Manual Deployment (SSH)

The deploy user's key is pinned to a forced command, so the SSH command line is the whole
API — there is no shell on the far end. `srv1342834.hstgr.cloud` here is the VPS's own hostname
for SSH, not the application domain — the app is served at `registrolabs.com` (see
`app/docs/deployment/domain-migration-registrolabs.md`). The two are unrelated and change
independently; don't "fix" this to `registrolabs.com`.

```bash
ssh deploy@srv1342834.hstgr.cloud "deploy v1.2.3"
ssh deploy@srv1342834.hstgr.cloud "status"
```

### Backup & Rollback

Rolling back means re-pinning the image tag; no build, no GitHub, no runner:

```bash
ssh deploy@srv1342834.hstgr.cloud "rollback v1.2.2"
```

**Rolling the image back does not roll migrations back.** Additive migrations survive it,
`drop`/`rename` do not — check with `php artisan migrations:check-rollback` before relying
on this. Database backups: `scripts/backup-database.sh` (to `/var/backups/registro`); a
backup you have not restored is not a backup.

**See:** [Deployment Runbook](docs/archive/deployment/runbooks/ci-cd-deployment.md) —
archived and written for the predecessor project's hosts; treat as reference, not
instructions. The current source of truth is
`app/docs/deployment/production-readiness-checklist.md`.

---

## 📝 License

**Proprietary License** - All rights reserved.

---

**Last Updated:** November 2025
**Version:** 1.0.0
**Maintainer:** Registro Team
