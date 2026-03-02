# Staging Environment - Next Steps

**Environment**: Staging VPS
**Server**: 72.60.17.138
**Last Updated**: 2025-11-11

This document tracks all pending tasks, improvements, and future enhancements for the staging environment.

---

## Task Status Legend

- [ ] **TODO** - Not started
- [x] **DONE** - Completed
- [⏳] **IN PROGRESS** - Currently being worked on
- [⚠️] **BLOCKED** - Waiting on external dependency

---

## High Priority Tasks

### Security & Access

#### [ ] Configure SSL/HTTPS with Let's Encrypt

**Priority**: Critical
**Effort**: 30 minutes
**Dependencies**: Domain name decision (or use IP-based)

**Description**:
Configure SSL certificates to enable HTTPS access. Currently, the application is only accessible via HTTP, which is insecure for production use.

**Implementation Steps**:

```bash
# Option A: With Domain Name (recommended)
# 1. Point domain to server
# Update DNS A record: staging.paradocks.com → 72.60.17.138

# 2. Verify DNS propagation
dig staging.paradocks.com +short
# Should show: 72.60.17.138

# 3. Obtain certificate
sudo certbot certonly --nginx -d staging.paradocks.com

# 4. Update nginx configuration
# Edit docker/nginx/app.prod.conf
# Uncomment HTTPS server block
# Update server_name to staging.paradocks.com
# Update certificate paths

# 5. Restart nginx
docker-compose -f docker-compose.prod.yml restart nginx

# 6. Test HTTPS
curl -I https://staging.paradocks.com

# 7. Update .env
APP_URL=https://staging.paradocks.com

# 8. Restart application
docker-compose -f docker-compose.prod.yml restart app

# Option B: IP-Based Self-Signed (for testing only)
# Create self-signed certificate for 72.60.17.138
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/ssl/private/nginx-selfsigned.key \
  -out /etc/ssl/certs/nginx-selfsigned.crt \
  -subj "/CN=72.60.17.138"

# Update nginx config with self-signed paths
# Note: Browser will show security warning
```

**Auto-Renewal Setup**:
```bash
# Certbot sets up auto-renewal via systemd timer
sudo systemctl status certbot.timer

# Test renewal
sudo certbot renew --dry-run

# Renewal command (automatic):
# /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh
```

**Acceptance Criteria**:
- [ ] HTTPS accessible without errors
- [ ] HTTP redirects to HTTPS
- [ ] Auto-renewal configured
- [ ] .env APP_URL updated
- [ ] Documentation updated

---

#### [ ] Change Admin Password from Temporary Value

**Priority**: Critical (Security)
**Effort**: 5 minutes
**Dependencies**: None

**Description**:
Current admin password is `Admin123!` which is temporary and insecure.

**Implementation Steps**:

```bash
# Option 1: Via Admin Panel (recommended)
# 1. Login: http://72.60.17.138/admin
# 2. Click user menu → Profile
# 3. Change password
# 4. Use strong password (16+ chars, mix of upper/lower/numbers/symbols)
# 5. Store in password manager

# Option 2: Via Tinker
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> $admin = \App\Models\User::where('email', 'admin@paradocks.com')->first();
>>> $admin->password = bcrypt('YourNewSecurePassword123!@#');
>>> $admin->save();
>>> exit
```

**New Password Requirements**:
- Minimum 16 characters
- Mix of uppercase, lowercase, numbers, symbols
- Not a dictionary word
- Unique (not used elsewhere)

**Post-Change**:
- [ ] Update password in password manager
- [ ] Update [03-CREDENTIALS.md](03-CREDENTIALS.md)
- [ ] Test login with new password
- [ ] Document change date

---

#### [ ] Configure Gmail SMTP for Email Notifications

**Priority**: High
**Effort**: 15 minutes
**Dependencies**: Gmail account with App Password

**Description**:
Currently using `log` mail driver. Configure Gmail SMTP to send actual emails.

**Implementation Steps**:

**Step 1: Generate Gmail App Password**
```
1. Go to https://myaccount.google.com/security
2. Enable 2-Factor Authentication (if not already enabled)
3. Go to "App passwords"
4. Select "Mail" and "Other (Custom name)"
5. Enter "ParaDocks Staging"
6. Click Generate
7. Copy the 16-character password (spaces don't matter)
```

**Step 2: Update Environment Configuration**
```bash
# Edit .env
vim /var/www/paradocks/.env

# Update mail configuration:
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@paradocks.com"
MAIL_FROM_NAME="ParaDocks Staging"

# Save and exit
```

**Step 3: Clear Configuration Cache**
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
docker-compose -f docker-compose.prod.yml exec app php artisan config:cache
```

**Step 4: Test Email**
```bash
# Send test email
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
>>> \Mail::raw('This is a test email from ParaDocks Staging', function($msg) {
      $msg->to('your-test-email@example.com')->subject('ParaDocks Test Email');
    });
