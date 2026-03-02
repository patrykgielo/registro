# Staging Environment - Maintenance Procedures

**Environment**: Staging VPS
**Server**: 72.60.17.138
**Last Updated**: 2025-11-11

This document outlines all regular maintenance procedures required to keep the staging server running smoothly and securely.

---

## Maintenance Schedule

| Task | Frequency | Estimated Time | Priority |
|------|-----------|----------------|----------|
| Health Check | Daily | 5 minutes | High |
| Log Rotation | Weekly | 10 minutes | Medium |
| Database Optimization | Weekly | 15 minutes | Medium |
| System Updates | Weekly | 30 minutes | High |
| Security Audit | Monthly | 60 minutes | High |
| Full Backup | Weekly | 20 minutes | High |
| Disk Cleanup | Monthly | 15 minutes | Medium |
| Performance Review | Monthly | 30 minutes | Medium |

---

## Daily Maintenance

### Daily Health Check

**Time Required**: ~5 minutes
**Schedule**: Once per day, morning
**Priority**: High

**Checklist**:

```bash
# 1. Check all services are running
cd /var/www/paradocks
docker-compose -f docker-compose.prod.yml ps
# Expected: All services "Up"

# 2. Check application is accessible
curl -I http://72.60.17.138
# Expected: HTTP/1.1 200 OK

# 3. Check disk space
df -h
# Expected: Root partition <80% used

# 4. Check memory usage
free -h
# Expected: <90% memory used (include swap)

# 5. Check for errors in logs (last hour)
tail -100 storage/logs/laravel.log | grep -i error
# Expected: No critical errors

# 6. Check failed queue jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:failed | head -20
# Expected: None or only old failed jobs

# 7. Check Horizon status
docker-compose -f docker-compose.prod.yml exec app php artisan horizon:status
# Expected: "Horizon is running."

# 8. Check system load
uptime
# Expected: Load average reasonable for 2-core system (<2.0)
```

**If Any Check Fails**:
1. Review detailed logs: `docker-compose -f docker-compose.prod.yml logs [service]`
2. Check [05-ISSUES-WORKAROUNDS.md](05-ISSUES-WORKAROUNDS.md) for known issues
3. Restart affected service if needed
4. Document new issues if not previously seen

---

## Weekly Maintenance

### Week 1: Log Rotation and Cleanup

**Time Required**: ~10 minutes
**Schedule**: Every Monday
**Priority**: Medium

**Procedure**:

```bash
cd /var/www/paradocks

# 1. Check log file sizes
du -sh storage/logs/*

# 2. Archive old Laravel logs (keep last 7 days)
cd storage/logs
for file in laravel-*.log; do
    if [ -f "$file" ]; then
        # Compress logs older than 7 days
        find . -name "laravel-*.log" -mtime +7 -exec gzip {} \;
    fi
done

# 3. Remove compressed logs older than 30 days
find storage/logs/ -name "*.gz" -mtime +30 -delete

# 4. Check current log file size
ls -lh storage/logs/laravel.log
# If >100MB, consider rotating:
# mv storage/logs/laravel.log storage/logs/laravel-$(date +%Y%m%d).log
# touch storage/logs/laravel.log
# chmod 664 storage/logs/laravel.log

# 5. Clear old Docker logs (optional)
docker system prune -f
# Removes: stopped containers, unused networks, dangling images

# 6. Verify log rotation didn't break anything
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Log::info('Test after log rotation');
>>> exit

tail storage/logs/laravel.log
# Should show test message
```

### Week 2: Database Optimization

**Time Required**: ~15 minutes
**Schedule**: Every other Monday
**Priority**: Medium

**Procedure**:

```bash
cd /var/www/paradocks

# 1. Backup database first (safety)
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > backup_pre_optimization_$(date +%Y%m%d).sql

# 2. Access MySQL
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks

# 3. Run optimization queries
USE paradocks;

# Check table status
SHOW TABLE STATUS;

# Optimize all tables
OPTIMIZE TABLE users;
OPTIMIZE TABLE migrations;
OPTIMIZE TABLE jobs;
OPTIMIZE TABLE failed_jobs;
# ... optimize other tables as needed

# Alternative: Optimize all tables at once
SELECT CONCAT('OPTIMIZE TABLE ', table_name, ';')
FROM information_schema.tables
WHERE table_schema='paradocks';
# Copy and paste output to run all OPTIMIZE commands

# 4. Check for table errors
CHECK TABLE users;
CHECK TABLE migrations;
# Expected: status = OK

# 5. Analyze tables for query optimization
ANALYZE TABLE users;
ANALYZE TABLE migrations;

# 6. Review index usage (if performance issues)
SHOW INDEX FROM users;

# 7. Check database size
SELECT
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
FROM information_schema.tables
WHERE table_schema='paradocks'
GROUP BY table_schema;

exit;

# 8. Verify application still works
curl http://72.60.17.138/admin
# Should load without errors
```

