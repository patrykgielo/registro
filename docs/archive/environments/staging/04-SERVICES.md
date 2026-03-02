# Staging Environment - Service Management

**Environment**: Staging VPS
**Server**: 72.60.17.138
**Last Updated**: 2025-11-11

This document provides comprehensive service management procedures for all Docker containers and system services running on the staging server.

---

## Table of Contents

- [Service Overview](#service-overview)
- [Docker Service Management](#docker-service-management)
- [Individual Service Management](#individual-service-management)
- [Common Operations](#common-operations)
- [Troubleshooting](#troubleshooting)
- [Performance Monitoring](#performance-monitoring)

---

## Service Overview

### Running Services

```bash
# View all services
docker-compose -f docker-compose.prod.yml ps
```

**Expected Output**:

| Service | Container Name | Status | Ports | Purpose |
|---------|---------------|---------|-------|---------|
| app | paradocks-app | Up (healthy) | 9000/tcp | PHP-FPM application |
| nginx | paradocks-nginx | Up (healthy) | 80, 443 | Web server, reverse proxy |
| mysql | paradocks-mysql | Up (healthy) | 3306 | Database |
| redis | paradocks-redis | Up (healthy) | 6379 | Cache, queue, sessions |
| horizon | paradocks-horizon | Up (healthy) | - | Queue worker manager |
| scheduler | paradocks-scheduler | Up | - | Task scheduler (cron) |

### Service Dependencies

```
┌─────────────────┐
│     nginx       │  (depends on app)
└────────┬────────┘
         │
    ┌────▼────┐
    │   app   │────┬───► mysql
    └─────────┘    │
                   └───► redis
         │
    ┌────▼────────┐
    │   horizon   │───► redis, mysql
    └─────────────┘
         │
    ┌────▼────────┐
    │  scheduler  │───► redis, mysql
    └─────────────┘
```

**Startup Order**:
1. mysql, redis (base layer)
2. app (depends on mysql, redis)
3. nginx (depends on app)
4. horizon, scheduler (depends on mysql, redis)

---

## Docker Service Management

### Project Location

```bash
# Always navigate to project directory first
cd /var/www/paradocks
```

**All commands below assume you're in `/var/www/paradocks`**

### Start All Services

```bash
# Start all services in background
docker-compose -f docker-compose.prod.yml up -d

# Start and view logs
docker-compose -f docker-compose.prod.yml up

# Start specific services
docker-compose -f docker-compose.prod.yml up -d app nginx mysql
```

**Verification**:
```bash
# Check all containers are running
docker-compose -f docker-compose.prod.yml ps

# View startup logs
docker-compose -f docker-compose.prod.yml logs
```

### Stop All Services

```bash
# Stop all services (containers remain)
docker-compose -f docker-compose.prod.yml stop

# Stop specific service
docker-compose -f docker-compose.prod.yml stop app

# Stop and remove containers (data volumes preserved)
docker-compose -f docker-compose.prod.yml down

# Stop and remove containers, networks, AND volumes (⚠️ DATA LOSS)
docker-compose -f docker-compose.prod.yml down -v
```

**Warning**: `down -v` will delete the MySQL database! Only use for complete teardown.

### Restart Services

```bash
# Restart all services
docker-compose -f docker-compose.prod.yml restart

# Restart specific service
docker-compose -f docker-compose.prod.yml restart app
docker-compose -f docker-compose.prod.yml restart nginx
docker-compose -f docker-compose.prod.yml restart mysql
docker-compose -f docker-compose.prod.yml restart redis
docker-compose -f docker-compose.prod.yml restart horizon
docker-compose -f docker-compose.prod.yml restart scheduler

# Restart multiple services
docker-compose -f docker-compose.prod.yml restart app nginx horizon
```

**When to Restart**:
- After changing .env file → restart `app`, `horizon`, `scheduler`
- After changing nginx config → restart `nginx`
- After MySQL configuration change → restart `mysql`
- After code changes (no rebuild) → restart `app`, `horizon`
- After clearing cache → usually no restart needed

### Rebuild Services

```bash
# Rebuild all images (after Dockerfile changes)
docker-compose -f docker-compose.prod.yml build

# Rebuild specific service
docker-compose -f docker-compose.prod.yml build app

# Rebuild and start (no cache)
docker-compose -f docker-compose.prod.yml build --no-cache
docker-compose -f docker-compose.prod.yml up -d

# Rebuild and recreate containers
docker-compose -f docker-compose.prod.yml up -d --build
```

**When to Rebuild**:
- After changing Dockerfile
- After changing docker-compose.prod.yml service definitions
- After updating base images (php:8.2-fpm-alpine, etc.)
- After changing PHP extensions installation

### View Service Status

```bash
# All services
docker-compose -f docker-compose.prod.yml ps

# Detailed status with resource usage
docker stats

# Specific container stats
docker stats paradocks-app paradocks-mysql paradocks-redis

# Check container health
docker inspect paradocks-mysql | grep -A 10 "Health"

# Process list in container
docker-compose -f docker-compose.prod.yml top app
```

### View Service Logs

```bash
# All services (follow mode)
docker-compose -f docker-compose.prod.yml logs -f

# Specific service
docker-compose -f docker-compose.prod.yml logs -f app
docker-compose -f docker-compose.prod.yml logs -f nginx
docker-compose -f docker-compose.prod.yml logs -f mysql
docker-compose -f docker-compose.prod.yml logs -f redis
docker-compose -f docker-compose.prod.yml logs -f horizon
docker-compose -f docker-compose.prod.yml logs -f scheduler

# Last N lines
docker-compose -f docker-compose.prod.yml logs --tail=100 app

# Since timestamp
docker-compose -f docker-compose.prod.yml logs --since 2025-11-11T10:00:00

# Multiple services
docker-compose -f docker-compose.prod.yml logs -f app nginx horizon
```

**Log Locations**:
- Application: `storage/logs/laravel.log`
- Docker stdout: `docker-compose logs`
- Nginx access: Docker logs (stdout)
- Nginx error: Docker logs (stderr)
- MySQL error: Docker logs + `/var/log/mysql/error.log` (in container)

---

## Individual Service Management

### Service: app (PHP-FPM)

**Purpose**: Main Laravel application, handles PHP execution

**Container**: paradocks-app
**Image**: paradocks-app:latest (custom built)
**Port**: 9000 (internal only, accessed by nginx)
**Working Directory**: /var/www/html

#### Common Operations

```bash
# Restart app
docker-compose -f docker-compose.prod.yml restart app

# View logs
docker-compose -f docker-compose.prod.yml logs -f app

# Access shell
docker-compose -f docker-compose.prod.yml exec app sh

# Run artisan commands
docker-compose -f docker-compose.prod.yml exec app php artisan <command>

# Run composer
docker-compose -f docker-compose.prod.yml exec app composer <command>

# Check PHP version
docker-compose -f docker-compose.prod.yml exec app php -v

# Check PHP configuration
docker-compose -f docker-compose.prod.yml exec app php -i

# Check loaded extensions
docker-compose -f docker-compose.prod.yml exec app php -m
```

#### Artisan Commands

```bash
# Application info
docker-compose -f docker-compose.prod.yml exec app php artisan about

# Clear caches
docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
docker-compose -f docker-compose.prod.yml exec app php artisan route:clear
docker-compose -f docker-compose.prod.yml exec app php artisan view:clear

# Rebuild caches
docker-compose -f docker-compose.prod.yml exec app php artisan config:cache
docker-compose -f docker-compose.prod.yml exec app php artisan route:cache
docker-compose -f docker-compose.prod.yml exec app php artisan view:cache

# Optimize (all caches at once)
docker-compose -f docker-compose.prod.yml exec app php artisan optimize

# Clear all caches
docker-compose -f docker-compose.prod.yml exec app php artisan optimize:clear

# Run migrations
docker-compose -f docker-compose.prod.yml exec app php artisan migrate

# Rollback migration
docker-compose -f docker-compose.prod.yml exec app php artisan migrate:rollback

# Seed database
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed

# Tinker (interactive REPL)
docker-compose -f docker-compose.prod.yml exec app php artisan tinker

# Storage link
docker-compose -f docker-compose.prod.yml exec app php artisan storage:link
```

#### Troubleshooting

**Issue**: Container won't start
```bash
# Check logs for errors
docker-compose -f docker-compose.prod.yml logs app

# Common causes:
# - Syntax error in PHP code
# - Missing environment variables
# - Permission issues with storage/
# - Database connection failure
```

**Issue**: Permission denied errors
```bash
# Fix permissions (from host)
chmod -R 775 storage bootstrap/cache
chown -R ubuntu:ubuntu storage bootstrap/cache

# Restart app
docker-compose -f docker-compose.prod.yml restart app
```

**Issue**: High memory usage
```bash
# Check current usage
docker stats paradocks-app

# Increase memory_limit in docker/php/php.ini
# memory_limit = 512M  (from 256M)

# Rebuild and restart
docker-compose -f docker-compose.prod.yml build app
docker-compose -f docker-compose.prod.yml up -d app
```

---

### Service: nginx

**Purpose**: Web server, reverse proxy, static file serving

**Container**: paradocks-nginx
**Image**: nginx:1.25-alpine
**Ports**: 80 (HTTP), 443 (HTTPS - not configured)
**Configuration**: docker/nginx/app.prod.conf

#### Common Operations

```bash
# Restart nginx
docker-compose -f docker-compose.prod.yml restart nginx

# View logs
docker-compose -f docker-compose.prod.yml logs -f nginx

# Access shell
docker-compose -f docker-compose.prod.yml exec nginx sh

# Test configuration
docker-compose -f docker-compose.prod.yml exec nginx nginx -t

# Reload configuration (without restart)
docker-compose -f docker-compose.prod.yml exec nginx nginx -s reload

# View nginx version
docker-compose -f docker-compose.prod.yml exec nginx nginx -v
```

#### After Configuration Changes

```bash
# 1. Test configuration
docker-compose -f docker-compose.prod.yml exec nginx nginx -t

# 2. If test passes, reload
docker-compose -f docker-compose.prod.yml exec nginx nginx -s reload

# OR restart the container
docker-compose -f docker-compose.prod.yml restart nginx
```

#### Troubleshooting

**Issue**: 502 Bad Gateway
```bash
# Check if app is running
docker-compose -f docker-compose.prod.yml ps app

# Check app logs for errors
docker-compose -f docker-compose.prod.yml logs app

# Check nginx error logs
docker-compose -f docker-compose.prod.yml logs nginx

# Common cause: app container down or PHP-FPM not responding
# Solution: Restart app
docker-compose -f docker-compose.prod.yml restart app
```

**Issue**: 404 Not Found
```bash
# Check nginx configuration
docker-compose -f docker-compose.prod.yml exec nginx cat /etc/nginx/conf.d/default.conf

# Verify document root
docker-compose -f docker-compose.prod.yml exec nginx ls -la /var/www/html/public

# Check Laravel routes
docker-compose -f docker-compose.prod.yml exec app php artisan route:list
```

**Issue**: Static files not loading
```bash
# Check if files exist
docker-compose -f docker-compose.prod.yml exec nginx ls -la /var/www/html/public/.vite/

# Check permissions
docker-compose -f docker-compose.prod.yml exec nginx ls -la /var/www/html/public/

# Rebuild assets
npm run build

# Restart nginx
docker-compose -f docker-compose.prod.yml restart nginx
```

---

### Service: mysql

**Purpose**: Primary database

**Container**: paradocks-mysql
**Image**: mysql:8.0
**Port**: 3306 (exposed)
**Data Volume**: paradocks_mysql-data

#### Common Operations

```bash
# Restart MySQL
docker-compose -f docker-compose.prod.yml restart mysql

# View logs
docker-compose -f docker-compose.prod.yml logs -f mysql

# Access MySQL shell (root)
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
# Password: see 03-CREDENTIALS.md

# Access MySQL shell (app user)
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks
# Password: see 03-CREDENTIALS.md

# Execute SQL query
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT COUNT(*) FROM users;" paradocks

# Database backup
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > backup_$(date +%Y%m%d_%H%M%S).sql

# Database restore
cat backup.sql | docker-compose -f docker-compose.prod.yml exec -T mysql mysql -u paradocks -p paradocks

# Check MySQL status
docker-compose -f docker-compose.prod.yml exec mysql mysqladmin -u root -p status
```

#### Database Maintenance

```bash
# Access MySQL shell as root
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p

# Show databases
SHOW DATABASES;

# Use paradocks database
USE paradocks;

# Show tables
SHOW TABLES;

# Check table status
SHOW TABLE STATUS;

# Optimize tables
OPTIMIZE TABLE users, migrations;

# Check table for errors
CHECK TABLE users;

# Repair table (if needed)
REPAIR TABLE users;

# Show current connections
SHOW PROCESSLIST;

# Show variables
SHOW VARIABLES LIKE 'max_connections';

# Show status
SHOW STATUS LIKE 'Threads_connected';
```

#### Troubleshooting

**Issue**: Can't connect to MySQL
```bash
# Check if container is running
docker-compose -f docker-compose.prod.yml ps mysql

# Check logs for errors
docker-compose -f docker-compose.prod.yml logs mysql

# Verify credentials
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks
# Enter password from 03-CREDENTIALS.md

# Test from app container
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \DB::connection()->getPdo();
```

**Issue**: Too many connections
```bash
# Check current connections
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p -e "SHOW PROCESSLIST;"

# Increase max_connections in docker/mysql/my.cnf
# max_connections=300  (from 151)

# Restart MySQL
docker-compose -f docker-compose.prod.yml restart mysql
```

**Issue**: Slow queries
```bash
# Check slow query log
docker-compose -f docker-compose.prod.yml exec mysql cat /var/log/mysql/slow-query.log

# Analyze slow queries and add indexes as needed
```

---

### Service: redis

**Purpose**: Cache, queue, session storage

**Container**: paradocks-redis
**Image**: redis:7.2-alpine
**Port**: 6379 (exposed)
**Persistence**: Disabled (cache only)

#### Common Operations

```bash
# Restart Redis
docker-compose -f docker-compose.prod.yml restart redis

# View logs
docker-compose -f docker-compose.prod.yml logs -f redis

# Access Redis CLI
docker-compose -f docker-compose.prod.yml exec redis redis-cli
127.0.0.1:6379> AUTH your_redis_password
OK

# One-line access with auth
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password

# Ping Redis
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password PING
# Response: PONG

# Get Redis info
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password INFO

# Monitor Redis commands
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password MONITOR
```

#### Redis Operations

```bash
# Access redis-cli
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password

# Select database
SELECT 0  # Default/Queue
SELECT 1  # Cache

# View all keys (⚠️ slow on large datasets)
KEYS *

# Better: Scan keys
SCAN 0 MATCH cache:* COUNT 100

# Get key value
GET cache:some_key

# Delete specific key
DEL cache:some_key

# Delete by pattern
redis-cli --scan --pattern cache:* | xargs redis-cli DEL

# Flush current database (⚠️ deletes all keys in current DB)
FLUSHDB

# Flush all databases (⚠️ deletes ALL Redis data)
FLUSHALL

# Get database size
DBSIZE

# Get memory usage
INFO memory

# Get memory usage of specific key
MEMORY USAGE cache:some_key
```

#### Troubleshooting

**Issue**: Can't connect to Redis
```bash
# Check if container is running
docker-compose -f docker-compose.prod.yml ps redis

# Check logs
docker-compose -f docker-compose.prod.yml logs redis

# Test connection
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password PING

# Test from app
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Redis::ping();
```

**Issue**: High memory usage
```bash
# Check memory usage
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password INFO memory

# Find large keys
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password --bigkeys

# Clear cache if needed
docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear
```

---

### Service: horizon (Queue Worker)

**Purpose**: Process queued jobs, monitor queue performance

**Container**: paradocks-horizon
**Image**: paradocks-app:latest
**Command**: `php artisan horizon`
**Dashboard**: http://72.60.17.138/admin/horizon

#### Common Operations

```bash
# Restart Horizon
docker-compose -f docker-compose.prod.yml restart horizon

# View logs
docker-compose -f docker-compose.prod.yml logs -f horizon

# Check Horizon status
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:status

# Pause Horizon
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:pause

# Continue Horizon
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:continue

# Terminate Horizon (gracefully)
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:terminate

# View failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:failed

# Retry failed job
docker-compose -f docker-compose.prod.yml exec app php artisan queue:retry <job-id>

# Retry all failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:retry all

# Flush failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:flush
```

#### Monitoring Queues

**Via Dashboard**:
- Access http://72.60.17.138/admin/horizon
- View real-time metrics, throughput, failed jobs
- Monitor worker processes

**Via CLI**:
```bash
# Queue status
docker-compose -f docker-compose.prod.yml exec app php artisan queue:work --once

# Queue statistics
docker-compose -f docker-compose.prod.yml exec app php artisan queue:monitor

# Queue work (manual)
docker-compose -f docker-compose.prod.yml exec app php artisan queue:work --stop-when-empty
```

#### Troubleshooting

**Issue**: Jobs not processing
```bash
# Check Horizon status
docker-compose -f docker-compose.prod.yml logs horizon

# Check if Horizon is running
docker-compose -f docker-compose.prod.yml ps horizon

# Check queue connection
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Queue::connection()->getRedis()->ping();

# Restart Horizon
docker-compose -f docker-compose.prod.yml restart horizon
```

**Issue**: Failed jobs piling up
```bash
# View failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:failed

# Check specific job details
docker-compose -f docker-compose.prod.yml exec app php artisan queue:failed <job-id>

# Retry failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:retry all

# Clear old failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:flush
```

**Issue**: Horizon not responding
```bash
# Terminate and restart
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:terminate
docker-compose -f docker-compose.prod.yml restart horizon

# Or force restart
docker-compose -f docker-compose.prod.yml restart horizon
```

---

### Service: scheduler

**Purpose**: Run Laravel scheduled tasks (replaces cron)

**Container**: paradocks-scheduler
**Image**: paradocks-app:latest
**Command**: Runs `php artisan schedule:run` every 60 seconds

#### Common Operations

```bash
# Restart scheduler
docker-compose -f docker-compose.prod.yml restart scheduler

# View logs
docker-compose -f docker-compose.prod.yml logs -f scheduler

# List scheduled tasks
docker-compose -f docker-compose.prod.yml exec app php artisan schedule:list

# Run schedule manually (test)
docker-compose -f docker-compose.prod.yml exec app php artisan schedule:run --verbose

# Check scheduler container is running
docker-compose -f docker-compose.prod.yml ps scheduler
```

#### Scheduled Tasks

**View Current Schedule**:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan schedule:list
```

**Example Output**:
```
0 0 * * * horizon:snapshot ........ Next Due: 1 day from now
```

**Add New Scheduled Task**:
Edit `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('horizon:snapshot')->everyFiveMinutes();
    $schedule->command('backup:run')->daily();
}
```

Then restart scheduler:
```bash
docker-compose -f docker-compose.prod.yml restart scheduler
```

#### Troubleshooting

**Issue**: Scheduled tasks not running
```bash
# Check scheduler logs
docker-compose -f docker-compose.prod.yml logs --tail=100 scheduler

# Check if scheduler is running
docker-compose -f docker-compose.prod.yml ps scheduler

# Run schedule manually to test
docker-compose -f docker-compose.prod.yml exec app php artisan schedule:run --verbose

# Restart scheduler
docker-compose -f docker-compose.prod.yml restart scheduler
```

**Issue**: Task runs but fails
```bash
# Check application logs
tail -f storage/logs/laravel.log

# Run the specific command manually
docker-compose -f docker-compose.prod.yml exec app php artisan <command-name>

# Check for errors
```

---

## Common Operations

### After Code Update

```bash
cd /var/www/paradocks

# 1. Pull latest code
git pull origin staging

# 2. Install/update dependencies (if composer.json changed)
docker-compose -f docker-compose.prod.yml exec app composer install --optimize-autoloader --no-dev

# 3. Rebuild assets (if frontend changed)
npm install
npm run build

# 4. Run migrations (if database changed)
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force

# 5. Clear caches
docker-compose -f docker-compose.prod.yml exec app php artisan optimize:clear

# 6. Rebuild caches
docker-compose -f docker-compose.prod.yml exec app php artisan optimize

# 7. Restart services
docker-compose -f docker-compose.prod.yml restart app horizon scheduler

# 8. Verify
docker-compose -f docker-compose.prod.yml ps
curl -I http://72.60.17.138
```

### After .env Change

```bash
# 1. Edit .env
vim .env

# 2. Clear config cache
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear

# 3. Rebuild config cache
docker-compose -f docker-compose.prod.yml exec app php artisan config:cache

# 4. Restart affected services
docker-compose -f docker-compose.prod.yml restart app horizon scheduler

# 5. Verify
docker-compose -f docker-compose.prod.yml exec app php artisan about
```

### Complete Service Restart

```bash
cd /var/www/paradocks

# Stop all services
docker-compose -f docker-compose.prod.yml down

# Start all services
docker-compose -f docker-compose.prod.yml up -d

# Verify all running
docker-compose -f docker-compose.prod.yml ps

# Check logs for errors
docker-compose -f docker-compose.prod.yml logs
```

### Health Check

```bash
# Service status
docker-compose -f docker-compose.prod.yml ps

# Resource usage
docker stats --no-stream

# Application health
curl -I http://72.60.17.138
docker-compose -f docker-compose.prod.yml exec app php artisan about

# Database health
docker-compose -f docker-compose.prod.yml exec mysql mysqladmin -u paradocks -p ping

# Redis health
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_password PING

# Horizon health
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:status

# Disk space
df -h

# Memory
free -h
```

---

## Performance Monitoring

### Real-Time Monitoring

```bash
# Container resource usage
docker stats

# System resources
htop

# Disk I/O
iotop

# Network usage
iftop
```

### Application Performance

```bash
# Laravel Horizon dashboard
# http://72.60.17.138/admin/horizon

# Database slow queries
docker-compose -f docker-compose.prod.yml exec mysql tail -f /var/log/mysql/slow-query.log

# Application logs
tail -f storage/logs/laravel.log

# Nginx access logs
docker-compose -f docker-compose.prod.yml logs -f nginx | grep "GET\|POST"
```

---

## Related Documentation

- **Server Info**: [00-SERVER-INFO.md](00-SERVER-INFO.md)
- **Configuration**: [02-CONFIGURATIONS.md](02-CONFIGURATIONS.md)
- **Troubleshooting**: [05-ISSUES-WORKAROUNDS.md](05-ISSUES-WORKAROUNDS.md)
- **Maintenance**: [06-MAINTENANCE.md](06-MAINTENANCE.md)

---

**Document Maintainer**: DevOps Team
**Last Updated**: 2025-11-11
