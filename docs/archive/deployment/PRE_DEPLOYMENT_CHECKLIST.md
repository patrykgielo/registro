# Pre-Deployment Checklist - Paradocks VPS Production

**Last Updated:** 2025-01-11
**Estimated Total Time:** 2-3 hours (first-time deployment)
**Status:** Production Ready ✅

---

## Prerequisites Verification

**Before starting deployment, ensure you have:**

- [ ] VPS with Ubuntu 22.04+ (recommended: 24.04 LTS)
- [ ] Minimum 2GB RAM, 2 CPU cores, 40GB SSD
- [ ] Root SSH access configured
- [ ] Domain name registered and DNS configured
- [ ] Gmail account with 2FA enabled (for SMTP)
- [ ] Google Maps API key with HTTP referrer restrictions
- [ ] Local SSH key generated (`ssh-keygen -t ed25519`)

**Documentation References:**
- Full Guide: [VPS_SETUP.md](VPS_SETUP.md)
- Expert Reference: [VPS_PRODUCTION_DEPLOYMENT.md](../VPS_PRODUCTION_DEPLOYMENT.md)

---

## Phase 1: VPS Initial Setup (30-45 minutes)

### 1.1 System Hardening (15 min)

- [ ] Connect via SSH: `ssh root@YOUR_VPS_IP`
- [ ] Update system: `sudo apt update && sudo apt upgrade -y`
- [ ] Install essentials: `sudo apt install -y curl wget git unzip vim htop ufw fail2ban`
- [ ] Create `deployer` user with sudo privileges
- [ ] Configure SSH key authentication for deployer
- [ ] **SECURITY:** Disable root login (`PermitRootLogin no` in `/etc/ssh/sshd_config`)
- [ ] **SECURITY:** Disable password authentication (`PasswordAuthentication no`)
- [ ] Restart SSH: `sudo systemctl restart sshd`

**⚠️ CRITICAL:** Test SSH login with deployer user BEFORE closing root session!

**Verification:**
```bash
ssh deployer@YOUR_VPS_IP  # Should work without password
sudo whoami              # Should return "root"
```

### 1.2 Install Docker (10 min)

- [ ] Install Docker Engine: `curl -fsSL https://get.docker.com | sudo sh`
- [ ] Add deployer to docker group: `sudo usermod -aG docker deployer`
- [ ] Install Docker Compose Plugin: `sudo apt install -y docker-compose-plugin`
- [ ] Log out and log back in (for group changes)
- [ ] Test Docker: `docker run hello-world`

**Verification:**
```bash
docker --version       # Should show v26.0.0+
docker compose version # Should show v2.26.0+
docker ps              # Should run without sudo
```

### 1.3 Install UFW-Docker (10 min) **CRITICAL FOR SECURITY**

**⚠️ WARNING:** Docker bypasses UFW by default. This script MUST be installed to prevent exposing MySQL/Redis ports publicly!

- [ ] Download ufw-docker script:
  ```bash
  sudo wget -O /usr/local/bin/ufw-docker \
    https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker
  ```
- [ ] Make executable: `sudo chmod +x /usr/local/bin/ufw-docker`
- [ ] Install integration: `sudo ufw-docker install`
- [ ] Restart UFW: `sudo systemctl restart ufw`
- [ ] **REBOOT REQUIRED:** `sudo reboot`

**Verification (after reboot):**
```bash
sudo iptables -L DOCKER-USER -n  # Should show ufw-docker rules
```

