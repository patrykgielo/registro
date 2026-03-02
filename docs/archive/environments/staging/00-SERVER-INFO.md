# Staging Server - Quick Reference

**Environment**: Staging
**Last Updated**: 2025-11-11
**Status**: Active and Healthy

---

## Emergency Access

### SSH Access

```bash
ssh ubuntu@72.60.17.138
```

**Port**: 22 (default)
**User**: ubuntu
**Auth**: SSH key (password disabled)

### Quick Health Check

```bash
# All services status
ssh ubuntu@72.60.17.138 "cd /var/www/paradocks && docker-compose -f docker-compose.prod.yml ps"

# Application logs (last 50 lines)
ssh ubuntu@72.60.17.138 "cd /var/www/paradocks && docker-compose -f docker-compose.prod.yml logs --tail=50 app"
```

---

## Server Details

| Property | Value |
|----------|-------|
| **IP Address** | 72.60.17.138 |
| **Hostname** | paradocks.pl (old: srv1117368.hstgr.cloud) |
| **OS** | Ubuntu 24.04 LTS (Noble Numbat) |
| **Kernel** | Linux 6.14.0-1015-oem |
| **RAM** | 2GB + 2GB Swap |
| **Timezone** | Europe/Warsaw |
| **Deployed** | 2025-11-11 |

---

## Application Information

| Property | Value |
|----------|-------|
| **Project Path** | `/var/www/paradocks` |
| **Branch** | `staging` (NOT main!) |
| **Laravel Version** | 12.32.5 |
| **PHP Version** | 8.2.29 |
| **APP_ENV** | production |
| **APP_DEBUG** | false |
| **APP_URL** | http://72.60.17.138 |

---

## Service Ports

| Service | Internal Port | External Port | Status |
|---------|--------------|---------------|--------|
| Nginx (HTTP) | 80 | 80 | Open (UFW) |
| Nginx (HTTPS) | 443 | 443 | Open (UFW, not configured) |
| PHP-FPM | 9000 | - | Internal only |
| MySQL | 3306 | 3306 | Exposed (password protected) |
| Redis | 6379 | 6379 | Exposed (password protected) |
| SSH | 22 | 22 | Open (UFW, key auth only) |

---

## Quick Commands

### Service Management

```bash
# Navigate to project
cd /var/www/paradocks

# View all containers status
docker-compose -f docker-compose.prod.yml ps

# Restart all services
docker-compose -f docker-compose.prod.yml restart

# Restart specific service
docker-compose -f docker-compose.prod.yml restart app
docker-compose -f docker-compose.prod.yml restart nginx
docker-compose -f docker-compose.prod.yml restart mysql

# View logs (follow mode)
docker-compose -f docker-compose.prod.yml logs -f

# View specific service logs
docker-compose -f docker-compose.prod.yml logs -f app
docker-compose -f docker-compose.prod.yml logs -f nginx
docker-compose -f docker-compose.prod.yml logs -f mysql
docker-compose -f docker-compose.prod.yml logs -f horizon
```

### Application Commands

```bash
# Laravel Artisan (from host)
docker-compose -f docker-compose.prod.yml exec app php artisan [command]

# Enter app container
docker-compose -f docker-compose.prod.yml exec app sh

# Run migrations
docker-compose -f docker-compose.prod.yml exec app php artisan migrate

# Clear caches
docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
docker-compose -f docker-compose.prod.yml exec app php artisan route:clear
docker-compose -f docker-compose.prod.yml exec app php artisan view:clear

# Optimize application
docker-compose -f docker-compose.prod.yml exec app php artisan optimize

# Queue management
docker-compose -f docker-compose.prod.yml exec app php artisan queue:work
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:status
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:pause
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:continue
```

### Database Access

```bash
# From server (via Docker)
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks

# From external machine
mysql -h 72.60.17.138 -P 3306 -u paradocks -p paradocks

# MySQL root access (if needed)
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p

# Database dump
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > backup_$(date +%Y%m%d).sql

# Restore database
cat backup.sql | docker-compose -f docker-compose.prod.yml exec -T mysql mysql -u paradocks -p paradocks
```

### Redis Access

```bash
# From server (via Docker)
docker-compose -f docker-compose.prod.yml exec redis redis-cli

# Authenticate in redis-cli
AUTH your_redis_password

# From external machine
redis-cli -h 72.60.17.138 -p 6379 -a your_redis_password

# Clear Redis cache
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password FLUSHDB

# Monitor Redis
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a your_redis_password MONITOR
```

