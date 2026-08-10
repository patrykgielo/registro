# Technology Stack

This document provides a comprehensive overview of all technologies used in the Registro project.

**Last Updated**: 2026-08-08
**Environment**: Deployed. The application is served at `registrolabs.com` (own registered
domain, own DNS zone at Hostinger) — the app-facing domain decision this document previously
flagged as pending has been made. The underlying VPS is still `srv1342834.hstgr.cloud`
(76.13.76.104), and that hostname remains correct for SSH/infra purposes; it is no longer the
domain the application answers on. See `app/docs/deployment/domain-migration-registrolabs.md`
for the migration, and `app/docs/deployment/production-readiness-checklist.md` for the original
first-deploy history.

---

## Overview

Registro is a modern Laravel application built with Docker containerization, featuring a Filament-powered admin panel and real-time features via Laravel Horizon.

```
┌─────────────────────────────────────────────────────┐
│                    Nginx 1.25.5                     │
│            (Reverse Proxy & Static Files)           │
└───────────────┬─────────────────────────────────────┘
                │
    ┌───────────┴───────────┬────────────────┐
    │                       │                │
┌───▼────────┐    ┌────────▼──────┐    ┌───▼──────┐
│  PHP-FPM   │    │  Laravel       │    │  Redis   │
│    8.2     │◄───│  Horizon       │◄───│   7.2    │
│            │    │  (Queues)      │    │  (Cache) │
└─────┬──────┘    └────────────────┘    └──────────┘
      │
      │
┌─────▼──────┐
│  MySQL 8.0 │
│ (Database) │
└────────────┘
```

---

## Backend Stack

### PHP Framework

**Laravel 12.32.5**
- **Purpose**: Core application framework
- **Key Features Used**:
  - Eloquent ORM for database operations
  - Livewire 3.6.4 for reactive components
  - Sanctum for API authentication (ready)
  - Horizon for queue monitoring
  - Task scheduling (Laravel Scheduler)
- **Location**: `/var/www/registro/`
- **Entry Point**: `public/index.php`

**Configuration Files**:
- `config/app.php` - Application core settings
- `config/database.php` - Database connections
- `config/queue.php` - Queue configuration (Redis)
- `config/cache.php` - Cache configuration (Redis)
- `config/session.php` - Session configuration (Redis)
- `config/horizon.php` - Horizon monitoring settings

### PHP Runtime

**PHP 8.3 with PHP-FPM**
- **Container**: `registro-app`
- **Base Image**: `php:8.3-fpm` (Debian, not Alpine — required for Playwright/browser-testing
  compatibility per `app/docs/decisions/ADR-013-docker-user-model.md`)
- **Process Manager**: PHP-FPM (FastCGI Process Manager)
- **Configuration**: `docker/php/php.ini`

**Installed Extensions**:
```
Core Extensions:
- pdo, pdo_mysql - Database connectivity
- mysqli - MySQL improved extension
- redis - Redis client
- gd - Image manipulation
- intl - Internationalization
- zip - Archive handling
- bcmath - Arbitrary precision mathematics
- opcache - Performance optimization

Additional:
- exif - Image metadata
- pcntl - Process control (for Horizon)
- sockets - Network sockets (for queues)
```

**PHP Configuration**:
```ini
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300
date.timezone = Europe/Warsaw
opcache.enable = 1
```

---

## Frontend Stack

### Build Tools

**Vite 7.1.9**
- **Purpose**: Frontend build tool and development server
- **Configuration**: `vite.config.js`
- **Output**: `public/.vite/` (with manifest symlink at `public/build/manifest.json`)
- **Build Command**: `npm run build`

**Key Features**:
- Hot Module Replacement (HMR) in development
- Asset optimization and minification
- CSS/JS bundling
- Version hashing for cache busting

### CSS Framework

**Tailwind CSS 4.0**
- **Purpose**: Utility-first CSS framework
- **Configuration**: `tailwind.config.js`
- **Integration**: Via Vite and Laravel Filament
- **Custom Theme**: Configured for Filament components

### JavaScript Runtime

**Node.js 20.19.5**
- **Purpose**: Build-time asset compilation
- **Package Manager**: npm
- **Usage**: Build assets, not runtime (container not kept running)

