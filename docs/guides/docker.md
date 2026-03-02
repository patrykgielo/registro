# Docker Development Guide

**Last Updated:** November 2025

## Overview

This application runs in Docker containers for consistent development and production environments. Docker Compose orchestrates 8 services that work together to provide a complete development stack.

## Quick Reference

### URLs

- **Main Application:** https://registro.local:8444
- **HTTP (redirects to HTTPS):** http://registro.local:8081
- **Filament Admin Panel:** https://registro.local:8444/admin
- **Horizon (Queue Monitor):** https://registro.local:8444/horizon
- **Vite Dev Server:** http://registro.local:5173

### Essential Commands

```bash
# Start all services
docker compose up -d

# View logs
docker compose logs -f

# Stop all services
docker compose down

# Rebuild containers
docker compose up -d --build

# Access shell in app container
docker compose exec app bash
```

## Docker Services

### 1. app (PHP 8.2-FPM)

**Container:** `registro-app`
**Base Image:** `php:8.2-fpm`
**Purpose:** Runs Laravel application

**PHP Extensions Installed:**
- `pdo_mysql`, `pdo_sqlite` - Database drivers
- `mbstring` - Multibyte string support
- `exif` - Image metadata reading
- `pcntl` - Process control
- `bcmath` - Arbitrary precision math
- `gd` - Image processing
- `zip` - ZIP archive handling
- `intl` - Internationalization (required for Laravel)

**Volume Mounts:**
- `./app:/var/www/html` - Application code
- `./docker/php/php.ini:/usr/local/etc/php/php.ini` - PHP configuration

**Commands:**
```bash
# Access shell
docker compose exec app bash

# Run Artisan commands
docker compose exec app php artisan <command>

# Run Composer commands
docker compose exec app composer <command>
```

### 2. nginx (Reverse Proxy)

**Container:** `registro-nginx`
**Base Image:** `nginx:alpine`
**Purpose:** Serves static assets and proxies PHP requests to FPM

**Ports:**
- `8081:80` - HTTP (redirects to HTTPS)
- `8444:443` - HTTPS with self-signed certificate

**Volume Mounts:**
- `./app:/var/www/html` - Application code (for static assets)
- `./docker/nginx/conf.d:/etc/nginx/conf.d` - Nginx configuration
- `./docker/ssl:/etc/nginx/ssl` - SSL certificates

**Configuration:**
- `./docker/nginx/conf.d/default.conf` - Server block configuration

### 3. mysql (Database)

**Container:** `registro-mysql`
**Base Image:** `mysql:8.0`
**Purpose:** MySQL database server

**Port:** `3307:3306` (host:container)

**Credentials:**
- **Database:** `registro`
- **Username:** `registro`
- **Password:** `password`
- **Root Password:** `root`

**Volume Mounts:**
- `mysql_data:/var/lib/mysql` - Persistent database storage

**Commands:**
```bash
# Access MySQL shell
docker compose exec mysql mysql -u registro -ppassword registro

# Run SQL query
docker compose exec mysql mysql -u registro -ppassword -e "SELECT * FROM users;" registro

# Backup database
docker compose exec mysql mysqldump -u registro -ppassword registro > backup.sql

# Restore database
docker compose exec -T mysql mysql -u registro -ppassword registro < backup.sql
```

### 4. node (Vite Dev Server)

**Container:** `registro-node`
**Base Image:** `node:20-alpine`
**Purpose:** Runs Vite development server for hot module replacement

**Port:** `5173:5173` - Vite dev server

**Volume Mounts:**
- `./app:/app` - Application code
- `node_modules:/app/node_modules` - Cached node modules

**Commands:**
```bash
# Access shell
docker compose exec node sh

# Run npm commands
docker compose exec node npm <command>

# Rebuild assets
docker compose exec node npm run build
```

### 5. redis (Queue Backend)

**Container:** `registro-redis`
**Base Image:** `redis:alpine`
**Purpose:** Queue backend and cache store

**Port:** `6380:6379` (host:container)

**Volume Mounts:**
- `redis_data:/data` - Persistent Redis data

**Commands:**
```bash
# Access Redis CLI
docker compose exec redis redis-cli

# Monitor commands
docker compose exec redis redis-cli MONITOR

# Check queue length
docker compose exec redis redis-cli LLEN queues:default
```

### 6. queue (Queue Worker)

**Container:** `registro-queue`
**Base Image:** Same as `app` (PHP-FPM)
**Purpose:** Processes queued jobs (emails, notifications)

**Command:** `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --queue=emails,reminders,default`

