# Staging Environment - Configuration Reference

**Environment**: Staging VPS
**Server**: 72.60.17.138
**Last Updated**: 2025-11-11

This document contains the complete configuration reference for the staging environment, including all configuration files and their purposes.

---

## Table of Contents

- [Environment Variables (.env)](#environment-variables-env)
- [Docker Compose Configuration](#docker-compose-configuration)
- [Nginx Configuration](#nginx-configuration)
- [PHP Configuration](#php-configuration)
- [Laravel Configuration](#laravel-configuration)
- [MySQL Configuration](#mysql-configuration)
- [Redis Configuration](#redis-configuration)
- [System Configuration](#system-configuration)

---

## Environment Variables (.env)

**Location**: `/var/www/paradocks/.env`
**Security**: NOT committed to Git (in .gitignore)

### Application Settings

```env
APP_NAME="ParaDocks"
APP_ENV=production
APP_KEY=base64:... (generated via php artisan key:generate)
APP_DEBUG=false
APP_TIMEZONE=Europe/Warsaw
APP_URL=http://72.60.17.138
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
```

**Notes**:
- `APP_ENV=production` - Uses production optimizations
- `APP_DEBUG=false` - Hides detailed error messages (security)
- `APP_TIMEZONE=Europe/Warsaw` - All timestamps in Warsaw timezone
- `APP_URL` - Currently IP-based, update when domain is configured

### Database Configuration

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=paradocks
DB_USERNAME=paradocks
DB_PASSWORD=<see 03-CREDENTIALS.md>
```

**Notes**:
- `DB_HOST=mysql` - Docker service name (internal DNS)
- Password generated with `openssl rand -base64 32`
- These variables were commented in .env.example (had to uncomment)

### Cache Configuration

```env
CACHE_STORE=redis
CACHE_PREFIX=
```

**Notes**:
- Redis cache for better performance
- No prefix (single application per Redis instance)

### Session Configuration

```env
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
```

**Notes**:
- Redis for session storage (persistence across app restarts)
- 120 minutes lifetime (2 hours)
- No encryption needed (Redis is password-protected)

### Queue Configuration

```env
QUEUE_CONNECTION=redis
```

**Notes**:
- All queues processed via Redis
- Monitored by Laravel Horizon
- Workers configured in config/horizon.php

### Redis Configuration

```env
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=<see 03-CREDENTIALS.md>
REDIS_PORT=6379
```

**Notes**:
- `phpredis` extension (faster than predis)
- `REDIS_HOST=redis` - Docker service name
- Password authentication enabled

### Mail Configuration

```env
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@paradocks.com"
MAIL_FROM_NAME="${APP_NAME}"
```

**Notes**:
- Currently using `log` driver (emails written to laravel.log)
- Pending Gmail SMTP configuration
- Will need to update when SMTP is configured

**Pending SMTP Configuration**:
```env
# Future configuration:
# MAIL_MAILER=smtp
# MAIL_HOST=smtp.gmail.com
# MAIL_PORT=587
# MAIL_USERNAME=your-email@gmail.com
# MAIL_PASSWORD=your-app-password
# MAIL_ENCRYPTION=tls
```

### Broadcasting Configuration

```env
BROADCAST_CONNECTION=log
```

**Notes**:
- Currently disabled (using log driver)
- Can enable Pusher or Laravel Echo Server if real-time features needed

### Logging Configuration

```env
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info
LOG_DEPRECATIONS_CHANNEL=null
```

**Notes**:
- `stack` channel combines multiple log drivers
- `single` backend - writes to `storage/logs/laravel.log`
- `info` level - logs info, warning, error, critical, alert, emergency

### Vite Configuration

```env
VITE_APP_NAME="${APP_NAME}"
```

**Notes**:
- Used during asset compilation
- Not used in runtime (assets pre-built)

### Additional Settings

```env
BCRYPT_ROUNDS=12
```

**Notes**:
- Password hashing rounds (security vs. performance balance)
- Default Laravel value

---

## Docker Compose Configuration

**Location**: `/var/www/paradocks/docker-compose.prod.yml`

### Complete Configuration

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    image: paradocks-app:latest
    container_name: paradocks-app
    restart: unless-stopped
    working_dir: /var/www/html
    environment:
      - APP_ENV=${APP_ENV}
      - APP_DEBUG=${APP_DEBUG}
      - DB_HOST=${DB_HOST}
      - DB_PORT=${DB_PORT}
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - REDIS_PORT=${REDIS_PORT}
    volumes:
      - ./:/var/www/html
      - ./storage:/var/www/html/storage
      - ./bootstrap/cache:/var/www/html/bootstrap/cache
    networks:
      - paradocks_network
    depends_on:
      - mysql
      - redis

  nginx:
    image: nginx:1.25-alpine
    container_name: paradocks-nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./:/var/www/html
      - ./docker/nginx/app.prod.conf:/etc/nginx/conf.d/default.conf
      - ./public:/var/www/html/public
    networks:
      - paradocks_network
    depends_on:
      - app

  mysql:
    image: mysql:8.0
    container_name: paradocks-mysql
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    ports:
      - "3306:3306"
    volumes:
      - paradocks_mysql-data:/var/lib/mysql
      - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf
    networks:
      - paradocks_network

  redis:
    image: redis:7.2-alpine
    container_name: paradocks-redis
    restart: unless-stopped
    command: redis-server --requirepass ${REDIS_PASSWORD}
    ports:
      - "6379:6379"
    networks:
      - paradocks_network

  horizon:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    image: paradocks-app:latest
    container_name: paradocks-horizon
    restart: unless-stopped
    working_dir: /var/www/html
    command: php artisan horizon
    environment:
      - APP_ENV=${APP_ENV}
      - DB_HOST=${DB_HOST}
      - DB_PORT=${DB_PORT}
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - REDIS_PORT=${REDIS_PORT}
    volumes:
      - ./:/var/www/html
    networks:
      - paradocks_network
    depends_on:
      - mysql
      - redis

  scheduler:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    image: paradocks-app:latest
    container_name: paradocks-scheduler
    restart: unless-stopped
    working_dir: /var/www/html
    command: /bin/sh -c "while true; do php artisan schedule:run --verbose --no-interaction & sleep 60; done"
    environment:
      - APP_ENV=${APP_ENV}
      - DB_HOST=${DB_HOST}
      - DB_PORT=${DB_PORT}
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - REDIS_PORT=${REDIS_PORT}
    volumes:
      - ./:/var/www/html
    networks:
      - paradocks_network
    depends_on:
      - mysql
      - redis

networks:
  paradocks_network:
    driver: bridge

volumes:
  paradocks_mysql-data:
    driver: local
```

### Key Configuration Notes

**Service: app**
- PHP-FPM application container
- `restart: unless-stopped` - Auto-restart on failure
- Bind mounts (NOT Docker volumes) for storage (see ADR-002)
- Environment variables passed from .env

**Service: nginx**
- Web server and reverse proxy
- Ports 80 (HTTP) and 443 (HTTPS) exposed
- Serves static files from public/
- Proxies PHP requests to app:9000

**Service: mysql**
- MySQL 8.0 database
- Port 3306 exposed for external tools (e.g., MySQL Workbench)
- Persistent storage via Docker volume
- Custom my.cnf configuration

**Service: redis**
- Redis 7.2 for cache/queue/sessions
- Password authentication via command parameter
- Port 6379 exposed for external tools

**Service: horizon**
- Laravel Horizon queue worker
- Shares same image as app
- Runs `php artisan horizon` command
- Auto-restarts if crashes

**Service: scheduler**
- Laravel task scheduler
- Runs schedule:run every 60 seconds
- Replaces cron jobs

**Networks**
- Single bridge network for all services
- Internal DNS resolution (services can reach each other by name)

**Volumes**
- `paradocks_mysql-data` - MySQL persistent storage
- No volumes for app storage (using bind mounts)

---

## Nginx Configuration

**Location**: `/var/www/paradocks/docker/nginx/app.prod.conf`

### Complete Configuration

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name _;

    root /var/www/html/public;
    index index.php index.html index.htm;

    # Logging
    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;

    # Client request size
    client_max_body_size 20M;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss application/json;

    # Main location block
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM processing
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass paradocks-app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param HTTP_PROXY "";
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
    }

    # Static files caching
    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml|svg|woff|woff2|ttf|eot)$ {
        expires max;
        access_log off;
        log_not_found off;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # Deny access to sensitive files
    location ~ /(\.env|\.git|composer\.(json|lock)|package\.(json|lock)|artisan|\.env\.example) {
        deny all;
        access_log off;
        log_not_found off;
    }
}

# HTTPS configuration (not active yet)
# server {
#     listen 443 ssl http2;
#     listen [::]:443 ssl http2;
#     server_name your-domain.com;
#
#     ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
#     ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
#
#     # SSL configuration
#     ssl_protocols TLSv1.2 TLSv1.3;
#     ssl_ciphers HIGH:!aNULL:!MD5;
#     ssl_prefer_server_ciphers on;
#
#     # ... (same configuration as HTTP block)
# }
```

### Configuration Notes

**Listen Directives**:
- `listen 80` - IPv4 HTTP
- `listen [::]:80` - IPv6 HTTP
- `server_name _` - Catch-all (no specific domain yet)

**Root and Index**:
- `root /var/www/html/public` - Laravel public directory
- `index index.php` - PHP entry point

**Client Settings**:
- `client_max_body_size 20M` - Max upload size (matches PHP settings)

**Compression**:
- Gzip enabled for text files and assets
- Reduces bandwidth usage

**PHP Processing**:
- `fastcgi_pass paradocks-app:9000` - Routes to PHP-FPM container
- FastCGI buffers sized for large responses

**Static File Caching**:
- `expires max` - Browser caching for assets
- Reduces server load

**Security**:
- Denies access to hidden files (.git, .env, etc.)
- Denies access to sensitive files (composer.json, artisan, etc.)

**HTTPS**:
- Commented out (not configured yet)
- Ready to uncomment when SSL certificates obtained

---

## PHP Configuration

**Location**: `/var/www/paradocks/docker/php/php.ini`

### Production Configuration

```ini
[PHP]
; Memory and execution limits
memory_limit = 256M
max_execution_time = 300
max_input_time = 300
max_input_vars = 3000

; File uploads
upload_max_filesize = 20M
post_max_size = 20M

; Error handling
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php_errors.log
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; Timezone
date.timezone = Europe/Warsaw

; OPcache configuration
[opcache]
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
opcache.validate_timestamps = 1

; Realpath cache
realpath_cache_size = 4096k
realpath_cache_ttl = 600

; Session configuration
session.save_handler = redis
session.save_path = "tcp://redis:6379?auth=<redis_password>"
session.gc_maxlifetime = 7200

; Security
expose_php = Off
allow_url_fopen = On
allow_url_include = Off
```

### Configuration Notes

**Memory and Execution**:
- `memory_limit = 256M` - Adequate for most operations
- `max_execution_time = 300` - 5 minutes for long-running requests
- `max_input_vars = 3000` - Handles large forms (Filament)

**File Uploads**:
- `upload_max_filesize = 20M` - Matches Nginx client_max_body_size
- `post_max_size = 20M` - Must be >= upload_max_filesize

**Error Handling**:
- `display_errors = Off` - Security best practice for production
- `log_errors = On` - Errors logged to file
- `error_reporting` - Exclude deprecated/strict notices

**OPcache**:
- Enabled for production performance
- `opcache.memory_consumption = 128` - OPcache memory
- `opcache.max_accelerated_files = 10000` - Plenty for Laravel
- `opcache.revalidate_freq = 2` - Check for changes every 2 seconds

**Session**:
- Using Redis (faster than files, persistent)
- 7200 seconds (2 hours) lifetime

**Security**:
- `expose_php = Off` - Hides PHP version in headers
- `allow_url_include = Off` - Prevents remote code inclusion

---

## Laravel Configuration

### Core Application Config

**Location**: `/var/www/paradocks/config/app.php`

**Key Settings**:
```php
'name' => env('APP_NAME', 'ParaDocks'),
'env' => env('APP_ENV', 'production'),
'debug' => (bool) env('APP_DEBUG', false),
'url' => env('APP_URL', 'http://localhost'),
'timezone' => env('APP_TIMEZONE', 'Europe/Warsaw'),
'locale' => env('APP_LOCALE', 'en'),
```

### Database Config

**Location**: `/var/www/paradocks/config/database.php`

**MySQL Connection**:
```php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => null,
],
```

**Redis Connection**:
```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => 0,
    ],
    'cache' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => 1,
    ],
],
```

### Cache Config

**Location**: `/var/www/paradocks/config/cache.php`

```php
'default' => env('CACHE_STORE', 'redis'),

'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],
```

### Queue Config

**Location**: `/var/www/paradocks/config/queue.php`

```php
'default' => env('QUEUE_CONNECTION', 'redis'),

'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
        'after_commit' => false,
    ],
],
```

### Session Config

**Location**: `/var/www/paradocks/config/session.php`

```php
'driver' => env('SESSION_DRIVER', 'redis'),
'lifetime' => env('SESSION_LIFETIME', 120),
'expire_on_close' => false,
'encrypt' => false,
'files' => storage_path('framework/sessions'),
'connection' => 'default',
'table' => 'sessions',
'store' => null,
'lottery' => [2, 100],
'cookie' => env(
    'SESSION_COOKIE',
    Str::slug(env('APP_NAME', 'laravel'), '_').'_session'
),
'path' => '/',
'domain' => env('SESSION_DOMAIN'),
'secure' => env('SESSION_SECURE_COOKIE', false),
'http_only' => true,
'same_site' => 'lax',
```

### Horizon Config

**Location**: `/var/www/paradocks/config/horizon.php`

```php
'defaults' => [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue' => ['default'],
        'balance' => 'auto',
        'autoScalingStrategy' => 'time',
        'maxProcesses' => 1,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 128,
        'tries' => 1,
        'timeout' => 60,
        'nice' => 0,
    ],
],

