# VPS Deployment Guide - Paradocks Laravel Application

**Last Updated:** November 2025
**Status:** Production Ready
**Deployment Time:** ~1-2 hours (first-time setup)

---

## Prerequisites

### VPS Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| **RAM** | 2 GB | 4 GB |
| **CPU** | 2 cores | 3 cores |
| **Storage** | 40 GB SSD | 80 GB NVMe |
| **OS** | Ubuntu 22.04 LTS | Ubuntu 24.04 LTS |
| **Network** | 100 Mbps | 1 Gbps |
| **Bandwidth** | Unlimited preferred | Unlimited |

### Local Requirements

- SSH client (OpenSSH, PuTTY, etc.)
- SSH key pair (generated with `ssh-keygen`)
- Git installed locally
- Text editor for `.env` configuration

---

## Phase 1: VPS Initial Setup (30 minutes)

### Step 1: Connect to VPS via SSH

```bash
# Generate SSH key (if you don't have one)
ssh-keygen -t ed25519 -C "your-email@example.com"

# Copy public key to clipboard
cat ~/.ssh/id_ed25519.pub

# Connect to VPS (replace with your IP)
ssh root@YOUR_VPS_IP

# Or with custom port
ssh -p 2222 root@YOUR_VPS_IP
```

### Step 2: Update System

```bash
# Update package index
sudo apt update

# Upgrade all packages
sudo apt upgrade -y

# Install essential tools
sudo apt install -y curl wget git unzip vim htop ufw fail2ban
```

### Step 3: Configure Firewall (UFW)

```bash
# Allow SSH (IMPORTANT: Do this BEFORE enabling UFW!)
sudo ufw allow 22/tcp
# Or custom SSH port
# sudo ufw allow 2222/tcp

# Allow HTTP & HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Enable firewall
sudo ufw enable

# Check status
sudo ufw status verbose
```

**Expected Output:**
```
Status: active

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW       Anywhere
80/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere
```

### Step 4: Install Docker Engine

```bash
# Install Docker using official script
curl -fsSL https://get.docker.com | sudo sh

# Add current user to docker group (avoid using sudo)
sudo usermod -aG docker $USER

# Install Docker Compose Plugin
sudo apt install -y docker-compose-plugin

# Verify installation
docker --version
docker compose version

# Test Docker (should run without sudo after re-login)
docker run hello-world
```

**Expected Output:**
```
Docker version 26.0.0, build 2ae903e
Docker Compose version v2.26.0
```

**Note:** You may need to log out and log back in for group changes to take effect:
```bash
exit
# Then reconnect via SSH
```

### Step 5: Install UFW-Docker Script (CRITICAL for Security)

**⚠️ IMPORTANT:** Docker bypasses UFW firewall rules by default, manipulating iptables directly. This script fixes that security issue.

```bash
# Download ufw-docker script
sudo wget -O /usr/local/bin/ufw-docker \
  https://github.com/chaifeng/ufw-docker/raw/master/ufw-docker

# Make executable
sudo chmod +x /usr/local/bin/ufw-docker

# Install UFW integration
sudo ufw-docker install

# Restart UFW to apply changes
sudo systemctl restart ufw
```

**What this does:**
- Adds rules to `/etc/ufw/after.rules` that hook into Docker's `DOCKER-USER` iptables chain
- Blocks all public internet access to Docker ports by default
- Allows private networks (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16) to communicate
- Ensures Docker containers respect UFW firewall rules

**Verify installation:**
```bash
# Check if DOCKER-USER chain exists
sudo iptables -L DOCKER-USER -n

# Expected output: Should show ufw-docker rules, not just "RETURN" rule
```

**⚠️ CRITICAL:** After installation, **REBOOT the server** for iptables rules to take full effect:
```bash
sudo reboot
```

After reboot, reconnect and verify:
```bash
# Verify UFW status
sudo ufw status verbose

# Verify Docker-UFW integration
sudo iptables -L DOCKER-USER -n
```

**Why this matters:**
Without ufw-docker, even with `ufw deny 3306/tcp`, Docker will expose MySQL port 3306 publicly. This script prevents that security hole.