**Queue Priorities:**
1. `emails` (highest)
2. `reminders`
3. `default` (lowest)

**Commands:**
```bash
# View queue worker logs
docker compose logs -f queue

# Restart queue worker (after code changes)
docker compose restart queue
```

### 7. horizon (Queue Monitor)

**Container:** `registro-horizon`
**Base Image:** Same as `app` (PHP-FPM)
**Purpose:** Laravel Horizon queue monitoring dashboard

**Command:** `php artisan horizon`

**Dashboard:** https://registro.local:8444/horizon

**Features:**
- Real-time queue monitoring
- Failed job management
- Metrics and statistics
- Auto-scaling workers

### 8. scheduler (Task Scheduler)

**Container:** `registro-scheduler`
**Base Image:** Same as `app` (PHP-FPM)
**Purpose:** Runs Laravel scheduled tasks (cron jobs)

**Command:** `sh -c "while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done"`

**Scheduled Tasks:**
- Email reminders (24h, 2h before appointments)
- Follow-up emails (24h after completion)
- Admin daily digest (8:00 AM)
- Cleanup old email logs (2:00 AM daily)

## SSL Certificates

### Location

Self-signed certificates are stored in `docker/ssl/`:
- `cert.pem` - Certificate file
- `key.pem` - Private key file

### Generate New Certificates

```bash
cd docker/ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout key.pem -out cert.pem \
  -subj "/CN=registro.local"
```

### Trust Certificate (Browser)

**Chrome/Edge:**
1. Visit https://registro.local:8444
2. Click "Not Secure" → "Certificate"
3. Drag certificate to Desktop
4. Open Keychain Access → System → Add certificate
5. Double-click → Trust → Always Trust

**Firefox:**
1. Visit https://registro.local:8444
2. Click "Advanced" → "Accept the Risk and Continue"

**For detailed instructions:** See [docker/ssl/README.md](../../docker/ssl/README.md)

## Docker Compose Configuration

### docker-compose.yml Structure

```yaml
services:
  app:        # PHP-FPM (Laravel)
  nginx:      # Reverse proxy
  mysql:      # Database
  node:       # Vite dev server
  redis:      # Queue/cache backend
  queue:      # Queue worker
  horizon:    # Queue monitor
  scheduler:  # Task scheduler

volumes:
  mysql_data:    # Persistent MySQL data
  redis_data:    # Persistent Redis data
  node_modules:  # Cached Node modules
```

### Environment Switching

Switch between development and production modes via `.env`:

```bash
# Development
APP_ENV=local
APP_DEBUG=true

# Production
APP_ENV=production
APP_DEBUG=false
```

**After changing `.env`:**
```bash
docker compose down && docker compose up -d
docker compose exec app php artisan optimize:clear
```

## Troubleshooting

### Containers Won't Start

```bash
# Check Docker daemon
sudo systemctl status docker

# View container status
docker compose ps

# View error logs
docker compose logs nginx
docker compose logs app
```

### Port Already in Use

**Error:** `Bind for 0.0.0.0:8444 failed: port is already allocated`

**Solution:**
```bash
# Find process using port
sudo lsof -i :8444

# Kill process or change port in docker-compose.yml
# Edit docker-compose.yml → nginx.ports: "8445:443"
```

### MySQL Connection Refused

```bash
# Check MySQL container
docker compose ps mysql

# Restart MySQL
docker compose restart mysql

# Check MySQL logs
docker compose logs mysql

# Verify credentials in .env
DB_HOST=registro-mysql
DB_DATABASE=registro
DB_USERNAME=registro
DB_PASSWORD=password
```

### Volume Permission Issues

```bash
# Reset file permissions
sudo chown -R $USER:$USER app/

# Reset storage permissions
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### Container Disk Space Issues

```bash
# Check Docker disk usage
docker system df

# Clean up unused images
docker system prune -a

# Remove specific volumes (⚠️ DELETES DATA)
docker volume rm registro_mysql_data
```

## Production Deployment

For production deployment considerations:

- Use `docker-compose.prod.yml` with optimized settings
- Replace self-signed certificates with Let's Encrypt
- Set `APP_ENV=production` and `APP_DEBUG=false`
- Run `npm run build` before building containers
- Use Docker secrets for sensitive credentials
- Set up automated backups for MySQL volumes

**See Also:** [Production Build Guide](./production-build.md)

## See Also

- [Quick Start Guide](./quick-start.md) - Initial setup with Docker
- [Commands Reference](./commands.md) - Docker commands
- [Production Build](./production-build.md) - Asset compilation
- [Troubleshooting](./troubleshooting.md) - Common Docker issues