**Cleanup Old Data** (if applicable):

```bash
# Remove old failed jobs (older than 30 days)
docker-compose -f docker-compose.prod.yml exec app php artisan queue:prune-failed --hours=720

# Clear old session data (if using database sessions)
# docker-compose -f docker-compose.prod.yml exec app php artisan session:gc
# (Not needed - using Redis for sessions)

# Clear old notifications (if applicable)
# Add custom cleanup commands here
```

### Week 3: System Updates

**Time Required**: ~30 minutes
**Schedule**: Third Monday of each month
**Priority**: High

**Procedure**:

```bash
# IMPORTANT: Perform during low-traffic period (e.g., early morning)

# 1. Backup before updates
cd /var/www/paradocks
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > backup_pre_update_$(date +%Y%m%d).sql
tar -czf backup_files_$(date +%Y%m%d).tar.gz .env docker-compose.prod.yml

# 2. Update system packages
sudo apt update
sudo apt upgrade -y

# 3. Check for Docker updates
docker --version
# Check https://docs.docker.com/engine/release-notes/ for latest version
# If update available:
# sudo apt update
# sudo apt install docker-ce docker-ce-cli containerd.io

# 4. Update Docker images (use cautiously)
# Pull latest base images (only if needed)
# docker pull php:8.2-fpm-alpine
# docker pull nginx:1.25-alpine
# docker pull mysql:8.0
# docker pull redis:7.2-alpine

# 5. Rebuild application images (if base images updated)
# docker-compose -f docker-compose.prod.yml build --no-cache

# 6. Update Composer dependencies (patch versions only)
docker-compose -f docker-compose.prod.yml exec app composer update --prefer-stable --no-dev

# 7. Update NPM packages (if needed - CAUTION)
# npm update  # Only for patch versions
# npm audit fix  # Fix security vulnerabilities

# 8. Run migrations (if any)
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force

# 9. Clear and rebuild caches
docker-compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker-compose -f docker-compose.prod.yml exec app php artisan optimize

# 10. Restart services
docker-compose -f docker-compose.prod.yml restart

# 11. Verify everything works
docker-compose -f docker-compose.prod.yml ps
curl -I http://72.60.17.138
docker-compose -f docker-compose.prod.yml exec app php artisan about

# 12. Monitor for issues
docker-compose -f docker-compose.prod.yml logs -f --tail=50
# Watch for ~5 minutes for any errors

# 13. Reboot if kernel updated
sudo reboot
# Wait 2 minutes, then verify services auto-started
```

### Week 4: Full Backup

**Time Required**: ~20 minutes
**Schedule**: Last Monday of each month (or weekly if critical)
**Priority**: High

**Procedure**:

```bash
cd /var/www/paradocks

# Create backup directory
sudo mkdir -p /backups/paradocks
BACKUP_DIR="/backups/paradocks/backup_$(date +%Y%m%d_%H%M%S)"
sudo mkdir -p "$BACKUP_DIR"

# 1. Database backup
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks | sudo tee "$BACKUP_DIR/database.sql" > /dev/null

# 2. Environment file
sudo cp .env "$BACKUP_DIR/env.txt"

# 3. Docker configuration
sudo cp docker-compose.prod.yml "$BACKUP_DIR/"

# 4. Application files
sudo tar -czf "$BACKUP_DIR/storage.tar.gz" storage/
sudo tar -czf "$BACKUP_DIR/public-storage.tar.gz" public/storage/

# 5. Nginx configuration
sudo cp docker/nginx/app.prod.conf "$BACKUP_DIR/"

# 6. PHP configuration
sudo cp docker/php/php.ini "$BACKUP_DIR/"

# 7. Create backup manifest
cat > "$BACKUP_DIR/MANIFEST.txt" << EOF
Backup Date: $(date)
Server: 72.60.17.138
Environment: Staging
Branch: staging
Commit: $(git rev-parse HEAD)
Laravel Version: $(docker-compose -f docker-compose.prod.yml exec app php artisan --version)

Files Included:
- database.sql (MySQL dump)
- env.txt (Environment variables)
- docker-compose.prod.yml
- storage.tar.gz (Storage directory)
- public-storage.tar.gz (Public uploads)
- app.prod.conf (Nginx config)
- php.ini (PHP config)
EOF

# 8. Set permissions
sudo chown -R ubuntu:ubuntu "$BACKUP_DIR"
sudo chmod -R 600 "$BACKUP_DIR"/*

# 9. Compress entire backup
cd /backups/paradocks
sudo tar -czf "backup_$(date +%Y%m%d_%H%M%S).tar.gz" "backup_$(date +%Y%m%d_%H%M%S)/"
sudo rm -rf "backup_$(date +%Y%m%d_%H%M%S)/"

# 10. Verify backup
ls -lh /backups/paradocks/

# 11. Upload to remote storage (when configured)
# aws s3 cp backup_*.tar.gz s3://your-bucket/paradocks/staging/
# OR
# rclone copy backup_*.tar.gz remote:paradocks/staging/

# 12. Remove old backups (keep last 4 weekly backups = ~1 month)
cd /backups/paradocks
ls -t backup_*.tar.gz | tail -n +5 | xargs -r sudo rm

# 13. Document backup
echo "$(date): Backup completed - $BACKUP_DIR" | sudo tee -a /backups/paradocks/backup.log
```

