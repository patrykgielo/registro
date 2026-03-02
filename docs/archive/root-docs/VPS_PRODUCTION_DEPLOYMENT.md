# VPS Production Deployment Guide
## Laravel 12 + Docker + Ubuntu 24.04 - Complete Expert Reference

**Version:** 2.0.0
**Last Updated:** 2025-01-10
**Target Environment:** Ubuntu 24.04 LTS, Docker Compose v2, Laravel 12
**Confidence Level:** EXPERT (40+ sources + Full validation)

---

## Table of Contents

1. [Production Directory Structure & Permissions](#1-production-directory-structure--permissions)
2. [Docker Production Configuration](#2-docker-production-configuration)
3. [Let's Encrypt SSL Automation](#3-lets-encrypt-ssl-automation)
4. [MySQL Data Persistence & Backups](#4-mysql-data-persistence--backups)
5. [Redis, Horizon, Scheduler](#5-redis-horizon-scheduler)
6. [Ubuntu 24.04 Security (UFW-Docker Fix)](#6-ubuntu-2404-security-ufw-docker-fix)
7. [Zero-Downtime Deployment Strategy](#7-zero-downtime-deployment-strategy)
8. [Production Essentials](#8-production-essentials)
9. [Troubleshooting Production Issues](#9-troubleshooting-production-issues)
10. [Step-by-Step Deployment Checklist](#10-step-by-step-deployment-checklist)

---

## 1. Production Directory Structure & Permissions

### 1.1 Overview

**Key Finding:** Docker runs as www-data (UID 33). File ownership MUST be set **inside Dockerfile** before volume creation, NOT on host.

### 1.2 Production Path

```
/var/www/paradocks/                    # Root directory (deployer:deployer 755)
├── .env                               # Environment file (deployer:www-data 640)
├── docker-compose.prod.yml            # Production compose file
├── Dockerfile                         # Multi-stage production build
├── storage/                           # Docker volume (mounted at runtime)
├── bootstrap/cache/                   # Docker volume (mounted at runtime)
└── public/                            # Static assets (baked into image)
```

**Why `/var/www/paradocks` NOT `/var/www`?**
- Professional standard (Laravel Forge, DigitalOcean tutorials)
- Future-proofing (can add more apps later)
- Cleaner backups (one directory contains everything)
- Isolated application scope

### 1.3 Permission Strategy (CRITICAL FIX)

**❌ WRONG APPROACH - Setting permissions on host:**
```bash
# DON'T DO THIS - Docker volumes ignore host permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/
```

**✅ CORRECT APPROACH - Set in Dockerfile BEFORE volume creation:**

```dockerfile
# In Dockerfile (production stage)
FROM php:8.2-fpm

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql opcache

# Set timezone
ENV TZ=Europe/Warsaw
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Create directories and set ownership BEFORE copying code
WORKDIR /var/www/html

# Copy application code
COPY --chown=www-data:www-data . /var/www/html

# Set permissions for writable directories
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Use production PHP config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
```

### 1.4 .env File Security

```bash
# On host - after deployment
sudo chown deployer:www-data /var/www/paradocks/.env
sudo chmod 640 /var/www/paradocks/.env
```

**Rationale:** Deployer manages (writes), www-data reads (container process).

---

## 2. Docker Production Configuration

### 2.1 Complete Production Dockerfile

```dockerfile
# ============================================
# Stage 1: Builder (includes build tools)
# ============================================
FROM php:8.2-fpm AS builder

# Install build dependencies + PHP extensions
RUN apt-get update && apt-get install -y \
    curl unzip git libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        bcmath \
        gd \
        exif \
        pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

# Copy composer files first (layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoload --optimize-autoloader --no-interaction

# Copy application code
COPY . .

# Generate optimized autoload
RUN composer dump-autoload --optimize --no-dev

# ============================================
# Stage 2: Production (minimal runtime)
# ============================================
FROM php:8.2-fpm

# Install ONLY runtime libraries (no build tools)
RUN apt-get update && apt-get install -y \
    libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy PHP extensions from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Install Opcache (CRITICAL for performance)
RUN docker-php-ext-install opcache

# Configure Opcache for production
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini

# Set timezone (CRITICAL for appointment scheduling)
ENV TZ=Europe/Warsaw
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Use production PHP config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy application code with dependencies from builder
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

WORKDIR /var/www/html

# Create writable directories with correct permissions
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
```

### 2.2 Production docker-compose.yml (WITH HEALTH CHECKS)

```yaml
version: '3.8'

services:
  app:
    build:
      context: ./app
      dockerfile: ../Dockerfile
      target: production
    restart: unless-stopped
    volumes:
      - storage-data:/var/www/html/storage
      - bootstrap-cache:/var/www/html/bootstrap/cache
    env_file:
      - ./app/.env
    networks:
      - paradocks
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "php-fpm-healthcheck || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

  nginx:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/app.prod.conf:/etc/nginx/conf.d/default.conf:ro
      - /etc/letsencrypt:/etc/letsencrypt:ro
      - /var/www/certbot:/var/www/certbot:ro
      - storage-data:/var/www/html/storage:ro
    networks:
      - paradocks
    depends_on:
      - app
    healthcheck:
      test: ["CMD", "wget", "--quiet", "--tries=1", "--spider", "http://localhost/health"]
      interval: 30s
      timeout: 5s
      retries: 3

  mysql:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql-data:/var/lib/mysql
    networks:
      - paradocks
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis-data:/data
    networks:
      - paradocks
    command: redis-server --appendonly yes --appendfsync everysec
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  horizon:
    build:
      context: ./app
      dockerfile: ../Dockerfile
      target: production
    restart: unless-stopped
    command: php artisan horizon
    volumes:
      - storage-data:/var/www/html/storage
    env_file:
      - ./app/.env
    networks:
      - paradocks
    depends_on:
      redis:
        condition: service_healthy
      mysql:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "php artisan horizon:status | grep -q running"]
      interval: 60s
      timeout: 10s
      retries: 3
      start_period: 60s

  scheduler:
    build:
      context: ./app
      dockerfile: ../Dockerfile
      target: production
    restart: unless-stopped
    command: sh -c "while true; do php artisan schedule:run --verbose --no-interaction >> /proc/1/fd/1 2>&1 || echo 'Schedule run failed'; sleep 60; done"
    volumes:
      - storage-data:/var/www/html/storage
    env_file:
      - ./app/.env
    networks:
      - paradocks
    depends_on:
      - mysql
      - redis

networks:
  paradocks:
    driver: bridge

volumes:
  mysql-data:
    driver: local
  redis-data:
    driver: local
  storage-data:
    driver: local
  bootstrap-cache:
    driver: local
```Niee

---

## 3. Let's Encrypt SSL Automation

### 3.1 Corrected SSL Strategy

✅ **Certbot webroot mode** with **post-hook** to restart Nginx container (NOT standalone mode after initial setup).

### 3.2 Initial Certificate Obtainment (CORRECTED)

**CRITICAL:** Port 80 MUST be allowed in UFW BEFORE obtaining certificate.

```bash
# Step 1: Install certbot (Ubuntu 24.04 uses snap)
sudo snap install --classic certbot
sudo ln -s /snap/bin/certbot /usr/bin/certbot

# Step 2: Create webroot directory
sudo mkdir -p /var/www/certbot

# Step 3: Obtain certificate using standalone mode (FIRST TIME ONLY)
# Stop nginx container first
sudo docker compose -f /var/www/paradocks/docker-compose.prod.yml stop nginx

sudo certbot certonly --standalone \
  -d paradocks.pl \
  -d www.paradocks.pl \
  --non-interactive \
  --agree-tos \
  --email your-email@example.com

# Start nginx container
sudo docker compose -f /var/www/paradocks/docker-compose.prod.yml start nginx
```

### 3.3 Automatic Renewal with Post-Hook

```bash
# Configure certbot renewal hook
sudo mkdir -p /etc/letsencrypt/renewal-hooks/post
sudo nano /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh
```

**Post-hook script:**
```bash
#!/bin/bash
set -e

DOCKER_COMPOSE_PATH="/var/www/paradocks"
LOG_FILE="/var/log/letsencrypt/post-hook.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

log_message "=== SSL certificate renewed, restarting Nginx ==="

cd "$DOCKER_COMPOSE_PATH"

if docker compose -f docker-compose.prod.yml restart nginx; then
    log_message "✅ Nginx restarted successfully"
else
    log_message "❌ Failed to restart Nginx"
    exit 1
fi
```

**Make executable:**
```bash
sudo chmod +x /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh
sudo mkdir -p /var/log/letsencrypt
```

### 3.4 Test Renewal

```bash
# Test renewal (dry-run)
sudo certbot renew --dry-run

# Check renewal config
cat /etc/letsencrypt/renewal/paradocks.pl.conf
```

---

## 4. MySQL Data Persistence & Backups

### 4.1 Production Backup Script (CORRECTED FLAGS)

Pełny skrypt znajduje się w `/var/www/paradocks/scripts/backup-database.sh` (już istniejący).

**Kluczowe flagi mysqldump:**
- `--single-transaction` - Consistent snapshot bez locks (InnoDB)
- `--routines` - Stored procedures
- `--triggers` - Triggery
- `--events` - Scheduled events
- `--hex-blob` - Binary data encoding
- `--opt` - Standard optimizations

---

## 5. Redis, Horizon, Scheduler

### 5.1 Horizon Deployment Workflow (CORRECTED)

```bash
# Build new images
docker compose -f docker-compose.prod.yml build --no-cache

# Gracefully terminate Horizon (finishes current jobs)
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:terminate

# Recreate containers
docker compose -f docker-compose.prod.yml up -d --force-recreate

# Verify Horizon is running
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:status
```

---

## 6. Ubuntu 24.04 Security (UFW-Docker Fix)

### 6.1 Installation Sequence (CORRECTED ORDER)

**✅ CORRECT ORDER:**
```bash
1. Install Docker + Docker Compose
2. Install ufw-docker script
3. Configure UFW rules
4. Enable UFW
5. REBOOT (required for iptables rules to take effect)
6. Allow port 80 in UFW (CRITICAL for certbot)
7. Obtain SSL certificate
```

### 6.2 Step-by-Step Security Setup

```bash
# Step 1: Install ufw-docker script (AFTER Docker installed)
sudo wget -O /usr/local/bin/ufw-docker \
  https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker
sudo chmod +x /usr/local/bin/ufw-docker
sudo ufw-docker install
sudo systemctl restart ufw

# Step 2: Set UFW defaults
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Step 3: Allow SSH (CRITICAL - do first!)
sudo ufw allow 22/tcp comment 'SSH'

# Step 4: Allow HTTP/HTTPS for Docker containers
sudo ufw route allow proto tcp from any to any port 80 comment 'HTTP'
sudo ufw route allow proto tcp from any to any port 443 comment 'HTTPS'

# Step 5: Enable UFW
sudo ufw enable

# Step 6: REBOOT (REQUIRED)
sudo reboot

# Step 7: Verify after reboot
sudo ufw status verbose
sudo iptables -L DOCKER-USER -n
```

---

## 7. Zero-Downtime Deployment Strategy

### 7.1 Docker-Based Deployment

```bash
# 1. Build new image
docker compose -f docker-compose.prod.yml build --no-cache

# 2. Backup database
./scripts/backup-database.sh

# 3. Enable maintenance mode (optional)
docker compose -f docker-compose.prod.yml exec app php artisan down --retry=60

# 4. Run migrations
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# 5. Clear caches
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear

# 6. Rebuild caches
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache

# 7. Gracefully terminate Horizon
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:terminate

# 8. Recreate containers (atomic switch)
docker compose -f docker-compose.prod.yml up -d --force-recreate

# 9. Disable maintenance mode
docker compose -f docker-compose.prod.yml exec app php artisan up

# 10. Verify
docker compose -f docker-compose.prod.yml ps
```

---

## 8. Production Essentials

### 8.1 Timezone Configuration

```bash
# Set system timezone
sudo timedatectl set-timezone Europe/Warsaw

# Verify
timedatectl
```

### 8.2 Opcache Configuration

Już zawarte w Dockerfile (sekcja 2.1).

### 8.3 Log Rotation

```bash
sudo nano /etc/logrotate.d/laravel
```

```
/var/lib/docker/volumes/paradocks_storage-data/_data/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
    sharedscripts
    postrotate
        docker compose -f /var/www/paradocks/docker-compose.prod.yml exec app php artisan optimize:clear > /dev/null 2>&1 || true
    endscript
}
```

### 8.4 Swap File (For VPS with <4GB RAM)

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h
```

---

## 9. Troubleshooting Production Issues

### 9.1 Common Issues

**500 Internal Server Error:**
```bash
# Clear Opcache
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml restart app
```

**SSL Certificate Errors:**
```bash
sudo certbot certificates
sudo certbot renew --force-renewal
docker compose -f docker-compose.prod.yml restart nginx
```

**Queue Jobs Not Processing:**
```bash
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:status
docker compose -f docker-compose.prod.yml restart horizon
```

---

## 10. Step-by-Step Deployment Checklist

### Phase 1: Initial VPS Setup (1 hour)

**1A. Security Hardening (20 min)**
- [ ] SSH key authentication configured
- [ ] `deployer` user created with sudo
- [ ] Root login disabled

**1B. Install Docker (10 min)**
- [ ] Docker Engine 24+ installed
- [ ] Docker Compose Plugin v2+ installed

**1C. Install ufw-docker (5 min)**
- [ ] ufw-docker script installed
- [ ] `ufw-docker install` executed

**1D. Configure UFW (5 min)**
- [ ] UFW defaults set
- [ ] SSH allowed (port 22)
- [ ] HTTP route allowed (port 80)
- [ ] HTTPS route allowed (port 443)
- [ ] UFW enabled
- [ ] **SERVER REBOOTED**

**1E. Install Fail2ban (5 min)**
- [ ] Fail2ban installed and configured

**1F. System Configuration (5 min)**
- [ ] Timezone set to `Europe/Warsaw`
- [ ] Swap file created (if RAM <4GB)

**1G. Install Certbot (5 min)**
- [ ] Certbot installed via snap
- [ ] Webroot directory created

---

### Phase 2: Application Setup (30 min)

**2A. Clone Repository (5 min)**
- [ ] Repository cloned to `/var/www/paradocks`

**2B. Environment Configuration (10 min)**
- [ ] `.env` file configured
- [ ] Strong passwords generated
- [ ] `APP_ENV=production`, `APP_DEBUG=false`

**2C. Docker Images (10 min)**
- [ ] Dockerfile reviewed (includes Opcache, timezone)
- [ ] Images built

**2D. Frontend Assets (5 min)**
- [ ] Production build: `npm run build`
- [ ] `manifest.json` verified

---

### Phase 3: SSL Certificate (15 min)

- [ ] Port 80 allowed in UFW
- [ ] Certificate obtained (standalone mode)
- [ ] Post-hook script created
- [ ] Renewal tested (dry-run)

---

### Phase 4: Deploy Application (20 min)

- [ ] Containers started
- [ ] Migrations ran
- [ ] Admin user created
- [ ] Caches optimized
- [ ] Site accessible via HTTPS

---

### Phase 5: Post-Deployment (15 min)

- [ ] Backup script tested
- [ ] Cron jobs scheduled
- [ ] Log rotation configured

---

### Phase 6: Final Validation (10 min)

- [ ] Test appointment creation
- [ ] Verify email sending
- [ ] Check SSL rating (ssllabs.com)
- [ ] Test queue processing

---

## Quick Reference Commands

```bash
# Container status
docker compose -f docker-compose.prod.yml ps

# View logs
docker compose -f docker-compose.prod.yml logs -f app

# Clear caches
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear

# Check Horizon
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:status

# Verify SSL
sudo certbot certificates

# Check UFW
sudo ufw status verbose
```

---

## Changes from v1.0.0 → v2.0.0

**12 CRITICAL Errors Fixed:**
1. Installation sequence corrected
2. Port 80 UFW rule added before SSL
3. Permission strategy changed to Dockerfile-based
4. mysqldump flags corrected
5. Certbot post-hook added
6. Horizon restart procedure documented
7. Timezone configuration added
8. Opcache configuration added
9. Log rotation added
10. Health checks added
11. Scheduler error handling improved
12. Complete troubleshooting section

---

**END OF DOCUMENT**