**References:**
- [chaifeng/ufw-docker on GitHub](https://github.com/chaifeng/ufw-docker)
- [Docker Official Docs on UFW](https://docs.docker.com/network/packet-filtering-firewalls/)

### Step 6: Create Application User (Optional but Recommended)

```bash
# Create dedicated user for Laravel app
sudo adduser paradocks

# Add to docker group
sudo usermod -aG docker paradocks

# Switch to app user
sudo su - paradocks
```

---

## Phase 2: Application Deployment (30-45 minutes)

### Step 1: Clone Repository

```bash
# Create application directory
sudo mkdir -p /var/www
sudo chown -R $USER:$USER /var/www

# Clone from GitHub
cd /var/www
git clone git@github.com:patrykgielo/paradocks.git

# Or via HTTPS
git clone https://github.com:patrykgielo/paradocks.git

# Navigate to app directory
cd paradocks
```

### Step 2: Configure Environment

```bash
# Copy production environment template
cp .env.production.example .env

# Edit configuration (use nano or vim)
nano .env
```

**Critical Values to Update:**

```bash
# Application
APP_URL=https://your-domain.com  # Your actual domain
APP_KEY=  # Will be generated in next step

# Database
DB_PASSWORD=YOUR_STRONG_DB_PASSWORD_HERE
DB_ROOT_PASSWORD=YOUR_STRONG_ROOT_PASSWORD_HERE

# Redis
REDIS_PASSWORD=YOUR_STRONG_REDIS_PASSWORD_HERE

# Gmail SMTP
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password  # From Google Account

# Google Maps
GOOGLE_MAPS_API_KEY=YOUR_API_KEY_HERE
GOOGLE_MAPS_MAP_ID=YOUR_MAP_ID_HERE
```

**Save & Exit:** `Ctrl+X` → `Y` → `Enter`

### Step 3: Generate Application Key

```bash
# Generate Laravel encryption key
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate

# Verify .env now has APP_KEY filled
grep APP_KEY .env
```

### Step 4: Build and Start Docker Containers

```bash
# Build images (first time)
docker compose -f docker-compose.prod.yml build

# Start all services
docker compose -f docker-compose.prod.yml up -d

# Check container status
docker compose -f docker-compose.prod.yml ps
```

**Expected Output:**
```
NAME                           STATUS
paradocks-app-prod             Up (healthy)
paradocks-mysql-prod           Up (healthy)
paradocks-nginx-prod           Up (healthy)
paradocks-redis-prod           Up (healthy)
paradocks-horizon-prod         Up
paradocks-scheduler-prod       Up
```

**📋 Note: File Permissions in Docker**

**⚠️ IMPORTANT:** Laravel needs write access to `storage/` and `bootstrap/cache/` directories. In Dockerized environments, permissions are set **inside the Dockerfile**, NOT on the host system.

**Why this matters:**
- Docker volumes inherit permissions from the image, not the host
- Running `chown -R www-data:www-data storage/` on host **does NOT work** with Docker volumes
- Permissions must be baked into the image during build time

**How it's done (already configured in Dockerfile):**
```dockerfile
# In Dockerfile (line 28-36)
RUN useradd -G www-data,root -u 1000 -d /home/laravel laravel
RUN mkdir -p /home/laravel/.composer && \
    chown -R laravel:laravel /home/laravel

WORKDIR /var/www
RUN chown -R laravel:laravel /var/www

USER laravel
```

**What happens:**
1. Image creates `laravel` user with UID 1000 (matches most Linux users)
2. Ownership set to `laravel:laravel` for `/var/www`
3. Container runs as `laravel` user (non-root for security)
4. Laravel can write to `storage/` and `bootstrap/cache/` inside container

**Troubleshooting permission errors:**
```bash
# If you see "Permission denied" errors in logs:
docker compose -f docker-compose.prod.yml exec app ls -la storage/
docker compose -f docker-compose.prod.yml exec app ls -la bootstrap/cache/

# Rebuild image if needed (permissions baked at build time):
docker compose -f docker-compose.prod.yml build --no-cache app
docker compose -f docker-compose.prod.yml up -d
```

**DO NOT:**
- ❌ Run `sudo chown` on host for `storage/` or `bootstrap/cache/`
- ❌ Run `chmod 777` (security risk)
- ❌ Modify permissions inside running container (not persistent)

**✅ CORRECT:** Permissions are already configured in Dockerfile. If errors occur, rebuild the image.

### Step 5: Run Database Migrations

```bash
# Run migrations (creates all tables)
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# Seed essential data
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=VehicleTypeSeeder
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=RolePermissionSeeder
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=EmailTemplateSeeder
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=SettingSeeder
```

### Step 6: Create Admin User

```bash
# Interactive admin user creation
docker compose -f docker-compose.prod.yml exec app php artisan make:filament-user

# Follow prompts:
# Name: Admin User
# Email: admin@your-domain.com
# Password: [strong password]
# Confirm password: [repeat]
```

### Step 7: Optimize Application

```bash
# Clear and cache routes, configs, views
docker compose -f docker-compose.prod.yml exec app php artisan optimize

# Verify optimization
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache
```

---

## Phase 3: SSL Configuration (15-30 minutes)

### Option A: Let's Encrypt with Certbot (Free, Automated)

**⚠️ IMPORTANT:** This guide uses standalone mode for initial certificate + webroot mode for renewals (Docker-compatible approach).

#### Step 1: Install Certbot

```bash
# Install Certbot via snap (Ubuntu 24.04 recommended method)
sudo snap install --classic certbot

# Create symlink
sudo ln -s /snap/bin/certbot /usr/bin/certbot

# Verify installation
certbot --version
```

**Expected Output:**
```
certbot 2.x.x
```

#### Step 2: Create Webroot Directory

```bash
# Create directory for ACME challenge files
sudo mkdir -p /var/www/certbot

# Set permissions
sudo chown -R www-data:www-data /var/www/certbot
```

#### Step 3: Stop Nginx Container (For Initial Certificate Only)

```bash
# Certbot needs port 80 free for standalone mode
docker compose -f docker-compose.prod.yml stop nginx
```

#### Step 4: Generate Initial SSL Certificate (Standalone Mode)

```bash
# Replace with your actual domain
sudo certbot certonly --standalone \
  -d your-domain.com \
  -d www.your-domain.com \
  --non-interactive \
  --agree-tos \
  --email your-email@example.com

# Follow prompts if not using --non-interactive
```

**Expected Output:**
```
Successfully received certificate.
Certificate is saved at: /etc/letsencrypt/live/your-domain.com/fullchain.pem
Key is saved at:         /etc/letsencrypt/live/your-domain.com/privkey.pem
This certificate expires on 2025-04-15.
```

#### Step 4: Update Nginx Configuration

Edit `docker/nginx/app.conf`:

```nginx
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com www.your-domain.com;

    # SSL Certificate
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    # SSL Configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    root /var/www/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

#### Step 6: Configure Webroot Renewal + Post-Hook

**Setup post-hook script to restart Nginx after renewal:**

```bash
# Create post-hook directory
sudo mkdir -p /etc/letsencrypt/renewal-hooks/post

# Create restart script
sudo nano /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh
```

**Paste this content:**
```bash
#!/bin/bash
set -e

DOCKER_COMPOSE_PATH="/var/www/paradocks"
LOG_FILE="/var/log/letsencrypt/post-hook.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

log_message "=== SSL certificate renewed, restarting Nginx ===" cd "$DOCKER_COMPOSE_PATH"

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

#### Step 7: Update Certbot Renewal Config for Webroot

```bash
# Edit renewal config
sudo nano /etc/letsencrypt/renewal/your-domain.com.conf
```

**Add/modify these lines:**
```ini
# BEFORE (remove this):
# authenticator = standalone

# AFTER (add this):
authenticator = webroot

[[webroot_map]]
your-domain.com = /var/www/certbot
www.your-domain.com = /var/www/certbot
```

#### Step 8: Restart Nginx + Test Auto-Renewal

```bash
# Start Nginx
docker compose -f docker-compose.prod.yml up -d nginx

# Test HTTPS
curl -I https://your-domain.com

# Test renewal (dry run - will NOT restart nginx)
sudo certbot renew --dry-run

# Check systemd timer (auto-enabled by snap)
sudo systemctl status snap.certbot.renew.timer
```

**Expected Output:**
```
● snap.certbot.renew.timer - Timer for snap application certbot.renew
     Loaded: loaded
     Active: active (waiting)
    Trigger: Next run at 02:00:00
```

**Certbot will auto-renew certificates before expiry (every 90 days) and automatically restart Nginx via post-hook.**

---

### Option B: Cloudflare (Alternative - Easier Setup)

#### Advantages:
- Automatic SSL/TLS certificates
- Free CDN (faster page loads)
- DDoS protection
- No need for Certbot

#### Steps:

1. **Add Domain to Cloudflare:**
   - Sign up at https://cloudflare.com
   - Add your domain
   - Update nameservers at domain registrar

2. **Configure DNS:**
   ```
   Type: A
   Name: @
   Content: YOUR_VPS_IP
   Proxy status: Proxied (orange cloud)

   Type: A
   Name: www
   Content: YOUR_VPS_IP
   Proxy status: Proxied (orange cloud)
   ```

3. **SSL/TLS Settings:**
   - SSL/TLS encryption mode: **Full (strict)**
   - Minimum TLS Version: **TLS 1.2**
   - Always Use HTTPS: **On**

4. **Nginx Configuration:**
   - Keep HTTP on port 80 (Cloudflare handles HTTPS)
   - No need for SSL certificates in Nginx config

---

## Phase 4: DNS Configuration (5-10 minutes)

### Point Domain to VPS

**At Your Domain Registrar (e.g., Namecheap, GoDaddy):**

```
Type: A Record
Host: @
Value: YOUR_VPS_IP
TTL: 300 (5 minutes)

Type: A Record
Host: www
Value: YOUR_VPS_IP
TTL: 300
```

**DNS Propagation:**
- Initial: 5-30 minutes
- Global: up to 48 hours (usually <2 hours)

**Check Propagation:**
```bash
# From your local machine
dig your-domain.com
nslookup your-domain.com

# Or use online tool:
# https://dnschecker.org
```

---

## Phase 5: Verification & Testing (10 minutes)

### Step 1: Check All Services

```bash
# All containers should be healthy
docker compose -f docker-compose.prod.yml ps

# Check logs for errors
docker compose -f docker-compose.prod.yml logs -f --tail=100

# Specifically check app logs
docker compose -f docker-compose.prod.yml logs app | tail -n 50
```

### Step 2: Access Application

```bash
# Test HTTP (should redirect to HTTPS)
curl -I http://your-domain.com

# Test HTTPS
curl -I https://your-domain.com

# Expected: HTTP/2 200 OK
```

**Access in Browser:**
- Homepage: `https://your-domain.com`
- Admin Panel: `https://your-domain.com/admin`
- Horizon (Queue Dashboard): `https://your-domain.com/horizon`

### Step 3: Test Email System

```bash
# Send test email
docker compose -f docker-compose.prod.yml exec app php artisan email:test --to=your-email@example.com --template=user-registered --language=pl

# Check queue processing
docker compose -f docker-compose.prod.yml exec app php artisan horizon:status

# View email logs in Filament
# Login to: https://your-domain.com/admin
# Navigate to: Email → Email Sends
```

### Step 4: Test Database Connection

```bash
# Access MySQL
docker compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p

# Enter password from .env (DB_PASSWORD)

# Show databases
SHOW DATABASES;

# Check tables
USE paradocks;
SHOW TABLES;

# Exit
EXIT;
```

---

## Phase 6: Monitoring & Maintenance

### Daily Health Checks

```bash
# Container status
docker compose -f docker-compose.prod.yml ps

# Disk usage
df -h

# Memory usage
free -h

# Check failed queue jobs
docker compose -f docker-compose.prod.yml exec app php artisan queue:failed
```

### Log Monitoring

```bash
# Application logs (Laravel)
docker compose -f docker-compose.prod.yml logs app -f --tail=100

# Nginx access logs
docker compose -f docker-compose.prod.yml logs nginx -f --tail=50

# Queue worker logs (Horizon)
docker compose -f docker-compose.prod.yml logs horizon -f

# Scheduler logs
docker compose -f docker-compose.prod.yml logs scheduler -f
```

### Performance Monitoring

**Option A: Laravel Pulse (Built-in)**

Access: `https://your-domain.com/pulse`

**Option B: Uptime Robot (Free External Monitoring)**

1. Sign up: https://uptimerobot.com
2. Add monitor:
   - Type: HTTPS
   - URL: https://your-domain.com
   - Interval: 5 minutes
3. Get alerts via email/SMS when site is down

---

## Troubleshooting

### Issue: Containers Won't Start

```bash
# Check Docker logs
docker compose -f docker-compose.prod.yml logs

# Check specific service
docker compose -f docker-compose.prod.yml logs mysql

# Rebuild from scratch
docker compose -f docker-compose.prod.yml down -v
docker compose -f docker-compose.prod.yml build --no-cache
docker compose -f docker-compose.prod.yml up -d
```

### Issue: 502 Bad Gateway (Nginx)

**Causes:**
1. PHP-FPM container not running
2. Database connection failed
3. Application error

**Fix:**
```bash
# Check app container
docker compose -f docker-compose.prod.yml logs app

# Restart app
docker compose -f docker-compose.prod.yml restart app

# Check .env configuration
cat .env | grep DB_
```

### Issue: Emails Not Sending

```bash
# Check Horizon status
docker compose -f docker-compose.prod.yml exec app php artisan horizon:status

# Restart Horizon
docker compose -f docker-compose.prod.yml restart horizon

# Check email logs in database
docker compose -f docker-compose.prod.yml exec mysql mysql -u paradocks -p -e "SELECT * FROM email_sends ORDER BY created_at DESC LIMIT 5;" paradocks
```

### Issue: SSL Certificate Errors

```bash
# Renew certificate manually
sudo certbot renew --force-renewal

# Restart Nginx
docker compose -f docker-compose.prod.yml restart nginx

# Check certificate expiry
sudo certbot certificates
```

---

## Security Best Practices

### 1. Change Default SSH Port (Optional)

```bash
# Edit SSH config
sudo nano /etc/ssh/sshd_config

# Change line:
# Port 22
# TO:
# Port 2222

# Restart SSH
sudo systemctl restart sshd

# Update firewall
sudo ufw allow 2222/tcp
sudo ufw delete allow 22/tcp
```

### 2. Disable Root Login

```bash
# Edit SSH config
sudo nano /etc/ssh/sshd_config

# Set:
PermitRootLogin no
PasswordAuthentication no

# Restart SSH
sudo systemctl restart sshd
```

### 3. Install Fail2Ban (Brute Force Protection)

```bash
# Already installed in Step 2
# Configure for SSH
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

# Edit
sudo nano /etc/fail2ban/jail.local

# Find [sshd] section, set:
enabled = true
maxretry = 3
bantime = 3600

# Restart Fail2Ban
sudo systemctl restart fail2ban

# Check banned IPs
sudo fail2ban-client status sshd
```

### 4. Enable Automatic Security Updates

```bash
# Install unattended-upgrades
sudo apt install -y unattended-upgrades

# Enable automatic updates
sudo dpkg-reconfigure -plow unattended-upgrades

# Configure
sudo nano /etc/apt/apt.conf.d/50unattended-upgrades

# Enable security updates:
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
```

---

## Backup Strategy

See: `docs/deployment/BACKUP.md` for comprehensive backup guide.

**Quick Backup Commands:**

```bash
# Database backup
./scripts/backup-database.sh

# Full application backup (code + database)
./scripts/backup-full.sh

# Restore from backup
./scripts/restore-database.sh backup_20250110.sql.gz
```

---

## Cost Estimate (Monthly)

| Service | Cost | Notes |
|---------|------|-------|
| VPS (Hetzner CPX21) | €7.59 | 4GB RAM, 80GB SSD |
| Automated Backups | €1.52 | Hetzner snapshots |
| Off-site Backup Storage | €3.81 | Hetzner Storage Box 100GB |
| Domain Name | €1-2 | Annual cost divided by 12 |
| SSL Certificate | €0 | Let's Encrypt (free) |
| CDN | €0 | Cloudflare Free Tier |
| Monitoring | €0 | UptimeRobot Free Tier |
| **TOTAL** | **~€13-15/month** | **~60 PLN/month** |

---

## Support & Resources

- **Laravel Documentation:** https://laravel.com/docs/12.x
- **Docker Documentation:** https://docs.docker.com
- **Let's Encrypt:** https://letsencrypt.org/docs/
- **Project Documentation:** `/docs/README.md`
- **Email System:** `/docs/features/email-system/README.md`
- **Troubleshooting:** `/docs/features/email-system/troubleshooting.md`

---

## Next Steps

1. ✅ VPS configured and running
2. ✅ Application deployed with SSL
3. ✅ Database seeded with initial data
4. → **Setup automated backups** (see `BACKUP.md`)
5. → **Configure monitoring** (UptimeRobot, Laravel Pulse)
6. → **Implement CI/CD** (GitHub Actions - see `CI_CD.md`)
7. → **Load testing** (k6, Apache Bench)
8. → **Performance optimization** (Redis caching, Opcache tuning)

**Congratulations! Your Paradocks application is now live in production! 🎉**