**Key Dependencies**:
```json
{
  "vite": "^7.1.9",
  "laravel-vite-plugin": "^1.2.0",
  "tailwindcss": "^4.0",
  "autoprefixer": "^10.4.21",
  "postcss": "^8.4.49"
}
```

---

## Admin Panel

### Filament v4 (^4.11)

**Purpose**: Modern admin panel built on Laravel and Livewire

**Features Used**:
- Form Builder - Dynamic form creation
- Table Builder - Advanced data tables
- Notifications - Toast notifications
- Actions - Modal-based actions
- Widgets - Dashboard widgets
- Multi-tenancy ready
- Role-based access control integration

**Structure**:
```
app/Filament/
├── Resources/          # CRUD resources
├── Pages/             # Custom pages
├── Widgets/           # Dashboard widgets
└── ...
```

**Configuration**: `config/filament.php`

### Livewire 3.6.4

**Purpose**: Full-stack framework for dynamic interfaces

**Usage**:
- Powers all Filament components
- Real-time form validation
- Dynamic table updates
- No page reloads for interactions

**Configuration**: `config/livewire.php`

---

## Database Layer

### MySQL 8.0

**Container**: `registro-mysql`
- **Image**: `mysql:8.0`
- **Port**: per `docker-compose.prod.yml`, **not published to the host at all** — reachable only
  from other containers on the internal `registro-prod` bridge network. (Dev compose exposes
  3306 to `0.0.0.0` for local tooling; never use the dev compose file on the public VPS.)
- **Character Set**: `utf8mb4` / `utf8mb4_unicode_ci`
- **Storage**: Docker volume `registro_mysql-data`

**Configuration**:
```ini
[mysqld]
default-authentication-plugin=mysql_native_password
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
```

**Databases**:
- `registro` - Main application database

**Users**:
- `root` - Administrative access
- `registro` - Application user (limited privileges)

**Connection from Host**: not directly reachable from outside the Docker network (see Port note
above) — connect via `docker compose exec mysql mysql -u registro -p registro`, or an SSH tunnel
if external access is genuinely needed.

---

## Cache & Queue Layer

### Redis 7.2

**Container**: `registro-redis`
- **Image**: `redis:7.2-alpine`
- **Port**: per `docker-compose.prod.yml`, **not published to the host** — internal network only,
  same as MySQL above
- **Persistence**: Disabled (cache only)
- **Password**: `--requirepass` set via `REDIS_PASSWORD`; must be identical on `app`, `horizon`,
  and `scheduler` containers — a mismatch here was a real past incident (see
  `app/docs/deployment/known-issues.md`)

**Usage in Application**:
```
Cache Driver:     redis (config/cache.php)
Queue Driver:     redis (config/queue.php)
Session Driver:   redis (config/session.php)
```

**Redis Databases** (logical separation):
- DB 0: Cache
- DB 1: Sessions
- DB 2: Queues

**Connection from Host**: not directly reachable from outside the Docker network — use
`docker compose exec redis redis-cli -a <password>`.

---

## Web Server

### Nginx 1.25.5

**Container**: `registro-nginx`
- **Image**: `nginx:1.25-alpine`
- **Ports**: 80 (HTTP), 443 (HTTPS)
- **Configuration**: `docker/nginx/production/app.prod.conf` — note the actual path has a
  `production/` subdirectory; `docker-compose.prod.yml` currently mounts the wrong flat path
  (`docker/nginx/app.prod.conf`), a known bug tracked in
  `app/docs/deployment/production-readiness-checklist.md`

**Features**:
- Reverse proxy to PHP-FPM
- Static file serving (`public/`)
- Gzip compression
- Client request size limits (20M)
- FastCGI caching ready

**Configuration Highlights**:
```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;

    # PHP-FPM proxy
    location ~ \.php$ {
        fastcgi_pass registro-app:9000;
        # ... FastCGI params
    }

    # Static files
    location ~* \.(jpg|jpeg|gif|css|png|js|ico|svg)$ {
        expires max;
        access_log off;
    }
}
```

---

## Queue Management

### Laravel Horizon

