# Environment Variables Reference

**Last Updated:** 2025-11-30
**Version:** v0.2.11
**Audience:** DevOps, Backend Developers

---

## Overview

This document provides a comprehensive reference for ALL environment variables used in the Registro Laravel application across development, staging, and production environments.

**Critical Concept:**
Environment variables in Docker Compose have a strict hierarchy. Variables MUST be explicitly defined in `docker-compose.yml` environment section to be available in containers - being in `.env` file alone is NOT sufficient.

---

## Table of Contents

1. [Docker Environment Variable Hierarchy](#docker-environment-variable-hierarchy)
2. [Required Variables by Service](#required-variables-by-service)
3. [Application Environment Variables](#application-environment-variables)
4. [Database Environment Variables](#database-environment-variables)
5. [Redis & Queue Environment Variables](#redis--queue-environment-variables)
6. [Email Configuration](#email-configuration)
7. [External Services](#external-services)
8. [Build-time Variables](#build-time-variables)
9. [Production Configuration Template](#production-configuration-template)
10. [Validation & Troubleshooting](#validation--troubleshooting)

---

## Docker Environment Variable Hierarchy

**CRITICAL:** Understanding Docker Compose environment variable precedence is essential. This pattern was the root cause of deployment failures in v0.2.5-v0.2.7.

### Precedence Order (Highest to Lowest)

```
1. docker-compose.yml environment: section  ← HIGHEST PRIORITY
2. docker-compose.yml env_file: .env
3. Host shell environment
4. Docker image ENV directives
```

### The Critical Pattern

```yaml
# ❌ WRONG - Variable will NOT be available in container
services:
  app:
    env_file: .env
    # APP_KEY only in .env file

# ✅ CORRECT - Variable explicitly passed to container
services:
  app:
    env_file: .env
    environment:
      - APP_KEY=${APP_KEY}  # Must be explicit!
```

### Why This Matters

**.env file:**
```bash
APP_KEY=base64:abc123...
DB_CONNECTION=mysql
```

**docker-compose.yml WITHOUT explicit environment:**
```yaml
services:
  app:
    env_file: .env
    # Variables from .env are NOT automatically passed to container
```

**Result:** Container does NOT have `APP_KEY` or `DB_CONNECTION` set!

**docker-compose.yml WITH explicit environment:**
```yaml
services:
  app:
    env_file: .env
    environment:
      - APP_KEY=${APP_KEY}        # Reads from .env, passes to container
      - DB_CONNECTION=mysql        # Explicit value, passes to container
```

**Result:** Container HAS both variables set correctly!

---

## Required Variables by Service

### app Service (PHP-FPM)

**MUST HAVE (Application will fail without these):**
```yaml
environment:
  - APP_ENV=production
  - APP_DEBUG=false
  - APP_KEY=${APP_KEY}           # ← CRITICAL: Encryption, sessions
  - DB_CONNECTION=mysql           # ← CRITICAL: Must be explicit (not just in .env)
  - DB_HOST=mysql                 # ← Use service name, not localhost
  - DB_PORT=3306
  - DB_DATABASE=${DB_DATABASE}
  - DB_USERNAME=${DB_USERNAME}
  - DB_PASSWORD=${DB_PASSWORD}
  - REDIS_HOST=redis              # ← Use service name
  - REDIS_PASSWORD=${REDIS_PASSWORD}  # ← CRITICAL: Required for auth
  - QUEUE_CONNECTION=redis
  - CACHE_DRIVER=redis
  - SESSION_DRIVER=redis
```

**WHY These Are Critical:**
- `APP_KEY`: Laravel encryption, sessions, password resets (500 errors without it)
- `DB_CONNECTION=mysql`: Prevents SQLite fallback (database/database.sqlite errors)
- `REDIS_PASSWORD`: Redis requires auth (NOAUTH errors without it)

---

### horizon Service (Queue Worker)

**MUST HAVE (Same as app service):**
```yaml
environment:
  - APP_ENV=production
  - APP_KEY=${APP_KEY}           # ← CRITICAL: Must match app service
  - DB_CONNECTION=mysql           # ← CRITICAL: Horizon queries database
  - DB_HOST=mysql
  - DB_PORT=3306
  - DB_DATABASE=${DB_DATABASE}
  - DB_USERNAME=${DB_USERNAME}
  - DB_PASSWORD=${DB_PASSWORD}
  - REDIS_HOST=redis
  - REDIS_PASSWORD=${REDIS_PASSWORD}  # ← CRITICAL: Horizon uses Redis
```

**WHY Horizon Needs These:**
- `APP_KEY`: Decrypting queued jobs (may contain encrypted data)
- `DB_CONNECTION`: Horizon stores supervisor state, failed jobs in MySQL
- `REDIS_PASSWORD`: All queue operations use Redis

**Common Mistake:**
Assuming Horizon only needs Redis variables. It needs FULL database config because it logs failed jobs to MySQL.

---

### scheduler Service (Cron Jobs)

**MUST HAVE (Same as app and horizon):**
```yaml
environment:
  - APP_ENV=production
  - APP_KEY=${APP_KEY}           # ← CRITICAL: Scheduled jobs may encrypt/decrypt
  - DB_CONNECTION=mysql           # ← CRITICAL: Scheduled commands query database
  - DB_HOST=mysql
  - DB_PORT=3306
  - DB_DATABASE=${DB_DATABASE}
  - DB_USERNAME=${DB_USERNAME}
  - DB_PASSWORD=${DB_PASSWORD}
  - REDIS_HOST=redis
  - REDIS_PASSWORD=${REDIS_PASSWORD}
```

**WHY Scheduler Needs These:**
Scheduled commands (`schedule:run`) execute artisan commands that may:
- Query database (email reminders, cleanup tasks)
- Queue jobs (which need Redis)
- Encrypt/decrypt data (need APP_KEY)

---

### mysql Service

**MUST HAVE:**
```yaml
environment:
  MYSQL_DATABASE: ${DB_DATABASE:-registro}
  MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-CHANGE_ME_STRONG_ROOT_PASSWORD}
  MYSQL_PASSWORD: ${DB_PASSWORD:-CHANGE_ME_STRONG_PASSWORD}
  MYSQL_USER: ${DB_USERNAME:-registro}
```

**Performance Tuning (Optional):**
```yaml
environment:
  MYSQL_INNODB_BUFFER_POOL_SIZE: 256M
  MYSQL_INNODB_LOG_FILE_SIZE: 64M
```

---

### redis Service

**MUST HAVE:**
```yaml
command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD:-CHANGE_ME_REDIS_PASSWORD}
```

**Note:** Redis password is passed via `command`, not `environment`.

---

### nginx Service

**Optional (no environment variables required):**
- Configuration via mounted config files only
- Healthcheck uses internal http://127.0.0.1/up endpoint

---

## Application Environment Variables

### Core Application

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_NAME` | No | Laravel | Application name (used in emails, titles) |
| `APP_ENV` | Yes | production | Environment: local, staging, production |
| `APP_KEY` | **YES** | - | **Encryption key (CRITICAL)** |
| `APP_DEBUG` | Yes | false | Debug mode (NEVER true in production) |
| `APP_URL` | Yes | http://localhost | Base URL for asset generation |
| `APP_TIMEZONE` | No | UTC | Application timezone (Europe/Warsaw) |
| `APP_LOCALE` | No | en | UI locale — `pl` for this app |
| `APP_FALLBACK_LOCALE` | **YES** | en | **Must be `en`, never `pl` — see below** |

**⚠️ `APP_FALLBACK_LOCALE` MUST be `en`:**

There is no `lang/pl/validation.php` anywhere in this codebase — neither app-level nor in
`vendor/laravel/framework` (the framework only ships its own `en/validation.php` as a built-in
fallback; Filament's own `vendor/filament/forms/resources/lang/pl/validation.php` only overrides
two custom keys, `distinct` and `tampered_file_path`, not the standard rules like `unique`,
`required`, `max`). With `APP_LOCALE=pl` and `APP_FALLBACK_LOCALE=pl`, any validation rule that
falls through to Laravel's own `validation.*` message set (not one of Filament's PL-translated
keys) has **nothing to fall back to** and Laravel's translator returns the raw key —
`trans('validation.unique')` literally renders as the string `"validation.unique"` in the UI
instead of readable text. Setting `APP_FALLBACK_LOCALE=en` at least resolves through the
framework's built-in `en/validation.php`, so the same case renders as `"The slug has already been
taken."` — not translated to Polish, but readable. `.env.example` and `.env.staging.example` have
always had this right; `.env.testing`, `.env.production.example`, and `.env.local.example` were
wrong since the file each was introduced (confirmed via `git log -p`, not a regression from a
later edit) — fixed 2026-08-27 (`fix/location-slug-unique-per-tenant`). **If a real, deployed
`.env`/`.env.production` on a VPS was provisioned from `.env.production.example` before that date,
it likely still has `APP_FALLBACK_LOCALE=pl` and needs the same one-line fix applied by hand.**

A proper `lang/pl/validation.php` (translating Laravel's ~90 default validation messages into
Polish) would be the complete fix for a fully-Polish UI, but is a separate, larger piece of work —
not implemented as part of this hotfix.

### Logging

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `LOG_CHANNEL` | No | stack | Log channel: stack, single, daily |
| `LOG_LEVEL` | No | debug | Minimum log level: debug, info, warning, error |
| `LOG_DEPRECATIONS_CHANNEL` | No | null | Separate channel for deprecation warnings |

### Security

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `BCRYPT_ROUNDS` | No | 12 | Password hashing rounds (higher = slower but safer) |
| `SESSION_LIFETIME` | No | 120 | Session lifetime in minutes |
| `SESSION_ENCRYPT` | No | false | Encrypt session data (not needed with HTTPS) |

### File Storage

| Variable | Required | Default | Production Value | Description |
|----------|----------|---------|------------------|-------------|
| `FILESYSTEM_DISK` | **YES** | local | **public** | **CRITICAL: Storage disk for file uploads** |

**⚠️ CRITICAL CONFIGURATION:**

`FILESYSTEM_DISK` determines where uploaded files (images, documents) are stored. **This setting MUST be `public` for both local AND production environments.**

**Valid Options:**
- **`public`** (REQUIRED):
  - Files stored in `storage/app/public`
  - Accessible via `/storage` URL (requires `php artisan storage:link`)
  - **USE THIS FOR ALL ENVIRONMENTS**

- **`local`** (NEVER use):
  - Files stored in `storage/app/private`
  - NOT publicly accessible
  - **Causes file upload failures in Filament admin panel**
  - **DO NOT USE THIS**

**Common Production Error:**

Setting `FILESYSTEM_DISK=local` on production breaks ALL file uploads:
- CMS image uploads (Pages, Posts, Promotions, Portfolio)
- Filament Builder blocks (Hero Section backgrounds, Content Grid images)
- User profile avatars
- All Filament forms with file upload fields

**Error Message:**
```
The data.content.xxx.background_image.xxx failed to upload.
```

**Root Cause:**
Files uploaded to `storage/app/private` (local disk) cannot be accessed via public URLs.

**Fix:**
```bash
# On production server
sed -i 's/FILESYSTEM_DISK=local/FILESYSTEM_DISK=public/' .env
docker compose -f docker-compose.prod.yml restart app horizon
docker compose -f docker-compose.prod.yml exec -T app php artisan config:clear
```

**Deployment Checklist:**
- ✅ Verify `.env` has `FILESYSTEM_DISK=public`
- ✅ Run `php artisan storage:link` after deployment
- ✅ Test file upload in Filament admin panel

---

## Database Environment Variables

### MySQL Connection

| Variable | Required | Default | Production Value |
|----------|----------|---------|------------------|
| `DB_CONNECTION` | **YES** | sqlite | **mysql** (MUST be explicit!) |
| `DB_HOST` | Yes | 127.0.0.1 | **mysql** (service name) |
| `DB_PORT` | Yes | 3306 | 3306 |
| `DB_DATABASE` | Yes | laravel | registro |
| `DB_USERNAME` | Yes | root | registro |
| `DB_PASSWORD` | **YES** | - | **Strong password** |

### MySQL Root Credentials (for migrations, backups)

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `DB_ROOT_PASSWORD` | Recommended | - | MySQL root password (for mysqldump backups) |
| `DB_ROOT_USERNAME` | No | root | MySQL root username (usually 'root') |

### Critical Concepts

**DB_CONNECTION Pattern:**
```yaml
# ❌ WRONG - Laravel will use SQLite (default)
environment:
  - DB_HOST=mysql
  - DB_DATABASE=registro
  # Missing DB_CONNECTION!

# ✅ CORRECT - Laravel will use MySQL
environment:
  - DB_CONNECTION=mysql  # ← MUST BE EXPLICIT
  - DB_HOST=mysql
  - DB_DATABASE=registro
```

**DB_HOST Pattern:**
```yaml
# ❌ WRONG - Won't connect in Docker network
environment:
  - DB_HOST=127.0.0.1  # Localhost inside container
  - DB_HOST=localhost   # Localhost inside container

# ✅ CORRECT - Uses Docker service name
environment:
  - DB_HOST=mysql  # Docker Compose service name
```

---

## Redis & Queue Environment Variables

### Redis Connection

| Variable | Required | Default | Production Value |
|----------|----------|---------|------------------|
| `REDIS_CLIENT` | Recommended | predis | **phpredis** (C extension, 5x faster) |
| `REDIS_HOST` | Yes | 127.0.0.1 | **redis** (service name) |
| `REDIS_PASSWORD` | **YES** | null | **Strong password** |
| `REDIS_PORT` | No | 6379 | 6379 |

### Queue Configuration

| Variable | Required | Default | Production Value |
|----------|----------|---------|------------------|
| `QUEUE_CONNECTION` | Yes | sync | **redis** |
| `REDIS_QUEUE` | No | default | default |

### Cache Configuration

| Variable | Required | Default | Production Value |
|----------|----------|---------|------------------|
| `CACHE_DRIVER` | Yes | file | **redis** |
| `CACHE_PREFIX` | No | - | Optional cache key prefix |

### Session Configuration

| Variable | Required | Default | Production Value |
|----------|----------|---------|------------------|
| `SESSION_DRIVER` | Yes | file | **redis** |
| `SESSION_PATH` | No | / | / |
| `SESSION_DOMAIN` | No | null | Your domain (for subdomain support) |

### Critical Concepts

**REDIS_PASSWORD Pattern:**
```yaml
# ❌ WRONG - Redis won't accept connections
services:
  redis:
    command: redis-server --requirepass yourpassword

  app:
    environment:
      - REDIS_HOST=redis
      # Missing REDIS_PASSWORD!

# ✅ CORRECT - Password set in both places
services:
  redis:
    command: redis-server --requirepass ${REDIS_PASSWORD}

  app:
    environment:
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}  # ← MUST MATCH
```

**phpredis vs predis:**
```bash
# ❌ SLOWER - Pure PHP implementation
REDIS_CLIENT=predis
# Requires: composer require predis/predis

# ✅ FASTER - C extension (5x performance)
REDIS_CLIENT=phpredis
# Requires: pecl install redis (in Dockerfile)
```

---

## Email Configuration

### SMTP Settings

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `MAIL_MAILER` | Yes | smtp | Mail driver: smtp, sendmail, log |
| `MAIL_HOST` | Yes | 127.0.0.1 | SMTP server (e.g., smtp.gmail.com) |
| `MAIL_PORT` | Yes | 25 | SMTP port (587 for TLS, 465 for SSL) |
| `MAIL_USERNAME` | Conditional | - | SMTP username (required for Gmail, etc.) |
| `MAIL_PASSWORD` | Conditional | - | SMTP password (App Password for Gmail) |
| `MAIL_ENCRYPTION` | Recommended | null | Encryption: tls, ssl, or null |
| `MAIL_FROM_ADDRESS` | Yes | - | Default sender email |
| `MAIL_FROM_NAME` | Yes | ${APP_NAME} | Default sender name |

### Production Example (Gmail)

```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@registro.local
MAIL_FROM_NAME="Registro System"
```

**Note:** Gmail requires App Passwords (not regular password). Generate at: https://myaccount.google.com/apppasswords

---

## External Services

### Google Maps API

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `GOOGLE_MAPS_API_KEY` | **YES** | - | API key from Google Cloud Console |
| `GOOGLE_MAPS_MAP_ID` | **YES** | - | Map ID (required for Maps v3.56+) |

**Setup Instructions:**
1. Create API key at: https://console.cloud.google.com/google/maps-apis/
2. Enable APIs: Maps JavaScript API, Places API
3. Apply HTTP referrer restrictions for security
4. Create Map ID at: Maps Studio → Map IDs → CREATE MAP ID

### AWS S3 (Optional - for file uploads)

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | Conditional | - | AWS IAM access key |
| `AWS_SECRET_ACCESS_KEY` | Conditional | - | AWS IAM secret key |
| `AWS_DEFAULT_REGION` | No | us-east-1 | S3 region |
| `AWS_BUCKET` | Conditional | - | S3 bucket name |
| `AWS_USE_PATH_STYLE_ENDPOINT` | No | false | Use path-style URLs (for Minio, etc.) |

---

## Build-time Variables

### Docker Build Arguments

These are passed during `docker build`, not at runtime:

| Variable | Default | Description |
|----------|---------|-------------|
| `USER_ID` | 1000 | UID for laravel user (must match VPS file ownership) |
| `GROUP_ID` | 1000 | GID for laravel user |

**Usage in docker-compose.yml:**
```yaml
services:
  app:
    build:
      args:
        USER_ID: ${DOCKER_USER_ID:-1000}
        GROUP_ID: ${DOCKER_GROUP_ID:-1000}
```

**Usage in deployment:**
```bash
# Detect from VPS file ownership
export DOCKER_USER_ID=$(stat -c '%u' /var/www/registro/storage)
export DOCKER_GROUP_ID=$(stat -c '%g' /var/www/registro/storage)

# Build with correct UID
docker compose build app
```

---

## Production Configuration Template

### .env File (Production)

```bash
#############################################################################
# Application
#############################################################################
APP_NAME=Registro
APP_ENV=production
APP_KEY=base64:GENERATE_WITH_php_artisan_key:generate
APP_DEBUG=false
APP_URL=https://registro.local
APP_TIMEZONE=Europe/Warsaw

#############################################################################
# Database (MySQL in Docker)
#############################################################################
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=registro
DB_USERNAME=registro
DB_PASSWORD=CHANGE_TO_STRONG_PASSWORD_32_CHARS_MIN

# For backups and migrations
DB_ROOT_PASSWORD=CHANGE_TO_STRONG_ROOT_PASSWORD_64_CHARS_MIN

#############################################################################
# Redis (Cache, Queue, Sessions)
#############################################################################
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=CHANGE_TO_STRONG_REDIS_PASSWORD_32_CHARS_MIN
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

#############################################################################
# File Storage (CRITICAL!)
#############################################################################
# ⚠️ MUST BE 'public' FOR PRODUCTION (enables file uploads in Filament)
# NEVER set to 'local' - breaks all file uploads!
FILESYSTEM_DISK=public
BROADCAST_CONNECTION=log

#############################################################################
# Email (Gmail SMTP)
#############################################################################
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password-16-chars
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@registro.local
MAIL_FROM_NAME="${APP_NAME}"

#############################################################################
# Google Maps
#############################################################################
GOOGLE_MAPS_API_KEY=AIzaSy...your-api-key
GOOGLE_MAPS_MAP_ID=your-map-id

#############################################################################
# Logging
#############################################################################
LOG_CHANNEL=stack
LOG_LEVEL=error
LOG_DEPRECATIONS_CHANNEL=null

#############################################################################
# Security
#############################################################################
BCRYPT_ROUNDS=12
SESSION_ENCRYPT=false
```

### docker-compose.prod.yml (Critical Sections)

```yaml
services:
  app:
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=${APP_KEY}                    # ← CRITICAL
      - DB_CONNECTION=mysql                    # ← CRITICAL
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}      # ← CRITICAL
      - QUEUE_CONNECTION=redis
      - CACHE_DRIVER=redis
      - SESSION_DRIVER=redis

  horizon:
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}                    # ← CRITICAL
      - DB_CONNECTION=mysql                    # ← CRITICAL
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}      # ← CRITICAL

  scheduler:
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}                    # ← CRITICAL
      - DB_CONNECTION=mysql                    # ← CRITICAL
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}      # ← CRITICAL
```

---

## Validation & Troubleshooting

### Pre-Deployment Checklist

**1. Check .env file exists:**
```bash
ls -la .env
# Should exist and be readable
```

**2. Validate critical variables in .env:**
```bash
grep -E "^(APP_KEY|DB_CONNECTION|REDIS_PASSWORD)=" .env
# Should show all three variables with values
```

**3. Verify docker-compose.yml has explicit environment:**
```bash
grep -A 20 "app:" docker-compose.prod.yml | grep -E "(APP_KEY|DB_CONNECTION|REDIS_PASSWORD)"
# Should show all three in environment: section
```

**4. Generate APP_KEY if missing:**
```bash
php artisan key:generate
# Updates .env with new APP_KEY
```

**5. Generate strong passwords:**
```bash
# DB_PASSWORD
openssl rand -base64 32

# DB_ROOT_PASSWORD
openssl rand -base64 64

# REDIS_PASSWORD
openssl rand -base64 32
```

---

### Runtime Verification

**1. Check variables in running container:**
```bash
# Verify APP_KEY is set
docker compose exec app printenv APP_KEY
# Should output: base64:...

# Verify DB_CONNECTION is set
docker compose exec app printenv DB_CONNECTION
# Should output: mysql

# Verify REDIS_PASSWORD is set
docker compose exec app printenv REDIS_PASSWORD
# Should output: your-password (NOT null or empty)
```

**2. Verify all critical variables:**
```bash
# Check in app container
docker compose exec app sh -c 'env | grep -E "^(APP_KEY|DB_CONNECTION|REDIS_PASSWORD)=" | sort'

# Check in horizon container
docker compose exec horizon sh -c 'env | grep -E "^(APP_KEY|DB_CONNECTION|REDIS_PASSWORD)=" | sort'

# Check in scheduler container
docker compose exec scheduler sh -c 'env | grep -E "^(APP_KEY|DB_CONNECTION|REDIS_PASSWORD)=" | sort'
```

**3. Test database connection:**
```bash
docker compose exec app php artisan tinker
# Inside tinker:
DB::connection()->getPDO();
# Should return PDO object, not error
```

**4. Test Redis connection:**
```bash
docker compose exec app php artisan tinker
# Inside tinker:
Redis::ping();
# Should return: "+PONG"
```

---

### Common Errors & Solutions

#### Error: "No application encryption key has been specified"

**Symptom:**
```
RuntimeException: No application encryption key has been specified.
```

**Diagnosis:**
```bash
docker compose exec app printenv APP_KEY
# If shows nothing or "NOT_SET"
```

**Solution:**
1. Add to .env: `APP_KEY=base64:...`
2. Add to docker-compose.yml: `- APP_KEY=${APP_KEY}`
3. Restart containers: `docker compose up -d`

---

#### Error: "SQLSTATE[HY000] [2002] No such file or directory"

**Symptom:**
```
SQLSTATE[HY000] [2002] No such file or directory
```

**Diagnosis:**
```bash
docker compose exec app printenv DB_CONNECTION
# Shows: sqlite or empty

docker compose exec app printenv DB_HOST
# Shows: 127.0.0.1 or localhost (WRONG)
```

**Solution:**
1. Set DB_CONNECTION=mysql in docker-compose.yml
2. Set DB_HOST=mysql in docker-compose.yml (service name, not localhost)
3. Restart containers: `docker compose up -d`

---

#### Error: "NOAUTH Authentication required"

**Symptom:**
```
ERR NOAUTH Authentication required
```

**Diagnosis:**
```bash
docker compose exec app printenv REDIS_PASSWORD
# Shows: null or empty
```

**Solution:**
1. Add to .env: `REDIS_PASSWORD=your-strong-password`
2. Add to docker-compose.yml (ALL services): `- REDIS_PASSWORD=${REDIS_PASSWORD}`
3. Update redis command: `--requirepass ${REDIS_PASSWORD}`
4. Restart containers: `docker compose up -d`

---

#### Error: "Class 'Redis' not found"

**Symptom:**
```
Error: Class "Redis" not found
```

**Diagnosis:**
```bash
docker compose exec app php -m | grep -i redis
# If shows nothing, phpredis extension not installed
```

**Solution:**
1. Add to Dockerfile:
   ```dockerfile
   RUN pecl install redis && docker-php-ext-enable redis
   ```
2. Rebuild image: `docker compose build app`
3. Restart containers: `docker compose up -d`

---

## Security Best Practices

### Password Generation

**DO:**
- Use cryptographically secure random passwords
- Minimum 32 characters for application passwords
- Minimum 64 characters for root passwords
- Different password for each service

**DON'T:**
- Use default passwords from examples
- Use simple/predictable passwords
- Reuse passwords across services
- Store passwords in git (use .env, gitignored)

**Example:**
```bash
# Generate strong passwords
openssl rand -base64 32  # For DB_PASSWORD, REDIS_PASSWORD
openssl rand -base64 64  # For DB_ROOT_PASSWORD
```

### Environment File Security

**.env file permissions:**
```bash
chmod 600 .env
# Only owner can read/write
```

**.gitignore:**
```bash
.env
.env.production
.env.staging
# Never commit .env files
```

**Secrets management:**
- Use GitHub Secrets for CI/CD
- Use VPS environment variables for production
- Never hardcode secrets in code or docker-compose.yml

---

## References

- [Deployment History](deployment-history.md) - Issues caused by environment variables
- [Docker Infrastructure](docker-infrastructure.md) - How services communicate
- [Known Issues](known-issues.md) - Environment variable troubleshooting
- [Laravel Configuration Documentation](https://laravel.com/docs/11.x/configuration)
- [Docker Compose Environment Variables](https://docs.docker.com/compose/environment-variables/)

---

**Document Version:** 1.0
**Last Updated:** 2025-11-30
**Maintained By:** Development Team