**Backup Restoration Test** (quarterly):

```bash
# Test restoration process (on test environment)
# 1. Extract backup
# 2. Restore database
# 3. Restore files
# 4. Verify application works
# Document any issues found
```

---

## Monthly Maintenance

### Security Audit

**Time Required**: ~60 minutes
**Schedule**: First day of each month
**Priority**: High

**Checklist**:

```bash
# 1. Review failed SSH login attempts
sudo grep "Failed password" /var/log/auth.log | tail -50

# 2. Check for rootkit
sudo apt install -y rkhunter
sudo rkhunter --update
sudo rkhunter --check --skip-keypress

# 3. Check for security updates
sudo apt update
sudo apt list --upgradable | grep -i security

# 4. Review UFW rules
sudo ufw status verbose
sudo iptables -L -n -v

# 5. Check open ports
sudo netstat -tulpn

# 6. Review Docker security
docker ps --format "table {{.Names}}\t{{.Ports}}"
# Verify only necessary ports are exposed

# 7. Check file permissions
find /var/www/paradocks -type f -perm 0777
# Should be empty (no world-writable files)

find /var/www/paradocks -type d -perm 0777
# Should be empty (no world-writable directories)

# 8. Review application logs for suspicious activity
grep -i "unauthorized\|forbidden\|injection\|attack" storage/logs/laravel.log | tail -100

# 9. Check failed login attempts (application level)
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT * FROM paradocks.failed_jobs ORDER BY failed_at DESC LIMIT 20;"

# 10. Review user accounts
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT id, email, created_at, last_login_at FROM paradocks.users;"

# 11. Check SSL certificate expiry (when configured)
# sudo certbot certificates

# 12. Review environment variables for exposed secrets
grep -i "password\|secret\|key" .env
# Ensure no placeholder values, all strong passwords

# 13. Verify backup encryption (when implemented)
# Check backup files are encrypted and secure

# 14. Review access logs for suspicious patterns
docker-compose -f docker-compose.prod.yml exec nginx tail -1000 /var/log/nginx/access.log | awk '{print $1}' | sort | uniq -c | sort -rn | head -20
# Check for unusual IP addresses or patterns
```

### Disk Cleanup

**Time Required**: ~15 minutes
**Schedule**: 15th of each month
**Priority**: Medium

**Procedure**:

```bash
# 1. Check disk usage
df -h
du -sh /var/www/paradocks/*
du -sh /var/lib/docker/*

# 2. Clean Docker system
docker system df
docker system prune -a -f --volumes
# WARNING: This removes unused images and volumes

# 3. Clean old logs
find /var/log -type f -name "*.gz" -mtime +30 -delete
sudo journalctl --vacuum-time=30d

# 4. Clean package cache
sudo apt-get clean
sudo apt-get autoclean
sudo apt-get autoremove -y

# 5. Clean old kernels (keep current and one previous)
sudo apt-get autoremove --purge -y

# 6. Clean tmp files
sudo find /tmp -type f -mtime +7 -delete

# 7. Clean Laravel cache (if safe)
# docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear

# 8. Verify sufficient space remains
df -h
# Ensure at least 20% free space
```

### Performance Review

**Time Required**: ~30 minutes
**Schedule**: Last day of each month
**Priority**: Medium

