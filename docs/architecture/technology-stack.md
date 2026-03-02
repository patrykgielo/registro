# Technology Stack

This document provides a comprehensive overview of all technologies used in the Registro project.

**Last Updated**: 2025-11-11
**Environment**: Staging VPS (72.60.17.138)

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

**PHP 8.2.29 with PHP-FPM**
- **Container**: `registro-app`
- **Base Image**: `php:8.2-fpm-alpine`
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

### Filament v4.2.3

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
- **Port**: 3306 (exposed for external tools)
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

**Connection from Host**:
```bash
mysql -h 72.60.17.138 -u registro -p registro
```

---

## Cache & Queue Layer

### Redis 7.2

**Container**: `registro-redis`
- **Image**: `redis:7.2-alpine`
- **Port**: 6379 (exposed)
- **Persistence**: Disabled (cache only)
- **Password**: Protected with strong password

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

**Connection from Host**:
```bash
redis-cli -h 72.60.17.138 -a <password>
```

---

## Web Server

### Nginx 1.25.5

**Container**: `registro-nginx`
- **Image**: `nginx:1.25-alpine`
- **Ports**: 80 (HTTP), 443 (HTTPS - ready, not configured)
- **Configuration**: `docker/nginx/app.prod.conf`

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

### Docker Engine 29.0.0

**Purpose**: Container runtime
- **Installation**: Docker CE on Ubuntu 24.04
- **Storage Driver**: overlay2
- **Logging Driver**: json-file

### Docker Compose 2.40.3

**Purpose**: Multi-container orchestration
- **Configuration**: `docker-compose.prod.yml`
- **Network**: `registro_network` (bridge)

**Container Architecture**:

```yaml
Services:
  app:          PHP-FPM application (port 9000)
  nginx:        Web server (ports 80, 443)
  mysql:        Database (port 3306)
  redis:        Cache/Queue (port 6379)
  horizon:      Queue worker
  scheduler:    Task scheduler
```

**Volumes**:
```yaml
registro_mysql-data:  MySQL persistent storage
Application Code:      Bind mount from /var/www/registro
```

---

## Server Infrastructure

### Operating System

**Ubuntu 24.04 LTS (Noble Numbat)**
- **Kernel**: Linux 6.14.0-1015-oem (custom)
- **Architecture**: x86_64
- **Memory**: 2GB RAM + 2GB Swap
- **Hostname**: registro.local (old: srv1117368.hstgr.cloud)
- **IP Address**: 72.60.17.138

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
- **Purpose**: Prevent Docker from bypassing firewall rules
- **Implementation**: Custom ufw-docker script
- **See**: [ADR-001-ufw-docker-security.md](decision_log/ADR-001-ufw-docker-security.md)

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

**Admin Credentials** (Temporary):
- Email: admin@registro.com
- Password: Admin123! (MUST BE CHANGED)

### Password Management

**System Passwords**:
- All passwords stored in `docs/environments/staging/03-CREDENTIALS.md`
- Generated using secure random strings
- Minimum 32 characters for service passwords

**Application Secrets**:
- `APP_KEY` - Laravel encryption key (base64:44 chars)
- `DB_PASSWORD` - MySQL user password
- `DB_ROOT_PASSWORD` - MySQL root password
- `REDIS_PASSWORD` - Redis authentication
- All stored in `.env` file (not committed)

### SSL/TLS

**Status**: Not configured (pending)
- **Tool**: Certbot (installed)
- **Certificate**: Let's Encrypt
- **See**: [07-NEXT-STEPS.md](../environments/staging/07-NEXT-STEPS.md)

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
  "laravel/framework": "^12.0",
  "filament/filament": "^3.0",
  "livewire/livewire": "^3.0",
  "laravel/horizon": "^5.0",
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

**Current Configuration**:
- **Driver**: `local` (filesystem)
- **Root**: `storage/app`
- **Public Disk**: `storage/app/public` → symlinked to `public/storage`

**Permissions**:
```bash
Owner: ubuntu:ubuntu (1000:1000)
Directories: 775
Files: 664
```

**Future Consideration**: AWS S3 or similar for scalability

### Build Artifacts

**Vite Build Output**:
- **Location**: `public/.vite/`
- **Manifest**: `public/.vite/manifest.json`
- **Symlink**: `public/build/manifest.json` → `.vite/manifest.json`
- **See**: [ADR-003-vite-manifest-symlink.md](decision_log/ADR-003-vite-manifest-symlink.md)

---

## Email Configuration

### Mail Driver

**Current**: `log` (emails written to `storage/logs/laravel.log`)

**Pending**: Gmail SMTP
- **Configuration**: Requires Gmail App Password
- **See**: [07-NEXT-STEPS.md](../environments/staging/07-NEXT-STEPS.md)

**Future Configuration** (example):
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@registro.com
MAIL_FROM_NAME="Registro"
```

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

**Status**: Not implemented (pending)

**Recommended Approach**:
- Daily MySQL dumps
- Weekly full backups
- Offsite storage (S3, Backblaze)
- **See**: [07-NEXT-STEPS.md](../environments/staging/07-NEXT-STEPS.md)

---

## Version Information

### Production Versions (as of 2025-11-11)

| Component | Version | Container/Location |
|-----------|---------|-------------------|
| Laravel | 12.32.5 | `registro-app` |
| PHP | 8.2.29 | `registro-app` |
| MySQL | 8.0 | `registro-mysql` |
| Redis | 7.2 | `registro-redis` |
| Nginx | 1.25.5 | `registro-nginx` |
| Node.js | 20.19.5 | Build-time only |
| Vite | 7.1.9 | Build-time only |
| Filament | 4.2.3 | Application |
| Livewire | 3.6.4 | Application |
| Tailwind CSS | 4.0 | Build-time only |
| Docker | 29.0.0 | Host system |
| Docker Compose | 2.40.3 | Host system |
| Ubuntu | 24.04 LTS | Host OS |

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

- **Deployment Process**: [01-DEPLOYMENT-LOG.md](../environments/staging/01-DEPLOYMENT-LOG.md)
- **Configuration Details**: [02-CONFIGURATIONS.md](../environments/staging/02-CONFIGURATIONS.md)
- **Service Management**: [04-SERVICES.md](../environments/staging/04-SERVICES.md)
- **Architecture Decisions**: [decision_log/README.md](decision_log/README.md)

---

**Document Owner**: Development Team
**Last Review**: 2025-11-11
**Next Review**: 2025-12-11 (quarterly)