>>> exit

# Check Gmail sent folder
# Check recipient inbox
```

**Acceptance Criteria**:
- [ ] Gmail App Password generated
- [ ] .env updated with SMTP credentials
- [ ] Test email sent successfully
- [ ] Test email received in inbox (not spam)
- [ ] Credentials documented in [03-CREDENTIALS.md](03-CREDENTIALS.md)

---

### Infrastructure & Reliability

#### [ ] Implement Automated Backup System

**Priority**: High
**Effort**: 2 hours
**Dependencies**: Cloud storage account (S3, Backblaze, etc.)

**Description**:
Set up automated daily backups of database and critical files with offsite storage.

**Backup Components**:
- MySQL database (daily)
- .env file (daily)
- Uploaded files (storage/app/public) (daily)
- Configuration files (weekly)
- Full system backup (monthly)

**Implementation Options**:

**Option A: Script + S3 (Recommended)**

1. **Create Backup Script**:
```bash
sudo nano /usr/local/bin/paradocks-backup.sh
```

```bash
#!/bin/bash
# ParaDocks Staging Backup Script

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/tmp/paradocks_backup_$DATE"
PROJECT_DIR="/var/www/paradocks"
S3_BUCKET="s3://your-bucket/paradocks/staging"

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Backup database
cd "$PROJECT_DIR"
docker-compose -f docker-compose.prod.yml exec -T mysql mysqldump -u paradocks -p$DB_PASSWORD paradocks > "$BACKUP_DIR/database.sql"

# Backup environment
cp .env "$BACKUP_DIR/env.txt"

# Backup storage
tar -czf "$BACKUP_DIR/storage.tar.gz" storage/app/public/

# Backup configurations
tar -czf "$BACKUP_DIR/configs.tar.gz" docker-compose.prod.yml docker/ .gitignore

# Create manifest
cat > "$BACKUP_DIR/MANIFEST.txt" << EOF
Backup Date: $(date)
Server: 72.60.17.138
Environment: Staging
Git Commit: $(git rev-parse HEAD)
Laravel Version: $(docker-compose -f docker-compose.prod.yml exec -T app php artisan --version | tr -d '\r')
EOF

# Compress
cd /tmp
tar -czf "paradocks_staging_$DATE.tar.gz" "paradocks_backup_$DATE"

# Upload to S3
aws s3 cp "paradocks_staging_$DATE.tar.gz" "$S3_BUCKET/"

# Cleanup local backup
rm -rf "$BACKUP_DIR"
rm "paradocks_staging_$DATE.tar.gz"

# Remove old S3 backups (keep last 30 days)
aws s3 ls "$S3_BUCKET/" | while read -r line; do
    createDate=$(echo $line | awk '{print $1" "$2}')
    createDate=$(date -d "$createDate" +%s)
    olderThan=$(date -d "30 days ago" +%s)
    if [[ $createDate -lt $olderThan ]]; then
        fileName=$(echo $line | awk '{print $4}')
        if [[ $fileName != "" ]]; then
            aws s3 rm "$S3_BUCKET/$fileName"
        fi
    fi
done

echo "Backup completed: paradocks_staging_$DATE.tar.gz"
```

2. **Make Script Executable**:
```bash
sudo chmod +x /usr/local/bin/paradocks-backup.sh
```

3. **Install AWS CLI**:
```bash
sudo apt install awscli -y
aws configure
# Enter AWS Access Key ID
# Enter AWS Secret Access Key
# Enter Default region
```

4. **Set up Cron Job**:
```bash
sudo crontab -e

# Add daily backup at 2 AM
0 2 * * * /usr/local/bin/paradocks-backup.sh >> /var/log/paradocks-backup.log 2>&1
```

5. **Test Backup**:
```bash
sudo /usr/local/bin/paradocks-backup.sh
# Check S3 bucket for uploaded file
```

**Option B: Laravel Backup Package**

```bash
# Install spatie/laravel-backup
docker-compose -f docker-compose.prod.yml exec app composer require spatie/laravel-backup

# Publish config
docker-compose -f docker-compose.prod.yml exec app php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"

# Configure config/backup.php with S3 settings

# Schedule in app/Console/Kernel.php
$schedule->command('backup:clean')->daily()->at('01:00');
$schedule->command('backup:run')->daily()->at('02:00');