**Container**: `registro-horizon`
- **Purpose**: Queue worker management and monitoring
- **Dashboard**: `/admin/horizon` (protected by auth)
- **Configuration**: `config/horizon.php`

**Features**:
- Real-time queue monitoring
- Job metrics and throughput
- Failed job management
- Worker supervision
- Auto-scaling workers

**Queues Configured**:
- `default` - General background jobs
- Additional queues can be added as needed

### Laravel Scheduler

**Container**: `registro-scheduler`
- **Purpose**: Cron job replacement
- **Schedule**: `app/Console/Kernel.php`
- **Runs**: `php artisan schedule:run` every minute

**Configured Tasks** (example):
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('horizon:snapshot')->everyFiveMinutes();
    // Add more scheduled tasks as needed
}
```

---

## Containerization

### Docker Engine

**Purpose**: Container runtime
- **Installation**: Docker CE on Ubuntu 24.04 (target VPS currently ships Docker CE 29.6.2 via
  the hosting provider's base image — confirmed empty of containers/volumes/images after reset)
- **Storage Driver**: overlay2
- **Logging Driver**: json-file

### Docker Compose

**Purpose**: Multi-container orchestration
- **Configuration**: `docker-compose.prod.yml`
- **Network**: `registro-prod` (bridge) — `mysql`/`redis` have no `ports:` published to the host
  on this network; only `nginx` exposes 80/443

**Container Architecture** (6 services, matches `docker-compose.prod.yml`):

```yaml
Services:
  app:          PHP-FPM application (internal only, no host port)
  nginx:        Web server (ports 80, 443 — the only host-published ports)
  mysql:        Database (internal only, no host port)
  redis:        Cache/Queue (internal only, no host port)
  horizon:      Queue worker
  scheduler:    Task scheduler
```

**Volumes** (named Docker volumes, not host bind-mounts, per `docker-compose.prod.yml`):
```yaml
mysql_data, redis_data:                          Database/cache persistent storage
app_public, storage-app-public, storage-app-private,
storage-framework, storage-logs:                 Shared Laravel storage/ across app+horizon+scheduler
```

---

## Server Infrastructure

### Operating System

**Ubuntu 24.04 LTS (Noble Numbat)**
- **Architecture**: x86_64
- **Target host**: `srv1342834.hstgr.cloud` / `76.13.76.104` — this is the VPS's own hostname for
  SSH/infra purposes, not the application domain (that's `registrolabs.com`, see
  `app/docs/deployment/domain-migration-registrolabs.md`). Originally a fresh reset (no prior
  Registro deployment); now the live production host.

### Firewall

**UFW (Uncomplicated Firewall)**
- **Status**: Active
- **Rules**:
  ```
  22/tcp   ALLOW   (SSH)
  80/tcp   ALLOW   (HTTP)
  443/tcp  ALLOW   (HTTPS)
  ```

**UFW-Docker Integration**
- **Purpose**: Prevent Docker from bypassing firewall rules (Docker writes its own `DOCKER-USER`
  iptables chain, which plain `ufw allow/deny` does not constrain)
- **Implementation**: `ufw-docker` helper script
- **See**: `app/docs/decisions/ADR-007-ufw-docker-security.md` — not yet installed on the target
  VPS; tracked in `production-readiness-checklist.md`

### System Services

**systemd Services**:
- `docker.service` - Docker daemon
- `ssh.service` - SSH server
- `ufw.service` - Firewall

**Monitoring**:
```bash
# System resources
htop
df -h
free -h

# Docker stats
docker stats