**References:** [chaifeng/ufw-docker](https://github.com/chaifeng/ufw-docker)

### 1.4 Configure Firewall (5 min)

- [ ] Set defaults: `sudo ufw default deny incoming && sudo ufw default allow outgoing`
- [ ] Allow SSH: `sudo ufw allow 22/tcp` (or custom port)
- [ ] Allow HTTP: `sudo ufw route allow proto tcp from any to any port 80`
- [ ] Allow HTTPS: `sudo ufw route allow proto tcp from any to any port 443`
- [ ] Enable UFW: `sudo ufw enable`

**Verification:**
```bash
sudo ufw status verbose  # Should show SSH, HTTP route, HTTPS route
```

### 1.5 System Configuration (5 min)

- [ ] Set timezone: `sudo timedatectl set-timezone Europe/Warsaw`
- [ ] Verify: `timedatectl` (should show Europe/Warsaw)
- [ ] **If RAM <4GB:** Create 2GB swap file:
  ```bash
  sudo fallocate -l 2G /swapfile
  sudo chmod 600 /swapfile
  sudo mkswap /swapfile
  sudo swapon /swapfile
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
  ```
- [ ] Verify swap: `free -h`

### 1.6 Install Certbot (5 min)

- [ ] Install via snap: `sudo snap install --classic certbot`
- [ ] Create symlink: `sudo ln -s /snap/bin/certbot /usr/bin/certbot`
- [ ] Verify: `certbot --version` (should show 2.x.x)
- [ ] Create webroot: `sudo mkdir -p /var/www/certbot`
- [ ] Set permissions: `sudo chown -R www-data:www-data /var/www/certbot`

---

## Phase 2: Application Setup (30-40 minutes)

### 2.1 Clone Repository (5 min)

- [ ] Create app directory: `sudo mkdir -p /var/www && sudo chown -R deployer:deployer /var/www`
- [ ] Clone repo:
  ```bash
  cd /var/www
  git clone git@github.com:yourusername/paradocks.git
  cd paradocks
  ```
- [ ] Verify structure: `ls -la` (should show app/, docker/, scripts/, etc.)

### 2.2 Environment Configuration (10 min)

- [ ] Copy template: `cp app/.env.production.example app/.env`
- [ ] Edit config: `nano app/.env`

**CRITICAL Values to Update:**

| Variable | Example | Notes |
|----------|---------|-------|
| `APP_URL` | `https://your-domain.com` | Your actual domain |
| `APP_KEY` | *(generated in next step)* | Leave empty for now |
| `DB_PASSWORD` | `openssl rand -base64 24` | Generate strong password |
| `DB_ROOT_PASSWORD` | `openssl rand -base64 24` | Different from DB_PASSWORD |
| `REDIS_PASSWORD` | `openssl rand -base64 24` | Another strong password |
| `MAIL_USERNAME` | `your-email@gmail.com` | Gmail address |
| `MAIL_PASSWORD` | `abcd efgh ijkl mnop` | 16-char App Password from Google |
| `GOOGLE_MAPS_API_KEY` | `AIzaSy...` | Production key with HTTP referrer restrictions |
| `GOOGLE_MAPS_MAP_ID` | `your-map-id` | Required for AdvancedMarkerElement |

**⚠️ SECURITY CHECKLIST:**
- [ ] `APP_ENV=production` (NOT local/dev)
- [ ] `APP_DEBUG=false` (NEVER true in production!)
- [ ] All passwords minimum 24 characters
- [ ] Gmail App Password generated (not regular password)
- [ ] Google Maps API key has HTTP referrer restrictions

**Generate Strong Passwords:**
```bash
openssl rand -base64 24  # Run 3 times for DB, root, redis
```

**Gmail App Password Setup:**
1. Enable 2-Step Verification: https://myaccount.google.com/security
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Select "Mail" → "Other (Laravel)" → Copy 16-char password
4. Remove spaces: `abcd efgh ijkl mnop` → `abcdefghijklmnop`

### 2.3 Generate Application Key (2 min)

- [ ] Generate key:
  ```bash
  docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate
  ```
- [ ] Verify: `grep APP_KEY app/.env` (should have base64:... value)

### 2.4 Build Frontend Assets (10 min)

**⚠️ CRITICAL:** Production build MUST be done BEFORE docker build (assets baked into image)

- [ ] Install Node.js dependencies: `cd app && npm ci`
- [ ] Build production assets: `npm run build`
- [ ] Verify output:
  ```bash
  ls -la public/build/assets/  # Should show app-[hash].css and app-[hash].js
  cat public/build/.vite/manifest.json  # Should contain asset mappings
  ```
- [ ] Return to root: `cd ..`

**Expected manifest.json structure:**
```json
{
  "resources/css/app.css": {
    "file": "assets/app-[hash].css",
    "isEntry": true
  },
  "resources/js/app.js": {
    "file": "assets/app-[hash].js",
    "isEntry": true
  }
}
```

**Troubleshooting:**
- If build fails: Check Node.js version (≥20.19) with `node --version`
- If manifest missing: Verify plugin order in `vite.config.js` (`tailwindcss()` BEFORE `laravel()`)

### 2.5 Build Docker Images (5 min)

- [ ] Build production images:
  ```bash
  docker compose -f docker-compose.prod.yml build --no-cache
  ```
- [ ] Verify: `docker images | grep paradocks` (should show freshly built images)

**⚠️ IMPORTANT:** If you see "permission denied" errors during build, check:
```bash
ls -la app/storage/     # Should be owned by laravel:laravel inside container
ls -la app/bootstrap/cache/  # Same ownership
```

---

## Phase 3: SSL Certificate (15-20 minutes)

**⚠️ CRITICAL PREREQUISITE:** Port 80 MUST be allowed in UFW BEFORE obtaining certificate!

### 3.1 Verify Port 80 Access (1 min)

- [ ] Check UFW: `sudo ufw status | grep 80` (should show "ALLOW")
- [ ] If missing: `sudo ufw route allow proto tcp from any to any port 80`

### 3.2 Obtain Initial Certificate (5 min)

**Using standalone mode (Nginx must be stopped):**

- [ ] Stop Nginx (if running): `docker compose -f docker-compose.prod.yml stop nginx`
- [ ] Obtain certificate:
  ```bash
  sudo certbot certonly --standalone \
    -d your-domain.com \
    -d www.your-domain.com \
    --non-interactive \
    --agree-tos \
    --email your-email@example.com
  ```
- [ ] Verify success: `sudo certbot certificates`

**Expected Output:**
```
Certificate is saved at: /etc/letsencrypt/live/your-domain.com/fullchain.pem
Key is saved at:         /etc/letsencrypt/live/your-domain.com/privkey.pem
This certificate expires on 2025-04-15.
```

### 3.3 Configure Post-Hook for Auto-Renewal (10 min)

- [ ] Create post-hook directory: `sudo mkdir -p /etc/letsencrypt/renewal-hooks/post`
- [ ] Create script: `sudo nano /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh`

**Paste this content:**
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

- [ ] Make executable: `sudo chmod +x /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh`
- [ ] Create log directory: `sudo mkdir -p /var/log/letsencrypt`

### 3.4 Switch to Webroot Renewal (3 min)

- [ ] Edit renewal config: `sudo nano /etc/letsencrypt/renewal/your-domain.com.conf`
- [ ] Change authenticator from `standalone` to `webroot`:
  ```ini
  # Replace this line:
  authenticator = standalone

  # With these lines:
  authenticator = webroot

  [[webroot_map]]
  your-domain.com = /var/www/certbot
  www.your-domain.com = /var/www/certbot
  ```
- [ ] Save and exit

### 3.5 Test Auto-Renewal (1 min)

- [ ] Test renewal: `sudo certbot renew --dry-run`
- [ ] Expected: "The dry run was successful"
- [ ] Check systemd timer: `sudo systemctl status snap.certbot.renew.timer`

**Expected:**
```
Active: active (waiting)
Trigger: Next run at 02:00:00
```

---

## Phase 4: Deploy Application (20-30 minutes)

### 4.1 Start All Services (5 min)

- [ ] Start containers:
  ```bash
  docker compose -f docker-compose.prod.yml up -d
  ```
- [ ] Wait 30 seconds for health checks
- [ ] Check status: `docker compose -f docker-compose.prod.yml ps`

**Expected Output (all "Up" with "healthy"):**
```
paradocks-app-prod       Up (healthy)
paradocks-mysql-prod     Up (healthy)
paradocks-nginx-prod     Up (healthy)
paradocks-redis-prod     Up (healthy)
paradocks-horizon-prod   Up
paradocks-scheduler-prod Up
```

**Troubleshooting:**
- If unhealthy: `docker compose -f docker-compose.prod.yml logs <service_name>`
- Common issues: Database password mismatch, .env syntax errors

### 4.2 Run Database Migrations & Seeders (5-10 min)

- [ ] Run migrations:
  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
  ```

- [ ] Run production-safe seeders (automatic detection):
  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan deploy:seed
  ```

**Expected Output (First Deployment):**
```
🌱 Deploy Seeder - Smart Seeder Execution

🔍 Detecting deployment context...
   ✓ First deployment detected (Settings table empty)

📋 Execution Plan:
   Context: First Deployment
   Seeders to run: 6

   1. SettingSeeder
   2. RolePermissionSeeder
   3. VehicleTypeSeeder
   4. ServiceSeeder
   5. EmailTemplateSeeder
   6. SmsTemplateSeeder

🚀 Executing seeders...
   Running: SettingSeeder...
   ✓ SettingSeeder completed (1234ms)
   [... other seeders ...]
✅ All seeders completed successfully
   Executed: 6/6
   Total time: 8688ms
```

**Troubleshooting Seeder Failures:**
```bash
# Check specific seeder error
docker compose -f docker-compose.prod.yml logs app | grep -A 20 "deploy:seed"

# Run in dry-run mode (preview without execution)
docker compose -f docker-compose.prod.yml exec app php artisan deploy:seed --dry-run

# Force all seeders (override detection)
docker compose -f docker-compose.prod.yml exec app php artisan deploy:seed --force-all
```

**Verification:**
```bash
docker compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SHOW TABLES;" paradocks
# Should list ~30 tables including: users, appointments, vehicle_types, settings, email_templates
```

### 4.3 Create Admin User (2 min)

- [ ] Create admin:
  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan make:filament-user
  ```
- [ ] Enter details when prompted:
  - First Name: Admin
  - Last Name: User
  - Email: admin@your-domain.com
  - Password: [strong password - save to password manager!]
  - Confirm password: [repeat]

### 4.4 Optimize Application (3 min)

- [ ] Clear all caches:
  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
  ```
- [ ] Rebuild caches:
  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan config:cache
  docker compose -f docker-compose.prod.yml exec app php artisan route:cache
  docker compose -f docker-compose.prod.yml exec app php artisan view:cache
  ```
- [ ] Verify Opcache: `docker compose -f docker-compose.prod.yml exec app php -i | grep opcache.enable`

**Expected Opcache Config:**
```
opcache.enable => On
opcache.enable_cli => On
opcache.save_comments => On
opcache.validate_timestamps => Off
opcache.memory_consumption => 256
```

### 4.5 Verify Horizon & Scheduler (5 min)

- [ ] Check Horizon: `docker compose -f docker-compose.prod.yml exec app php artisan horizon:status`
  - Expected: "Horizon is running"
- [ ] Check scheduler logs: `docker compose -f docker-compose.prod.yml logs scheduler --tail=20`
  - Expected: "Running scheduled command..." every 60 seconds

---

## Phase 5: Post-Deployment Validation (15-20 minutes)

### 5.1 HTTP/HTTPS Access (3 min)

- [ ] Test HTTP redirect:
  ```bash
  curl -I http://your-domain.com
  # Expected: HTTP/1.1 301 Moved Permanently → Location: https://...
  ```
- [ ] Test HTTPS:
  ```bash
  curl -I https://your-domain.com
  # Expected: HTTP/2 200 OK
  ```

### 5.2 Application Access (5 min)

Open in browser:

- [ ] Homepage: `https://your-domain.com` (should load booking wizard)
- [ ] Admin Panel: `https://your-domain.com/admin` (login with admin user)
- [ ] Horizon Dashboard: `https://your-domain.com/horizon` (should show metrics)

**⚠️ If 502 Bad Gateway:**
```bash
docker compose -f docker-compose.prod.yml logs app
# Common causes: .env DB_HOST should be "mysql" not "localhost"
```

### 5.3 Email System Test (5 min)

- [ ] Send test email:
  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan email:test \
    --to=your-email@example.com \
    --template=user-registered \
    --language=pl
  ```
- [ ] Check Horizon: `https://your-domain.com/horizon/jobs/recent` (should show dispatched job)
- [ ] Check inbox: Email should arrive within 1-2 minutes
- [ ] Check admin panel: Email → Email Sends (should show "sent" status)

**⚠️ If email fails:**
```bash
# Check Gmail App Password is correct (16 chars, no spaces)
docker compose -f docker-compose.prod.yml exec app grep MAIL_PASSWORD .env

# Check Horizon logs
docker compose -f docker-compose.prod.yml logs horizon | tail -50
```

### 5.4 SSL Certificate Validation (2 min)

- [ ] Check SSL rating: https://www.ssllabs.com/ssltest/analyze.html?d=your-domain.com
  - Expected: A or A+ rating
  - Wait 2-3 minutes for scan to complete
- [ ] Verify expiry: `sudo certbot certificates`
  - Should expire in ~90 days

### 5.5 Database Connection Test (5 min)

- [ ] Connect to MySQL:
  ```bash
  docker compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p
  # Enter password from .env
  ```
- [ ] Run queries:
  ```sql
  SHOW DATABASES;
  USE paradocks;
  SHOW TABLES;
  SELECT COUNT(*) FROM users;  -- Should return 1 (admin user)
  SELECT COUNT(*) FROM vehicle_types;  -- Should return 5
  EXIT;
  ```

---

## Phase 6: Backup & Monitoring Setup (15-20 minutes)

### 6.1 Configure Automated Backups (10 min)

- [ ] Test backup script: `sudo ./scripts/backup-database.sh`
- [ ] Verify backup created: `ls -lh /var/backups/paradocks/`
- [ ] Setup cron job: `crontab -e`

**Add these lines:**
```cron
# Daily database backup at 2 AM
0 2 * * * /var/www/paradocks/scripts/backup-database.sh

# SSL certificate renewal (already handled by snap timer, this is backup)
0 3 * * * certbot renew --quiet && docker compose -f /var/www/paradocks/docker-compose.prod.yml restart nginx
```

- [ ] Save and verify: `crontab -l`

### 6.2 Setup Log Rotation (5 min)

- [ ] Create logrotate config: `sudo nano /etc/logrotate.d/laravel`

**Paste this content:**
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

- [ ] Test: `sudo logrotate -d /etc/logrotate.d/laravel`

### 6.3 Setup External Monitoring (5 min)

**Option A: UptimeRobot (Free)**

1. Sign up: https://uptimerobot.com
2. Add monitor:
   - Type: HTTPS
   - URL: https://your-domain.com
   - Interval: 5 minutes
3. Add alert contacts (email/SMS)

**Option B: Slack Notifications (Advanced)**

- [ ] Create Slack webhook: https://api.slack.com/messaging/webhooks
- [ ] Add to Laravel: `config/logging.php` → slack channel
- [ ] Test: `docker compose -f docker-compose.prod.yml exec app php artisan log:test`

---

## Final Security Checklist

**⚠️ CRITICAL - Review before going live:**

### System Security
- [ ] Root login disabled (`PermitRootLogin no`)
- [ ] Password authentication disabled (`PasswordAuthentication no`)
- [ ] UFW enabled with only SSH, HTTP route, HTTPS route allowed
- [ ] UFW-Docker installed and active (verify: `sudo iptables -L DOCKER-USER -n`)
- [ ] Fail2ban active: `sudo systemctl status fail2ban`
- [ ] MySQL port 3306 NOT exposed to public (check: `docker compose -f docker-compose.prod.yml config | grep 3306`)
- [ ] Redis port 6379 NOT exposed to public (check: `docker compose -f docker-compose.prod.yml config | grep 6379`)
- [ ] Redis password configured: `REDIS_PASSWORD` set in `.env`
- [ ] `.env` file has permissions 640: `ls -l app/.env`

### Application Security
- [ ] `APP_DEBUG=false` in `.env`
- [ ] `APP_ENV=production` in `.env`
- [ ] All passwords minimum 24 characters
- [ ] Google Maps API key has HTTP referrer restrictions

### Session Security (GDPR/Security Critical)
- [ ] `SESSION_ENCRYPT=true` in `.env` (encrypts session data)
- [ ] `SESSION_SECURE_COOKIE=true` in `.env` (HTTPS-only cookies)
- [ ] `SESSION_SAME_SITE=lax` in `.env` (CSRF protection)

### SSL/TLS
- [ ] SSL certificate valid and auto-renewal configured
- [ ] Automated backups scheduled in cron

---

## Performance Optimization Checklist (Optional but Recommended)

- [ ] Opcache enabled (verify: `docker compose -f docker-compose.prod.yml exec app php -i | grep opcache`)
- [ ] Redis used for cache: `grep CACHE_DRIVER app/.env` (should be "redis")
- [ ] Redis used for sessions: `grep SESSION_DRIVER app/.env` (should be "redis")
- [ ] Redis used for queue: `grep QUEUE_CONNECTION app/.env` (should be "redis")
- [ ] Horizon running: `docker compose -f docker-compose.prod.yml exec app php artisan horizon:status`
- [ ] Laravel caches built: route, config, view (run `php artisan optimize`)
- [ ] Vite production assets built (check `app/public/build/manifest.json` exists)

---

## Troubleshooting Quick Reference

### Container won't start
```bash
docker compose -f docker-compose.prod.yml logs <service_name>
docker compose -f docker-compose.prod.yml down -v
docker compose -f docker-compose.prod.yml up -d --build
```

### 502 Bad Gateway
```bash
# Check app logs
docker compose -f docker-compose.prod.yml logs app

# Common fix: Clear Opcache
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml restart app
```

### Emails not sending
```bash
# Check Horizon
docker compose -f docker-compose.prod.yml exec app php artisan horizon:status

# Restart Horizon
docker compose -f docker-compose.prod.yml restart horizon

# Check failed jobs
docker compose -f docker-compose.prod.yml exec app php artisan queue:failed
```

### Permission denied errors
```bash
# NEVER run chown on host - rebuild image instead
docker compose -f docker-compose.prod.yml build --no-cache app
docker compose -f docker-compose.prod.yml up -d
```

---

## Post-Go-Live Tasks (First 48 Hours)

**Day 1:**
- [ ] Monitor logs: `docker compose -f docker-compose.prod.yml logs -f`
- [ ] Check Horizon every 4 hours: https://your-domain.com/horizon
- [ ] Verify email delivery (check Email Sends in admin panel)
- [ ] Test appointment creation flow end-to-end
- [ ] Monitor disk usage: `df -h`
- [ ] Monitor memory: `free -h`

**Day 2:**
- [ ] Review backup logs: `ls -lh /var/backups/paradocks/`
- [ ] Check SSL certificate auto-renewal test: `sudo certbot renew --dry-run`
- [ ] Review failed queue jobs: `docker compose -f docker-compose.prod.yml exec app php artisan queue:failed`
- [ ] Test password reset flow
- [ ] Test Google Maps autocomplete
- [ ] Verify scheduler runs: `docker compose -f docker-compose.prod.yml logs scheduler | grep "Running scheduled"`

---

## Success Criteria

**✅ Deployment is successful when ALL of these are true:**

1. Homepage loads via HTTPS without certificate warnings
2. Admin panel accessible and functional
3. Test email sent and received successfully
4. Horizon dashboard shows "running" status
5. Scheduler logs show commands running every 60 seconds
6. SSL Labs rating is A or A+
7. All health checks passing: `docker compose -f docker-compose.prod.yml ps`
8. Backup script creates `.sql.gz` file in `/var/backups/paradocks/`
9. UFW-Docker prevents public access to MySQL/Redis ports
10. Application responds within 2 seconds (test with curl)

---

## Emergency Rollback Procedure

**If deployment fails catastrophically:**

1. **Stop all containers:**
   ```bash
   docker compose -f docker-compose.prod.yml down
   ```

2. **Restore database backup:**
   ```bash
   gunzip -c /var/backups/paradocks/backup_YYYYMMDD_HHMMSS.sql.gz | \
   docker compose -f docker-compose.prod.yml exec -T mysql mysql -u paradocks -p paradocks
   ```

3. **Revert code:**
   ```bash
   git reset --hard <previous_commit_hash>
   ```

4. **Rebuild and restart:**
   ```bash
   docker compose -f docker-compose.prod.yml build --no-cache
   docker compose -f docker-compose.prod.yml up -d
   ```

---

## Support & Resources

- **Main Guide:** [VPS_SETUP.md](VPS_SETUP.md)
- **Expert Reference:** [VPS_PRODUCTION_DEPLOYMENT.md](../VPS_PRODUCTION_DEPLOYMENT.md)
- **Email System:** [../features/email-system/README.md](../features/email-system/README.md)
- **Backup Guide:** [BACKUP.md](BACKUP.md)
- **Laravel Docs:** https://laravel.com/docs/12.x/deployment

---

**Last Verified:** 2025-01-11
**Production Deployments:** 0 (Pre-launch checklist)

**Good luck! 🚀**