# Test
docker-compose -f docker-compose.prod.yml exec app php artisan backup:run
```

**Acceptance Criteria**:
- [ ] Backup script created and tested
- [ ] Cron job scheduled
- [ ] S3 bucket configured
- [ ] Test backup and restore successful
- [ ] Old backups auto-deleted (retention policy)
- [ ] Backup monitoring/alerts configured
- [ ] Restore procedure documented

---

## Medium Priority Tasks

### Configuration & Optimization

#### [ ] Set up Domain Name (if available)

**Priority**: Medium
**Effort**: 30 minutes
**Dependencies**: Domain availability

**Steps**:
1. Purchase domain or use existing
2. Configure DNS A record pointing to 72.60.17.138
3. Update .env APP_URL
4. Update nginx server_name
5. Configure SSL (see above)
6. Test access via domain

---

#### [ ] Implement Log Rotation

**Priority**: Medium
**Effort**: 30 minutes
**Dependencies**: None

**Description**:
Configure automatic log rotation to prevent log files from consuming excessive disk space.

**Implementation**:

```bash
# Create logrotate configuration
sudo nano /etc/logrotate.d/paradocks

/var/www/paradocks/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    create 0664 ubuntu ubuntu
    sharedscripts
    postrotate
        docker-compose -f /var/www/paradocks/docker-compose.prod.yml exec app php artisan cache:clear > /dev/null 2>&1 || true
    endscript
}

# Test configuration
sudo logrotate -d /etc/logrotate.d/paradocks

# Force rotation (test)
sudo logrotate -f /etc/logrotate.d/paradocks
```

**Acceptance Criteria**:
- [ ] Logrotate configured
- [ ] Logs rotated daily
- [ ] Old logs compressed
- [ ] Logs kept for 14 days
- [ ] Test rotation successful

---

#### [ ] Configure External Monitoring

**Priority**: Medium
**Effort**: 1 hour
**Dependencies**: Monitoring service account

**Recommended Services**:

**UptimeRobot (Free Tier)**:
1. Sign up at https://uptimerobot.com
2. Add monitor: http://72.60.17.138
3. Configure alert contacts (email, SMS)
4. Set check interval: 5 minutes
5. Set alert threshold: 2 failures

**Better Stack (Formerly Logtail)**:
1. Sign up at https://betterstack.com
2. Create uptime monitor
3. Create log destination
4. Install agent on server (optional)
5. Configure Laravel logging integration

**StatusCake (Free Tier)**:
1. Sign up at https://www.statuscake.com
2. Add website monitor
3. Configure contact groups
4. Set test interval

**Acceptance Criteria**:
- [ ] Uptime monitor configured
- [ ] Alert contacts added
- [ ] Test alert received
- [ ] Dashboard accessible
- [ ] Integration tested

---

#### [ ] Optimize Docker Images for Production

**Priority**: Medium
**Effort**: 2 hours
**Dependencies**: None

**Optimizations**:

1. **Multi-stage builds** (reduce image size)
2. **Remove development dependencies**
3. **Use specific image tags** (not `latest`)
4. **Implement health checks**
5. **Optimize layer caching**

**Example Optimization**:

```dockerfile
# docker/php/Dockerfile (optimized)
FROM php:8.2-fpm-alpine AS base

# Install production dependencies only
RUN apk add --no-cache \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    && docker-php-ext-install pdo_mysql zip gd

# Production stage
FROM base AS production
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --optimize-autoloader --no-dev
RUN php artisan config:cache && php artisan route:cache

HEALTHCHECK --interval=30s --timeout=3s --start-period=40s \
  CMD php artisan schedule:list || exit 1
```

---

### Monitoring & Observability

#### [ ] Install Laravel Telescope (Development Only)

**Priority**: Low
**Effort**: 30 minutes
**Dependencies**: Only for staging/development

**Installation**:

```bash
# Install Telescope
docker-compose -f docker-compose.prod.yml exec app composer require laravel/telescope --dev

# Publish assets
docker-compose -f docker-compose.prod.yml exec app php artisan telescope:install

# Run migrations
docker-compose -f docker-compose.prod.yml exec app php artisan migrate

# Publish config
docker-compose -f docker-compose.prod.yml exec app php artisan vendor:publish --tag=telescope-config

# Configure .env
TELESCOPE_ENABLED=true

# Access: http://72.60.17.138/telescope
```

**Configuration** (config/telescope.php):
```php
'enabled' => env('TELESCOPE_ENABLED', false),
'middleware' => [
    'web',
    'auth', // Require authentication
],
```

**Acceptance Criteria**:
- [ ] Telescope installed
- [ ] Migrations run
- [ ] Authentication enabled
- [ ] Dashboard accessible
- [ ] No performance impact on production

---

#### [ ] Set up Error Tracking (Sentry)

**Priority**: Medium
**Effort**: 1 hour
**Dependencies**: Sentry account

**Installation**:

```bash
# Install Sentry SDK
docker-compose -f docker-compose.prod.yml exec app composer require sentry/sentry-laravel

# Publish config
docker-compose -f docker-compose.prod.yml exec app php artisan sentry:publish

# Configure .env
SENTRY_LARAVEL_DSN=https://your-dsn@sentry.io/project-id