# Service status
systemctl status docker
docker-compose -f docker-compose.prod.yml ps
```

---

## Security

### Authentication & Authorization

**Laravel Sanctum**
- **Purpose**: API token authentication (ready, not actively used)
- **Configuration**: `config/sanctum.php`

**Filament Auth**
- **Provider**: Eloquent (User model)
- **Guards**: `web` (session-based)
- **Password Hashing**: Bcrypt (Laravel default)

**Admin Credentials**: created interactively during `deploy-init.sh`'s bootstrap step (or
manually via `php artisan make:filament-user`) — never hardcode or document a real admin
password in this file or anywhere else in the repo.

### Password Management

**System Passwords**:
- Real secrets live only in the untracked `.env` on the server (and, temporarily, in an
  encrypted local backup archive per `production-readiness-checklist.md` §3) — never committed,
  never written into a doc
- Generated using secure random strings (e.g. `openssl rand -base64 32`)
- Minimum 32 characters for service passwords

**Application Secrets**:
- `APP_KEY` - Laravel encryption key (base64:44 chars)
- `DB_PASSWORD` - MySQL user password
- `DB_ROOT_PASSWORD` - MySQL root password
- `REDIS_PASSWORD` - Redis authentication
- All stored in `.env` file (not committed)

### SSL/TLS

**Status**: Provisioned and live on UAT since 2026-08-08 — Let's Encrypt certificate covering
`registrolabs.com`, `www` and the tenant subdomain, renewed by cron. See
`app/docs/deployment/domain-migration-registrolabs.md`. Pattern is designed and
documented, just needs to be run against the real host once a domain is chosen.
- **Tool**: Certbot (`certonly`, standalone for first issuance, webroot for renewal)
- **Certificate**: Let's Encrypt, mounted read-only into the `nginx` container, with a
  `renewal-hooks/deploy/` script that restarts nginx + a systemd timer for auto-renewal
- **See**: `app/docs/decisions/ADR-014-ssl-https-configuration.md`

---

## Development Tools

### Package Manager

**Composer 2.x**
- **Purpose**: PHP dependency management
- **Configuration**: `composer.json`
- **Optimization**: `--optimize-autoloader --no-dev` for production

**Key Dependencies**:
```json
{
  "laravel/framework": "^12.60",
  "filament/filament": "^4.11",
  "livewire/livewire": "^3.8",
  "laravel/horizon": "^5.47",
  "laravel/sanctum": "^4.0"
}
```

### Code Quality (Development)

**Laravel Pint**
- **Purpose**: PHP code style fixer
- **Standard**: PSR-12 + Laravel conventions
- **Command**: `./vendor/bin/pint`

**PHPStan / Larastan** (if installed)
- **Purpose**: Static analysis
- **Level**: Configurable

---

## File Storage

### Application Storage

**Required Configuration**:
- **Driver**: `FILESYSTEM_DISK=public` — **never `local`**; `local` breaks Filament file uploads,
  per `.claude/rules/deployment.md` and `CLAUDE.md`
- **Root**: `storage/app`
- **Public Disk**: `storage/app/public` → symlinked to `public/storage`

**Permissions**:
```bash
Owner: laravel:laravel (1000:1000) — non-root container user, see
       app/docs/decisions/ADR-013-docker-user-model.md
Directories: 775
Files: 664
```

**S3-compatible storage**: supported and documented as optional in
`app/docs/deployment/environment-variables.md`; not required for the initial deploy.

### Build Artifacts

**Vite Build Output**:
- **Location**: `public/.vite/`
- **Manifest**: `public/.vite/manifest.json`
- **Symlink**: `public/build/manifest.json` → `.vite/manifest.json`

---

## Email Configuration

### Mail Driver

**Local/dev default**: `log` (emails written to `storage/logs/laravel.log`) / Mailpit
(`docker-compose.yml`, `docker-compose.staging.yml`)

**Production**: SMTP — no real provider decided yet for this deployment. A local, untracked
`.env.production` on this machine uses a Gmail App Password, but that credential should be
rotated and not reused as-is (see `production-readiness-checklist.md` §3); pick a real
transactional-mail provider before go-live rather than relying on Gmail SMTP long-term.
- **Env vars**: documented per-service in `app/docs/deployment/environment-variables.md`

---

## Timezone & Localization

**Application Timezone**: `Europe/Warsaw`
- **Configuration**: `config/app.php`
- **Database**: UTC (recommended practice)
- **Display**: Converted to Europe/Warsaw for users

**Locale**: `en` (English)
- **Fallback**: `en`
- **Available**: Expandable via `resources/lang/`

---

## Monitoring & Logging

### Application Logs

**Location**: `storage/logs/laravel.log`
- **Format**: Daily rotation
- **Channels**: stack (single, daily)
- **Level**: Configurable in `.env` (LOG_LEVEL)

**View Logs**:
```bash
# Application container logs
docker-compose -f docker-compose.prod.yml logs -f app

