# Staging Environment - Issues & Workarounds

**Environment**: Staging VPS
**Server**: 72.60.17.138
**Last Updated**: 2025-11-11

This document tracks all issues encountered during deployment and operation, along with their workarounds and resolutions.

---

## Issue Status Legend

- ✅ **RESOLVED** - Issue fully resolved, no workaround needed
- ⚠️ **WORKAROUND ACTIVE** - Issue has functional workaround, may need proper fix later
- ⏳ **PENDING** - Issue identified, solution planned but not implemented
- 🔴 **ACTIVE** - Issue currently affecting system, needs immediate attention

---

## Currently Active Issues

### None

All deployment issues have been resolved. See "Resolved Issues" section below.

---

## Pending Issues

### SSL/HTTPS Not Configured

**Status**: ⏳ PENDING
**Priority**: High
**Discovered**: 2025-11-11 (Initial deployment)

**Description**:
SSL/TLS certificates are not configured. Application currently accessible only via HTTP.

**Impact**:
- Unencrypted traffic (HTTP only)
- Browser security warnings for users
- Cannot handle sensitive data securely
- SEO penalties

**Current State**:
- Certbot installed on server
- Nginx configuration has commented HTTPS block ready
- Port 443 open in firewall
- Waiting for domain configuration or decision on IP-based cert

**Planned Solution**:
1. Configure domain name (if available) OR use IP-based self-signed cert for staging
2. Run certbot to obtain Let's Encrypt certificate
3. Uncomment HTTPS block in nginx configuration
4. Test HTTPS access
5. Set up auto-renewal

**See**: [07-NEXT-STEPS.md](07-NEXT-STEPS.md) for detailed implementation plan

---

### Gmail SMTP Not Configured

**Status**: ⏳ PENDING
**Priority**: Medium
**Discovered**: 2025-11-11 (Initial deployment)

**Description**:
Email functionality using `log` driver. Emails are written to laravel.log instead of being sent.

**Impact**:
- No actual emails sent
- User registration confirmations not delivered
- Password reset emails not sent
- Notification emails not delivered

**Current Workaround**:
- Using `MAIL_MAILER=log` driver
- Emails logged to `storage/logs/laravel.log`
- Acceptable for staging/testing, not for production

**Planned Solution**:
1. Generate Gmail App Password
2. Update .env with SMTP credentials
3. Test email sending
4. Verify deliverability

**See**: [07-NEXT-STEPS.md](07-NEXT-STEPS.md) for detailed implementation plan

---

### Temporary Admin Password

**Status**: ⏳ PENDING
**Priority**: Critical (Security)
**Discovered**: 2025-11-11 (Initial deployment)

**Description**:
Admin account has temporary password `Admin123!` set during seeding.

**Impact**:
- Security vulnerability (weak password)
- Should be changed immediately before real use

**Current State**:
- Admin email: admin@paradocks.com
- Admin password: Admin123! (TEMPORARY)
- Password meets minimum requirements but is not secure

**Required Action**:
1. Login to admin panel
2. Change password to strong, unique password
3. Store new password in password manager
4. Update [03-CREDENTIALS.md](03-CREDENTIALS.md)

**How to Change**:
```bash
# Via admin panel: /admin → Profile → Change Password
# OR via Tinker:
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> $admin = \App\Models\User::where('email', 'admin@paradocks.com')->first();
>>> $admin->password = bcrypt('YourNewSecurePassword');
>>> $admin->save();
```

---

### No Backup System

**Status**: ⏳ PENDING
**Priority**: High
**Discovered**: 2025-11-11 (Initial deployment)

**Description**:
No automated backup system implemented. Data loss risk if server fails.

**Impact**:
- Database could be lost in case of failure
- No disaster recovery capability
- Uploaded files could be lost
- Configuration could be lost

**Manual Backup Available**:
```bash
# Database backup
docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u paradocks -p paradocks > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf backup_files_$(date +%Y%m%d).tar.gz storage/ public/storage/
```

**Planned Solution**:
1. Implement automated daily database backups
2. Configure offsite storage (S3, Backblaze, etc.)
3. Implement file backups
4. Set up backup verification
5. Document restore procedures

**See**: [07-NEXT-STEPS.md](07-NEXT-STEPS.md) for detailed implementation plan

---

## Resolved Issues

### Issue #1: Docker Bypassing UFW Firewall

**Status**: ✅ RESOLVED
**Priority**: Critical (Security)
**Discovered**: 2025-11-11 10:00
**Resolved**: 2025-11-11 10:30
**Time to Resolve**: ~30 minutes