---

## File Locations

### Configuration Files

```
/var/www/paradocks/
├── .env                                    # Environment variables (NOT committed)
├── docker-compose.prod.yml                 # Production Docker configuration
├── docker/
│   ├── nginx/
│   │   └── app.prod.conf                   # Nginx server configuration
│   └── php/
│       └── php.ini                         # PHP configuration
├── config/                                 # Laravel configuration files
│   ├── app.php                            # Application settings
│   ├── database.php                       # Database connections
│   ├── cache.php                          # Cache configuration (Redis)
│   ├── queue.php                          # Queue configuration (Redis)
│   ├── session.php                        # Session configuration (Redis)
│   ├── filament.php                       # Filament admin panel
│   └── horizon.php                        # Laravel Horizon
└── storage/
    └── logs/
        └── laravel.log                    # Application logs
```

### Important Paths

| Purpose | Path |
|---------|------|
| Application root | `/var/www/paradocks` |
| Public files | `/var/www/paradocks/public` |
| Storage | `/var/www/paradocks/storage` |
| Logs | `/var/www/paradocks/storage/logs` |
| Vite assets | `/var/www/paradocks/public/.vite` |
| Environment file | `/var/www/paradocks/.env` |

---

## Access URLs

### Web Application

- **HTTP**: http://72.60.17.138
- **HTTPS**: Not configured yet (pending SSL setup)

### Admin Panel

- **URL**: http://72.60.17.138/admin
- **Credentials**: See [03-CREDENTIALS.md](03-CREDENTIALS.md)

**Current Credentials** (TEMPORARY - MUST CHANGE):
- Email: admin@paradocks.com
- Password: Admin123!

### Laravel Horizon

- **URL**: http://72.60.17.138/admin/horizon
- **Auth**: Requires admin login

---

## Firewall Rules

**Status**: Active (UFW + ufw-docker)

```bash
# Check firewall status
sudo ufw status verbose

# Current rules
To                         Action      From
--                         ------      ----
22/tcp                     ALLOW       Anywhere
80/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere
```

**Important**: UFW-Docker script installed to prevent Docker from bypassing firewall rules.
See: [../../architecture/decision_log/ADR-001-ufw-docker-security.md](../../architecture/decision_log/ADR-001-ufw-docker-security.md)

---

## Docker Containers

```bash
# Container status check
docker-compose -f docker-compose.prod.yml ps
```

**Expected Output**:

```
NAME                   STATUS    PORTS
paradocks-app          Up        9000/tcp
paradocks-nginx        Up        0.0.0.0:80->80/tcp, 0.0.0.0:443->443/tcp
paradocks-mysql        Up        0.0.0.0:3306->3306/tcp, 33060/tcp
paradocks-redis        Up        0.0.0.0:6379->6379/tcp
paradocks-horizon      Up        (no ports)
paradocks-scheduler    Up        (no ports)
```

**Health Check**:
```bash
# All should show "Up" status
docker-compose -f docker-compose.prod.yml ps

# Detailed health info
docker inspect paradocks-mysql | grep -A 10 "Health"
```

---

## System Resources

### Check System Resources

```bash
# Memory usage
free -h

# Disk usage
df -h

# Docker disk usage
docker system df

# Container resource usage (live)
docker stats

# Specific container stats
docker stats paradocks-app paradocks-mysql paradocks-redis
```

### Expected Resource Usage

| Container | CPU (avg) | Memory (avg) | Notes |
|-----------|-----------|--------------|-------|
| app | 1-5% | 100-200 MB | Spikes during requests |
| nginx | <1% | 10-20 MB | Very light |
| mysql | 1-10% | 200-400 MB | Depends on queries |
| redis | <1% | 10-50 MB | In-memory cache |
| horizon | 1-5% | 50-100 MB | Queue processing |
| scheduler | <1% | 20-40 MB | Runs every minute |

**Total Expected**: ~600-900 MB out of 2GB RAM (comfortable margin)

---

## Known Issues & Workarounds

See detailed documentation: [05-ISSUES-WORKAROUNDS.md](05-ISSUES-WORKAROUNDS.md)

**Quick Summary**:

1. ✅ **RESOLVED**: UFW-Docker security - ufw-docker script installed
2. ✅ **RESOLVED**: Storage volume permissions - removed Docker volumes, using bind mounts
3. ✅ **RESOLVED**: Vite manifest not found - created symlink
4. ✅ **RESOLVED**: MySQL password authentication - manual reset performed
5. ⏳ **PENDING**: SSL/HTTPS configuration (Certbot installed, not configured)
6. ⏳ **PENDING**: Gmail SMTP configuration (requires app password)

---

## Emergency Procedures

### Application Not Responding

```bash
# 1. Check container status
docker-compose -f docker-compose.prod.yml ps

# 2. Check application logs
docker-compose -f docker-compose.prod.yml logs --tail=100 app

# 3. Check nginx logs
docker-compose -f docker-compose.prod.yml logs --tail=100 nginx

# 4. Restart application
docker-compose -f docker-compose.prod.yml restart app nginx

# 5. If still failing, full restart
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d
```

### Database Connection Issues

```bash
# 1. Check MySQL container
docker-compose -f docker-compose.prod.yml ps mysql

# 2. Check MySQL logs
docker-compose -f docker-compose.prod.yml logs mysql

# 3. Test connection
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT 1;"

# 4. Restart MySQL (WARNING: Brief downtime)
docker-compose -f docker-compose.prod.yml restart mysql
```

### Queue Not Processing

```bash
# 1. Check Horizon status
docker-compose -f docker-compose.prod.yml logs horizon

# 2. Check failed jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:failed

# 3. Restart Horizon
docker-compose -f docker-compose.prod.yml restart horizon

# 4. Clear and restart queues
docker-compose -f docker-compose.prod.yml exec app php artisan queue:restart
docker-compose -f docker-compose.prod.yml restart horizon
```

### Full System Restart

```bash
# Complete restart (WARNING: Brief downtime)
cd /var/www/paradocks
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d

# Verify all services are running
docker-compose -f docker-compose.prod.yml ps

# Check logs for errors
docker-compose -f docker-compose.prod.yml logs
```

### Rollback Procedure

```bash
# 1. Stop services
docker-compose -f docker-compose.prod.yml down

# 2. Switch to previous commit/branch
git checkout <previous-commit-hash>

# 3. Rebuild if needed
docker-compose -f docker-compose.prod.yml build

# 4. Start services
docker-compose -f docker-compose.prod.yml up -d

# 5. Run migrations (down if needed)
docker-compose -f docker-compose.prod.yml exec app php artisan migrate:rollback
```

---

## Monitoring

### Real-time Monitoring

```bash
# System resources
htop

# Docker containers
docker stats

# Application logs (follow)
docker-compose -f docker-compose.prod.yml logs -f app

# Nginx access logs
docker-compose -f docker-compose.prod.yml logs -f nginx

# All services logs
docker-compose -f docker-compose.prod.yml logs -f
```

### Laravel Horizon Dashboard

- **URL**: http://72.60.17.138/admin/horizon
- **Features**: Queue metrics, failed jobs, worker stats

---

## Security Notes

- **SSH**: Key authentication only, password auth disabled
- **Firewall**: UFW active with minimal ports open
- **Database**: Password protected, exposed for development tools only
- **Redis**: Password protected, exposed for development tools only
- **HTTPS**: Not configured yet (pending)
- **Admin Password**: TEMPORARY - MUST be changed immediately

**Credentials**: See [03-CREDENTIALS.md](03-CREDENTIALS.md) (NOT committed to Git)

---

## Next Steps

See: [07-NEXT-STEPS.md](07-NEXT-STEPS.md)

**High Priority**:
- [ ] Configure SSL/HTTPS with Let's Encrypt
- [ ] Change admin password
- [ ] Configure Gmail SMTP
- [ ] Implement backup system

---

## Related Documentation

- **Deployment Log**: [01-DEPLOYMENT-LOG.md](01-DEPLOYMENT-LOG.md)
- **Full Configuration**: [02-CONFIGURATIONS.md](02-CONFIGURATIONS.md)
- **Credentials**: [03-CREDENTIALS.md](03-CREDENTIALS.md)
- **Service Management**: [04-SERVICES.md](04-SERVICES.md)
- **Troubleshooting**: [05-ISSUES-WORKAROUNDS.md](05-ISSUES-WORKAROUNDS.md)
- **Maintenance**: [06-MAINTENANCE.md](06-MAINTENANCE.md)

---

**Document Owner**: DevOps Team
**Emergency Contact**: (Add contact information)
**Last Verified**: 2025-11-11