# Laravel log file
tail -f storage/logs/laravel.log

# Nginx access/error logs
docker-compose -f docker-compose.prod.yml logs -f nginx
```

### Queue Monitoring

**Laravel Horizon**:
- **URL**: `/admin/horizon`
- **Requires**: Authentication
- **Features**: Real-time metrics, failed jobs, worker stats

### System Monitoring

**Tools Available**:
- `htop` - Process monitoring
- `docker stats` - Container resource usage
- `df -h` - Disk usage
- `free -h` - Memory usage

**Recommended** (not installed):
- Laravel Telescope (development)
- Sentry (error tracking)
- New Relic / Datadog (APM)

---

## Backup Strategy

**Status**: Not implemented on the target VPS yet (fresh host)

**Recommended Approach**:
- Daily `pg_dump`-equivalent for MySQL (`mysqldump` or `docker exec ... mysqldump`) + `gzip`
- Weekly full backups (DB + `.env` + any config drift)
- Offsite storage (download to a machine outside the VPS — see the historical lesson in
  `.claude/rules/ci-cd-troubleshooting.md` about a cron backup silently dying for 44 days
  unnoticed on a different project's VPS; verify the cron actually still writes files, don't just
  trust that it's configured)

---

## Version Information

### Versions (as of 2026-07-30, per `composer.json`/`Dockerfile`/`docker-compose.prod.yml` — no
production instance exists yet to read live versions from)

| Component | Version | Container/Location |
|-----------|---------|-------------------|
| Laravel | ^12.60 | `registro-app` |
| PHP | 8.3 | `registro-app` |
| MySQL | 8.0 | `registro-mysql` |
| Redis | 7.2 | `registro-redis` |
| Nginx | 1.25-alpine | `registro-nginx` |
| Node.js | 20 (alpine) | Build-time only |
| Filament | ^4.11 | Application |
| Livewire | ^3.8 | Application |
| Tailwind CSS | 4.0 | Build-time only |
| Ubuntu | 24.04 LTS | Target VPS host OS |

### Version Management

**PHP Dependencies**: Managed by `composer.lock`
**JS Dependencies**: Managed by `package-lock.json`
**System Packages**: Managed by apt (Ubuntu)

**Update Strategy**:
- Minor updates: Quarterly
- Security patches: Immediate
- Major upgrades: Planned with testing

---

## Performance Optimization

### Application Level

**Caching**:
- Route cache: `php artisan route:cache`
- Config cache: `php artisan config:cache`
- View cache: `php artisan view:cache`
- OPcache: Enabled in PHP

**Database**:
- Indexed columns (check migrations)
- Query optimization via Eloquent
- Connection pooling

**Queue System**:
- Offload long-running tasks
- Horizon for monitoring and optimization

### Server Level

**PHP-FPM**:
- Process manager: dynamic
- Max children: Tuned for 2GB RAM
- Request termination: 300s

**Nginx**:
- Gzip compression enabled
- Static file caching
- FastCGI caching (ready)

**Redis**:
- In-memory caching
- No persistence (faster)

---

## Related Documentation

- **Production readiness / open gaps**: `app/docs/deployment/production-readiness-checklist.md`
- **Past deployment incidents**: `app/docs/deployment/known-issues.md`,
  `app/docs/deployment/deployment-history.md`
- **Environment variables per service**: `app/docs/deployment/environment-variables.md`
- **Multi-tenancy / panel architecture**: `app/docs/guides/multi-tenancy-architecture.md`,
  `app/docs/architecture/panel-isolation.md`, `app/docs/architecture/data-isolation.md`
- **Architecture Decisions**: `app/docs/decisions/`
- **Security posture**: `app/docs/security/`

*(The `docs/environments/staging/` and `decision_log/` paths previously linked here belonged to
the decommissioned predecessor server and have moved to `docs/archive/`.)*

---

**Document Owner**: Development Team
**Last Review**: 2026-08-08
**Next Review**: quarterly. The first production deploy (to `srv1342834.hstgr.cloud` as an
interim host) has already happened; the application now runs on `registrolabs.com`.