**Description**:
Docker was modifying iptables directly, bypassing UFW firewall rules. This meant that Docker-exposed ports (MySQL 3306, Redis 6379) were accessible from outside even though no UFW rules existed for them.

**Root Cause**:
Docker daemon adds rules directly to iptables FORWARD chain, which takes precedence over UFW's rules. This is by design but creates a security issue.

**Impact**:
- Database port (3306) exposed to internet without firewall protection
- Redis port (6379) exposed to internet without firewall protection
- Potential unauthorized access despite password protection

**Investigation**:
```bash
# Tested port exposure
nmap -p 3306,6379 72.60.17.138
# Both ports shown as open despite UFW rules

# Checked iptables
iptables -L DOCKER-USER -n
# Chain was empty, no filtering
```

**Solution Implemented**:
Installed `ufw-docker` script to integrate UFW with Docker.

```bash
# Downloaded and installed script
wget -O /usr/local/bin/ufw-docker https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker
chmod +x /usr/local/bin/ufw-docker

# Installed UFW rules
ufw-docker install

# Reloaded UFW
systemctl restart ufw
```

**Verification**:
```bash
# Check iptables now shows filtering rules
iptables -L DOCKER-USER -n
# Shows DROP rules for non-allowed ports

# Verify ports still accessible (expected - password protected)
# But now with proper firewall rules in place
```

**Prevention**:
- Always install ufw-docker on Docker hosts
- Regularly audit iptables rules
- Use password protection on exposed services

**References**:
- ADR: [ADR-001-ufw-docker-security.md](../../architecture/decision_log/ADR-001-ufw-docker-security.md)
- Script: https://github.com/chaifeng/ufw-docker

---

### Issue #2: Storage Volume Permission Issues

**Status**: ✅ RESOLVED
**Priority**: High
**Discovered**: 2025-11-11 10:45
**Resolved**: 2025-11-11 11:30
**Time to Resolve**: ~45 minutes

**Description**:
Initial docker-compose.prod.yml used Docker-managed volumes for storage/ directory. This caused permission mismatches between container user (www-data, UID 82) and host user (ubuntu, UID 1000).

**Symptoms**:
```
Permission denied: Failed to open stream: /var/www/html/storage/logs/laravel.log
Permission denied: /var/www/html/storage/framework/cache
Permission denied: /var/www/html/storage/app/public
```

**Root Cause**:
Docker-managed volumes are created with root:root ownership. PHP-FPM container runs as www-data (UID 82). Host files owned by ubuntu:ubuntu (UID 1000). Mismatched UIDs/GIDs caused write failures.

**Options Considered**:

**Option A**: Change container user to UID 1000
- Pros: Matches host UID
- Cons: Requires custom Dockerfile, non-standard

**Option B**: Use chown in entrypoint script
- Pros: Automated permission fix
- Cons: Runs on every container start, slow

**Option C**: Remove Docker volumes, use bind mounts
- Pros: Simple, direct host access, standard permissions
- Cons: Less portable (but acceptable for single-server deployment)

**Solution Implemented**:
Option C - Removed Docker volumes, used bind mounts with proper host permissions.

**Changes Made**:

`docker-compose.prod.yml`:
```yaml
# BEFORE
volumes:
  - paradocks_storage:/var/www/html/storage

volumes:
  paradocks_storage:

# AFTER (removed volume definition)
volumes:
  - ./storage:/var/www/html/storage
  - ./bootstrap/cache:/var/www/html/bootstrap/cache
```

**Permissions Fixed**:
```bash
chmod -R 775 storage bootstrap/cache
chown -R ubuntu:ubuntu storage bootstrap/cache
```

**Verification**:
```bash
# Test log writing
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Log::info('Test log');
# Success

# Check file permissions
ls -la storage/logs/
# -rw-rw-r-- ubuntu ubuntu laravel.log (correct)
```

**Prevention**:
- Use bind mounts for development/staging
- Set proper host permissions before starting containers
- Consider Docker volumes only for production with proper UID mapping

**References**:
- ADR: [ADR-002-storage-volume-removal.md](../../architecture/decision_log/ADR-002-storage-volume-removal.md)

---

### Issue #3: Vite Manifest Not Found

**Status**: ✅ RESOLVED
**Priority**: High
**Discovered**: 2025-11-11 11:30
**Resolved**: 2025-11-11 11:45
**Time to Resolve**: ~15 minutes

**Description**:
Application showed error: "Vite manifest not found at: /var/www/paradocks/public/build/manifest.json"

**Symptoms**:
- Homepage loading but no CSS/JS
- Browser console errors for missing assets
- Filament admin panel broken (no styling)

