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

### CI/CD Automated Deployment

**Modern, automated deployment with GitHub Actions + Docker + MaintenanceService integration.**

#### Creating a Release

```bash
# Feature release (v1.0.0 → v1.1.0)
./scripts/release.sh minor

# Bug fix (v1.1.0 → v1.1.1)
./scripts/release.sh patch

# Breaking change (v1.1.1 → v2.0.0)
./scripts/release.sh major
```

**What happens automatically:**
1. ✅ Build Docker image (tagged with version)
2. ✅ Run PHPUnit tests + Laravel Pint
3. ✅ Scan for vulnerabilities (Trivy)
4. ✅ Wait for manual approval (production environment)
5. ✅ Deploy to VPS with MaintenanceService
6. ✅ Run migrations & health checks
7. ✅ Automatic rollback on failure

#### Monitor Deployment

- **GitHub Actions:** https://github.com/patrykgielo/registro/actions
- **Production Health:** https://registro.local:8444/health
- **Manual Approval:** Actions → Deploy to Production → Review deployments

#### Manual Deployment (SSH)

```bash
# SSH to VPS
ssh deploy@72.60.17.138

# Deploy specific version
cd /var/www/registro
./scripts/deploy-update.sh v1.2.3
```

### Backup & Rollback

```bash
# Automated backup before every deployment
# Location: /var/www/registro/backups/db-v1.0.0-20251128.sql

# Rollback to previous version
ssh deploy@72.60.17.138
cd /var/www/registro
./scripts/deploy-update.sh v1.0.4  # Previous working version
```

**See:** [Deployment Runbook](docs/deployment/runbooks/ci-cd-deployment.md) for complete procedures.

---

## 📝 License

**Proprietary License** - All rights reserved.

---

**Last Updated:** November 2025
**Version:** 1.0.0
**Maintainer:** Registro Team