'environments' => [
    'production' => [
        'supervisor-1' => [
            'maxProcesses' => 10,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
    ],
],
```

### Filament Config

**Location**: `/var/www/paradocks/config/filament.php`

```php
'path' => env('FILAMENT_PATH', 'admin'),
'domain' => env('FILAMENT_DOMAIN'),
'middleware' => [
    'auth' => [
        Authenticate::class,
    ],
],
'auth' => [
    'guard' => env('FILAMENT_AUTH_GUARD', 'web'),
    'pages' => [
        'login' => \Filament\Pages\Auth\Login::class,
    ],
],
```

**Access**: http://72.60.17.138/admin

---

## MySQL Configuration

**Location**: `/var/www/paradocks/docker/mysql/my.cnf`

```ini
[mysqld]
default-authentication-plugin=mysql_native_password
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci

# Performance
max_connections=151
innodb_buffer_pool_size=256M

# Binary logging (for backups and replication)
binlog_expire_logs_seconds=259200
max_binlog_size=100M

# Slow query log (for optimization)
slow_query_log=1
slow_query_log_file=/var/log/mysql/slow-query.log
long_query_time=2
```

### Configuration Notes

**Authentication**:
- `mysql_native_password` - Compatible with all MySQL clients
- Required for some older tools

**Character Set**:
- `utf8mb4` - Full Unicode support (including emojis)
- `utf8mb4_unicode_ci` - Case-insensitive collation

**Performance**:
- `max_connections=151` - Default (adequate for staging)
- `innodb_buffer_pool_size=256M` - ~25% of available RAM

**Logging**:
- Binary logs expire after 3 days
- Slow queries logged (>2 seconds)

---

## Redis Configuration

**Runtime Configuration**: Password-protected via command line

```bash
docker-compose -f docker-compose.prod.yml up -d redis
# Runs: redis-server --requirepass ${REDIS_PASSWORD}
```

**Logical Database Allocation**:
- DB 0: Default/Queue connection
- DB 1: Cache connection
- DB 2: Available for future use

**Memory Policy**: `noeviction` (default)
- Redis will not evict keys when memory limit reached
- Suitable for queue/session data (should not be lost)

**Persistence**: Disabled (cache/queue can be rebuilt)
- No RDB snapshots
- No AOF (Append-Only File)
- Faster performance

---

## System Configuration

### UFW Firewall

**Status**: Active

```bash
sudo ufw status verbose

Status: active
Logging: on (low)
Default: deny (incoming), allow (outgoing), disabled (routed)

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW IN    Anywhere
80/tcp                     ALLOW IN    Anywhere
443/tcp                    ALLOW IN    Anywhere
```

**UFW-Docker Integration**:
- Script installed at `/usr/local/bin/ufw-docker`
- Prevents Docker from bypassing UFW rules
- See: [ADR-001](../../architecture/decision_log/ADR-001-ufw-docker-security.md)

### Swap Configuration

**Size**: 2GB

```bash
# Swap file
/swapfile none swap sw 0 0

# Swappiness (default: 60)
vm.swappiness=60
```

**Verify**:
```bash
free -h
swapon --show
```

### File Permissions

**Application Files**:
```
Owner: ubuntu:ubuntu (1000:1000)
Directories: 775
Files: 664
```

**Storage Directories**:
```bash
chmod -R 775 storage bootstrap/cache
chown -R ubuntu:ubuntu storage bootstrap/cache
```

**Public Directory**:
```
Owner: ubuntu:ubuntu (1000:1000)
Directories: 755
Files: 644
```

---

## Configuration Verification Commands

### Verify All Configurations

```bash
# Environment variables
docker-compose -f docker-compose.prod.yml exec app php artisan about

# Database connection
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \DB::connection()->getPdo();

# Redis connection
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Redis::ping();

# Cache configuration
docker-compose -f docker-compose.prod.yml exec app php artisan config:show cache

# Queue configuration
docker-compose -f docker-compose.prod.yml exec app php artisan config:show queue

# Nginx configuration test
docker-compose -f docker-compose.prod.yml exec nginx nginx -t

# MySQL configuration
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p -e "SHOW VARIABLES LIKE 'character%';"

# PHP configuration
docker-compose -f docker-compose.prod.yml exec app php -i | grep -E "(memory_limit|max_execution_time|upload_max_filesize)"

# Horizon status
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:status

# Firewall status
sudo ufw status verbose
```

---

## Configuration Changes Log

| Date | File | Change | Reason |
|------|------|--------|--------|
| 2025-11-11 | .env | Uncommented DB_ variables | Were commented in .env.example |
| 2025-11-11 | docker-compose.prod.yml | Removed storage volumes | Permission issues (ADR-002) |
| 2025-11-11 | app.prod.conf | Removed paradocks-node references | Service doesn't exist |
| 2025-11-11 | public/build/manifest.json | Created symlink to .vite/manifest.json | Vite path mismatch (ADR-003) |

---

## Related Documentation

- **Credentials**: [03-CREDENTIALS.md](03-CREDENTIALS.md) (passwords, API keys)
- **Service Management**: [04-SERVICES.md](04-SERVICES.md) (start/stop/restart)
- **Deployment Log**: [01-DEPLOYMENT-LOG.md](01-DEPLOYMENT-LOG.md) (how these configs were applied)
- **Technology Stack**: [../../architecture/technology-stack.md](../../architecture/technology-stack.md)

---

**Document Maintainer**: DevOps Team
**Last Review**: 2025-11-11
**Next Review**: When configuration changes are made