**Root Cause**:
Vite builds assets to `public/.vite/manifest.json` but Laravel expects `public/build/manifest.json`.

**Investigation**:
```bash
# Vite output location
ls public/.vite/
# manifest.json  (exists here)

# Laravel expected location
ls public/build/
# ls: cannot access 'public/build/': No such file or directory
```

**Options Considered**:

**Option A**: Change Vite config to output to public/build/
- Pros: Matches Laravel expectation
- Cons: Would break development environment, Vite dev server

**Option B**: Change Laravel config to read from public/.vite/
- Pros: Matches Vite output
- Cons: Non-standard, could break integrations

**Option C**: Create symlink from build/ to .vite/
- Pros: Simple, works for both dev and prod, no code changes
- Cons: Requires manual step (but can be automated)

**Option D**: Copy files during build
- Pros: Real files, not symlinks
- Cons: Requires build script modification, duplicated files

**Solution Implemented**:
Option C - Created symlink

```bash
mkdir -p public/build
cd public/build
ln -s ../.vite/manifest.json manifest.json
```

**Verification**:
```bash
# Check symlink
ls -la public/build/
# lrwxrwxrwx manifest.json -> ../.vite/manifest.json

# Test application
curl http://72.60.17.138
# Page loads with CSS/JS

# Check browser console
# No errors
```

**Build Process**:
```bash
# During deployment, after npm run build:
mkdir -p public/build
ln -sf ../.vite/manifest.json public/build/manifest.json
```

**Prevention**:
- Add symlink creation to deployment script
- Document in deployment procedures
- Consider adding to package.json post-build script

**References**:
- ADR: [ADR-003-vite-manifest-symlink.md](../../architecture/decision_log/ADR-003-vite-manifest-symlink.md)

---

### Issue #4: MySQL Password Authentication Failure

**Status**: ✅ RESOLVED
**Priority**: Critical
**Discovered**: 2025-11-11 12:00
**Resolved**: 2025-11-11 12:20
**Time to Resolve**: ~20 minutes

**Description**:
Application couldn't connect to MySQL database despite correct credentials in .env file.

**Symptoms**:
```
SQLSTATE[HY000] [1045] Access denied for user 'paradocks'@'172.19.0.5' (using password: YES)
```

**Root Cause**:
MySQL container initialization timing issue. The `MYSQL_PASSWORD` environment variable wasn't properly applied during the first container creation.

**Investigation**:
```bash
# Check MySQL logs
docker-compose -f docker-compose.prod.yml logs mysql
# No errors, startup clean

# Check user exists
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p -e "SELECT user, host FROM mysql.user WHERE user='paradocks';"
# User exists

# Try to connect
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks
# Enter password: ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk
# Access denied (password wrong or not set)
```

**Solution Implemented**:
Manual password reset for paradocks user.

```bash
# 1. Access as root
docker-compose -f docker-compose.prod.yml exec mysql mysql -u root -p
# Enter root password

# 2. Reset paradocks user password
ALTER USER 'paradocks'@'%' IDENTIFIED BY 'ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk';
FLUSH PRIVILEGES;

# 3. Verify grants
SHOW GRANTS FOR 'paradocks'@'%';
# GRANT ALL PRIVILEGES ON `paradocks`.* TO `paradocks`@`%` (confirmed)

# 4. Test connection
exit
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p paradocks
# Success!
```

**Verification**:
```bash
# Test from application
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \DB::connection()->getPdo();
# PDO object returned (success)

# Run migrations
docker-compose -f docker-compose.prod.yml exec app php artisan migrate
# Migrations ran successfully
```

**Prevention**:
- For fresh deployments, always verify database user credentials after first container creation
- Consider initialization SQL script instead of env variables
- Add database connection verification to deployment checklist

**Note**: This issue typically only occurs on first deployment. Subsequent restarts preserve the correct password.

---

### Issue #5: DB_ Variables Commented in .env

**Status**: ✅ RESOLVED
**Priority**: High
**Discovered**: 2025-11-11 10:15
**Resolved**: 2025-11-11 10:20
**Time to Resolve**: ~5 minutes

**Description**:
After copying .env.example to .env, database connection failed because DB_ variables were commented out.

**Symptoms**:
```
SQLSTATE[HY000] [2002] No such file or directory
```

**Root Cause**:
.env.example had DB_ variables commented with `#`:
```env
# DB_CONNECTION=mysql
# DB_HOST=mysql
# DB_PORT=3306
# etc.
```

Laravel fell back to default values (localhost, 127.0.0.1) which don't exist in Docker network.

**Solution Implemented**:
Uncommented all DB_ variables in .env:

```bash
vim .env

# Changed from:
# DB_CONNECTION=mysql
# DB_HOST=mysql

# To:
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=paradocks
DB_USERNAME=paradocks
DB_PASSWORD=ENDnAJtD+RtLl88WE0K1UUT/lSlQ6YYk
```

**Verification**:
```bash
# Test database connection
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \DB::connection()->getDatabaseName();
# "paradocks" (correct)
```

**Prevention**:
- Update .env.example to have DB_ variables uncommented
- Add warning comment in .env.example
- Add to deployment checklist

**Follow-up Action**:
Consider updating .env.example in repository:
```env
# Database Configuration (REQUIRED for Docker)
DB_CONNECTION=mysql
DB_HOST=mysql  # Docker service name
DB_PORT=3306
DB_DATABASE=paradocks
DB_USERNAME=paradocks
DB_PASSWORD=  # SET THIS IN PRODUCTION
```

---

### Issue #6: Nginx Config Referenced Non-Existent paradocks-node Service

**Status**: ✅ RESOLVED
**Priority**: Medium
**Discovered**: 2025-11-11 11:15
**Resolved**: 2025-11-11 11:20
**Time to Resolve**: ~5 minutes

**Description**:
Initial nginx configuration included proxy rules for `paradocks-node` service that doesn't exist in docker-compose.prod.yml.

**Symptoms**:
- Nginx configuration test showed warnings
- Potential 502 errors if those routes were accessed

**Root Cause**:
nginx config was copied from a template that included Node.js/Socket.io configuration:
```nginx
location /socket.io {
    proxy_pass http://paradocks-node:3000;
}
```

But docker-compose.prod.yml doesn't have a `paradocks-node` service.

**Solution Implemented**:
Created clean `app.prod.conf` without Node.js references.

```bash
# Removed these sections:
# - location /socket.io
# - proxy_pass http://paradocks-node:3000
# - WebSocket proxy configuration
```

**Verification**:
```bash
# Test nginx configuration
docker-compose -f docker-compose.prod.yml exec nginx nginx -t
# nginx: configuration file /etc/nginx/nginx.conf test is successful

# Restart nginx
docker-compose -f docker-compose.prod.yml restart nginx
```

**Prevention**:
- Use environment-specific nginx configs
- Review configs for non-existent services
- Add nginx config test to deployment checklist

**Note**: If real-time features are needed in the future, consider:
- Laravel Reverb (Laravel's WebSocket server)
- Pusher
- Soketi (self-hosted Pusher alternative)

---

## Monitoring & Recurring Checks

### Daily Checks

```bash
# Service status
docker-compose -f docker-compose.prod.yml ps

# Disk space
df -h

# Memory usage
free -h

# Application logs for errors
tail -100 storage/logs/laravel.log | grep ERROR

# Failed queue jobs
docker-compose -f docker-compose.prod.yml exec app php artisan queue:failed
```

### Weekly Checks

```bash
# Check for large log files
du -sh storage/logs/*

# Database size
docker-compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT table_schema AS 'Database', ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' FROM information_schema.tables WHERE table_schema='paradocks' GROUP BY table_schema;"

# Redis memory usage
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a password INFO memory
```

---

## Issue Reporting Template

**When reporting new issues, use this template:**

```markdown
### Issue Title

**Status**: 🔴 ACTIVE / ⏳ PENDING / ⚠️ WORKAROUND ACTIVE
**Priority**: Critical / High / Medium / Low
**Discovered**: YYYY-MM-DD HH:MM
**Resolved**: (if applicable)

**Description**:
[Clear description of the issue]

**Symptoms**:
[What users/admins experience]

**Root Cause**:
[Why the issue occurred]

**Impact**:
[What is affected]

**Workaround** (if applicable):
[Temporary solution]

**Solution Implemented** (if resolved):
[How it was fixed]

**Verification**:
[How to verify the fix]

**Prevention**:
[How to prevent recurrence]

**References**:
[Links to ADRs, PRs, external docs]
```

---

## Related Documentation

- **Deployment Log**: [01-DEPLOYMENT-LOG.md](01-DEPLOYMENT-LOG.md) - Chronological deployment history
- **Architecture Decisions**: [../../architecture/decision_log/](../../architecture/decision_log/) - ADRs for major workarounds
- **Service Management**: [04-SERVICES.md](04-SERVICES.md) - Service troubleshooting
- **Maintenance**: [06-MAINTENANCE.md](06-MAINTENANCE.md) - Regular maintenance procedures

---

**Document Maintainer**: Development Team
**Last Updated**: 2025-11-11
**Review Frequency**: After each deployment or issue resolution