**Metrics to Collect**:

```bash
cd /var/www/paradocks

# 1. Server resource usage trends
free -h
df -h
uptime

# 2. Docker container stats
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}"

# 3. Database metrics
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SHOW GLOBAL STATUS LIKE 'Threads_connected'; SHOW GLOBAL STATUS LIKE 'Queries'; SHOW GLOBAL STATUS LIKE 'Slow_queries';"

# 4. Database size growth
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT table_schema AS 'Database', ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' FROM information_schema.tables WHERE table_schema='paradocks' GROUP BY table_schema;"

# 5. Redis memory usage
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a password INFO memory | grep used_memory_human

# 6. Queue performance
# Access http://72.60.17.138/admin/horizon
# Review: Job throughput, wait times, failed jobs

# 7. Slow query log review
docker-compose -f docker-compose.prod.yml exec mysql tail -50 /var/log/mysql/slow-query.log

# 8. Application response time
time curl -I http://72.60.17.138
# Should be <500ms

# 9. Check for memory leaks
docker stats --no-stream paradocks-app paradocks-horizon
# Compare with previous month, look for growth trend

# 10. Review error rates
grep -c "ERROR" storage/logs/laravel.log
# Compare with previous month
```

**Document Findings**:
- Record metrics in spreadsheet or monitoring tool
- Identify trends (growing database, increasing errors, etc.)
- Plan optimizations if needed

---

## Emergency Procedures

### Service Restart (Unresponsive Application)

```bash
cd /var/www/paradocks

# Quick restart
docker-compose -f docker-compose.prod.yml restart app nginx

# If that fails, full restart
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d

# If still failing, check logs
docker-compose -f docker-compose.prod.yml logs --tail=100
```

### Database Corruption Recovery

```bash
# 1. Stop application
docker-compose -f docker-compose.prod.yml stop app horizon scheduler

# 2. Backup current state
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > emergency_backup_$(date +%Y%m%d_%H%M%S).sql

# 3. Repair tables
docker-compose -f docker-compose.prod.yml exec mysql mysqlcheck -u paradocks -p --auto-repair paradocks

# 4. Restart services
docker-compose -f docker-compose.prod.yml start app horizon scheduler

# 5. Verify
docker-compose -f docker-compose.prod.yml exec app php artisan migrate:status
```

### Disk Full Recovery

```bash
# 1. Find large files
du -ah / | sort -rh | head -20

# 2. Clean Docker
docker system prune -a -f --volumes

# 3. Clean logs
sudo find /var/log -type f -name "*.log" -exec truncate -s 0 {} \;
sudo journalctl --vacuum-size=100M

# 4. Clean Laravel logs
> storage/logs/laravel.log

# 5. Verify space
df -h
```

---

## Maintenance Log

**Keep a record of all maintenance activities**:

```bash
# Create maintenance log
echo "$(date): [TASK] Description of maintenance performed" >> /var/www/paradocks/maintenance.log

# Example entries:
# 2025-11-11 09:00: [DAILY] Health check - all services OK
# 2025-11-11 10:00: [WEEKLY] Log rotation - removed 3 old compressed logs
# 2025-11-11 15:00: [EMERGENCY] Restarted app service due to high memory usage
```

---

## Monitoring & Alerts (Future)

**Recommended Monitoring Setup** (not yet implemented):

1. **Uptime Monitoring**
   - UptimeRobot (free tier)
   - Monitor: http://72.60.17.138
   - Alert: Email/SMS if down >5 minutes

2. **Resource Alerts**
   - Email when disk >85% full
   - Email when memory >90% used
   - Email when services fail

3. **Application Monitoring**
   - Laravel Telescope (development)
   - Sentry (error tracking)
   - New Relic / Datadog (APM)

4. **Log Aggregation**
   - Papertrail
   - Loggly
   - ELK Stack

See: [07-NEXT-STEPS.md](07-NEXT-STEPS.md) for implementation plans

---

## Related Documentation

- **Server Info**: [00-SERVER-INFO.md](00-SERVER-INFO.md)
- **Service Management**: [04-SERVICES.md](04-SERVICES.md)
- **Issues & Workarounds**: [05-ISSUES-WORKAROUNDS.md](05-ISSUES-WORKAROUNDS.md)
- **Next Steps**: [07-NEXT-STEPS.md](07-NEXT-STEPS.md)

---

**Document Maintainer**: DevOps Team
**Last Updated**: 2025-11-11
**Next Review**: 2025-12-11