# Test
docker-compose -f docker-compose.prod.yml exec app php artisan sentry:test
```

---

### Performance & Scalability

#### [ ] Implement Redis Caching Strategy

**Priority**: Medium
**Effort**: 2 hours
**Dependencies**: None (Redis already installed)

**Cache Strategies**:

1. **Route Caching** (already done)
2. **Query Caching**:
```php
$users = Cache::remember('users.all', 3600, function () {
    return User::all();
});
```

3. **View Caching** (already done)
4. **API Response Caching**
5. **Fragment Caching** (Blade)

**Monitoring**:
```bash
# Check cache hit ratio
docker-compose -f docker-compose.prod.yml exec redis redis-cli -a password INFO stats
```

---

#### [ ] Configure CDN for Static Assets

**Priority**: Low
**Effort**: 2 hours
**Dependencies**: CDN service (CloudFlare, AWS CloudFront, etc.)

**Options**:

**CloudFlare (Free Tier)**:
1. Add domain to CloudFlare
2. Update DNS to CloudFlare nameservers
3. Enable CDN caching for static assets
4. Configure cache rules
5. Update asset URLs in .env

**AWS CloudFront**:
1. Create CloudFront distribution
2. Set origin to 72.60.17.138
3. Configure caching behaviors
4. Update APP_URL or ASSET_URL

---

## Low Priority Tasks

### Nice-to-Have Features

#### [ ] Set up Staging-Specific Features

**Priority**: Low
**Effort**: Varies

**Ideas**:
- [ ] Laravel Debugbar (development mode)
- [ ] Query logging for optimization
- [ ] Fake SMTP server (Mailhog) for email testing
- [ ] Database seeding with realistic test data
- [ ] API documentation (Swagger/OpenAPI)

---

#### [ ] Implement Rate Limiting

**Priority**: Low
**Effort**: 30 minutes

**Configuration** (config/rate-limiter.php or routes):
```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

---

#### [ ] Add Server Monitoring Dashboard

**Priority**: Low
**Effort**: 2 hours

**Options**:
- Netdata (real-time monitoring)
- Grafana + Prometheus
- Custom Laravel dashboard

---

## Completed Tasks

### [x] Initial Server Deployment

**Completed**: 2025-11-11
**Description**: Deployed staging environment with Docker Compose

---

### [x] Configure UFW Firewall

**Completed**: 2025-11-11
**Description**: Set up UFW with ufw-docker integration

---

### [x] Resolve Storage Permission Issues

**Completed**: 2025-11-11
**Description**: Switched from Docker volumes to bind mounts

---

### [x] Fix Vite Manifest Path

**Completed**: 2025-11-11
**Description**: Created symlink for manifest.json

---

### [x] Create Documentation Structure

**Completed**: 2025-11-11
**Description**: Created comprehensive documentation

---

## Future Considerations

### Production Deployment

When ready to deploy to production:

1. **Separate Production Environment**:
   - New VPS or dedicated server
   - Production branch in Git
   - Production-specific configurations
   - Stricter security measures

2. **High Availability**:
   - Load balancer
   - Multiple app servers
   - Database replication
   - Redis Sentinel/Cluster

3. **Advanced Monitoring**:
   - APM (New Relic, Datadog)
   - Log aggregation (ELK Stack)
   - Real-time alerts (PagerDuty)

4. **Performance Optimization**:
   - Laravel Octane
   - Database query optimization
   - Asset optimization
   - HTTP/2, HTTP/3

5. **Disaster Recovery**:
   - Automated backups to multiple locations
   - Disaster recovery plan
   - Failover procedures
   - Regular recovery drills

---

## Task Management

### Adding New Tasks

When adding new tasks, use this format:

```markdown
#### [ ] Task Title

**Priority**: Critical / High / Medium / Low
**Effort**: X hours/minutes
**Dependencies**: List any dependencies

**Description**:
What needs to be done and why

**Implementation Steps**:
1. Step one
2. Step two
3. ...

**Acceptance Criteria**:
- [ ] Criterion 1
- [ ] Criterion 2
```

### Task Tracking

- Update checkboxes as tasks progress
- Move completed tasks to "Completed Tasks" section
- Add completion date
- Link to related documentation/PRs

---

## Related Documentation

- **Deployment Log**: [01-DEPLOYMENT-LOG.md](01-DEPLOYMENT-LOG.md)
- **Issues & Workarounds**: [05-ISSUES-WORKAROUNDS.md](05-ISSUES-WORKAROUNDS.md)
- **Maintenance Procedures**: [06-MAINTENANCE.md](06-MAINTENANCE.md)
- **Architecture Decisions**: [../../architecture/decision_log/](../../architecture/decision_log/)

---

**Document Maintainer**: Development Team
**Last Updated**: 2025-11-11
**Next Review**: After completing high-priority tasks
