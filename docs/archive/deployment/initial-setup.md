# Initial Production Setup Guide

**Complete guide for deploying Paradocks to a fresh production environment.**

## Table of Contents

- [Prerequisites](#prerequisites)
- [Step 1: Clone Repository](#step-1-clone-repository)
- [Step 2: Install Dependencies](#step-2-install-dependencies)
- [Step 3: Configure Environment](#step-3-configure-environment)
- [Step 4: Start Docker Containers](#step-4-start-docker-containers)
- [Step 5: Run Database Migrations](#step-5-run-database-migrations)
- [Step 6: Seed Production Data](#step-6-seed-production-data)
- [Step 7: Create First Super Admin](#step-7-create-first-super-admin)
- [Step 8: Verify Application](#step-8-verify-application)
- [Step 9: Optimize for Production](#step-9-optimize-for-production)
- [Step 10: Verify Services](#step-10-verify-services)
- [Security Checklist](#security-checklist)
- [Troubleshooting](#troubleshooting)

---

## Prerequisites

**Server Requirements:**
- Ubuntu 22.04 LTS or later
- 2GB RAM minimum (4GB recommended)
- 20GB disk space
- Docker Engine 24.0+ and Docker Compose 2.20+
- Git 2.30+
- Root or sudo access

**External Services:**
- MySQL 8.0+ (or use Docker container)
- Redis 7.0+ (or use Docker container)
- Gmail account with App Password (for SMTP)
- Google Maps API key with Places API enabled

**Network:**
- Open ports: 80 (HTTP), 443 (HTTPS), 8444 (dev HTTPS)
- Domain DNS configured (A record pointing to server IP)

---

## Step 1: Clone Repository

```bash
# SSH into your production server
ssh root@your-server-ip

# Navigate to web root
cd /var/www/projects

# Clone repository
git clone https://github.com/your-username/paradocks.git
cd paradocks/app

# Checkout main branch (production)
git checkout main
git pull origin main
```

**Verify:**
```bash
git branch --show-current  # Should show: main
git log -1                 # Should show latest production commit
```

---

## Step 2: Install Dependencies

### PHP Dependencies (Composer)

```bash
# Install Composer dependencies (production mode, no dev packages)
docker compose run --rm app composer install --optimize-autoloader --no-dev

# Or if Composer already available locally:
cd app
composer install --optimize-autoloader --no-dev
```

### JavaScript Dependencies (npm)

```bash
# Install npm packages
docker compose run --rm node npm ci

# Build production assets
docker compose run --rm node npm run build
```

**Verify build output:**
```bash
ls -la public/build/
# Should contain:
# - public/build/assets/app-[hash].css
# - public/build/assets/app-[hash].js
# - public/build/.vite/manifest.json
```

---

## Step 3: Configure Environment

### Create .env File

```bash
# Copy example environment file
cp .env.example .env

# Edit .env with production values
nano .env
```

### Critical Environment Variables

**Application:**
```bash
APP_NAME=Paradocks
APP_ENV=production
APP_KEY=        # Generate in next step
APP_DEBUG=false
APP_TIMEZONE=Europe/Warsaw
APP_URL=https://paradocks.com
```

**Database:**
```bash
DB_CONNECTION=mysql
DB_HOST=paradocks-mysql     # Docker service name
DB_PORT=3306
DB_DATABASE=paradocks
DB_USERNAME=paradocks
DB_PASSWORD=STRONG_PASSWORD_HERE  # Generate secure password
```

**Queue & Cache:**
```bash
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis               # Docker service name
REDIS_PASSWORD=REDIS_PASSWORD  # Generate secure password
REDIS_PORT=6379
```

**Email (Gmail SMTP):**
```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=xxxx-xxxx-xxxx-xxxx  # 16-character App Password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

**Google Maps:**
```bash
GOOGLE_MAPS_API_KEY=AIzaSy...
GOOGLE_MAPS_MAP_ID=your_map_id
```

### Generate Application Key

```bash
docker compose exec app php artisan key:generate
```

**Verify:**
```bash
grep APP_KEY .env
# Should show: APP_KEY=base64:...
```

---

## Step 4: Start Docker Containers

### Configure docker-compose.yml

Edit `docker-compose.yml` for production:

```yaml
services:
  app:
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      # Add all critical env vars here (see Step 3)
```

### Start All Services

```bash
# Start all containers in detached mode
docker compose up -d

# Verify all containers running
docker compose ps

# Check logs for errors
docker compose logs -f app
```

**Expected containers:**
- `paradocks-app` (PHP-FPM)
- `paradocks-nginx` (reverse proxy)
- `paradocks-mysql` (database)
- `paradocks-redis` (cache/queue)
- `paradocks-horizon` (queue worker)
- `paradocks-scheduler` (cron jobs)
- `paradocks-queue` (queue listener)

---

## Step 5: Run Database Migrations

```bash
# Run all migrations
docker compose exec app php artisan migrate --force

# Verify migrations
docker compose exec app php artisan migrate:status
```

**Output should show:**
```
Migration name .................................. Batch / Status
2024_01_01_000000_create_users_table ........... [1] Ran
2024_01_02_000000_create_services_table ........ [1] Ran
...
```

---

## Step 6: Seed Production Data

**IMPORTANT:** DatabaseSeeder only seeds lookup data, NOT users!

```bash
# Run all production seeders
docker compose exec app php artisan db:seed --force
```

**What gets seeded (v0.3.0):**
1. **SettingSeeder** - Application configuration (booking, map, contact settings)
2. **RolePermissionSeeder** - 4 roles (super-admin, admin, staff, customer) + permissions
3. **VehicleTypeSeeder** - 5 vehicle types (Sedan, SUV, Kombi, Coupe, Van)
4. **ServiceSeeder** - 8 car detailing services (Mycie podstawowe, Premium, etc.)
5. **EmailTemplateSeeder** - 28 email templates (14 types × 2 languages)
6. **SmsTemplateSeeder** - 14 SMS templates (7 types × 2 languages)

**Verify seeding:**
```bash
# Check services seeded
docker compose exec app php artisan tinker
>>> App\Models\Service::count()
=> 8

>>> App\Models\Service::pluck('name')
=> [
     "Mycie podstawowe",
     "Mycie premium",
     "Korekta lakieru",
     ...
   ]

>>> exit
```

---

## Step 7: Create First Super Admin

**CRITICAL: This is the ONLY way to create admin users in production.**

### Using Filament's Built-in Command (Recommended)

```bash
docker compose exec app php artisan make:filament-user
```

**Interactive prompts:**
```
 Name:
 > Jan Kowalski

 Email address:
 > patryk.gieloo@gmail.com

 Password:
 > ****************  (min 12 characters, hidden input)
```

**What happens:**
1. Password validated (min 12 chars, checked against breach database)
2. User created with hashed password (bcrypt, 12 rounds)
3. `super-admin` role assigned automatically
4. User can access `/admin` panel immediately

### Password Requirements

**Minimum requirements:**
- 12 characters minimum
- At least one uppercase letter (A-Z)
- At least one lowercase letter (a-z)
- At least one number (0-9)
- At least one symbol (!@#$%^&*)
- NOT in breach database (checked via Have I Been Pwned API)

**Example strong passwords:**
- `MyP@radocks2025!`
- `D3tailing#Secure`
- `Admin!Pass123$`

**DO NOT use:**
- `password`, `admin`, `12345678`
- Anything in common password lists
- Passwords previously breached

### Verify Admin Created

```bash
# Check user exists
docker compose exec app php artisan tinker
>>> App\Models\User::where('email', 'patryk.gieloo@gmail.com')->first()
=> App\Models\User {#...}

>>> $user = App\Models\User::where('email', 'patryk.gieloo@gmail.com')->first()
>>> $user->getRoleNames()
=> ["super-admin"]

>>> exit
```

---

## Step 8: Verify Application

### Test Web Access

```bash
# Access application
https://paradocks.com          # Public frontend
https://paradocks.com/admin    # Admin panel
```

**Login with admin credentials:**
- Email: `patryk.gieloo@gmail.com`
- Password: (your chosen password)

### Verify Admin Panel Features

**Check these sections work:**
1. `/admin/services` - Should show 8 services
2. `/admin/vehicle-types` - Should show 5 vehicle types
3. `/admin/email-templates` - Should show 28 templates
4. `/admin/users` - Should show 1 user (you)
5. `/admin/settings` - Should show all settings groups

### Create First Staff Member (Optional)

1. Navigate to `/admin/employees`
2. Click "Nowy pracownik"
3. Fill in details (name, email, phone)
4. Assign role: `staff`
5. Assign services (which services they can perform)
6. Create base schedule: `/admin/staff-schedules`

---

## Step 9: Optimize for Production

### Cache Configuration

```bash
# Cache config files
docker compose exec app php artisan config:cache

# Cache routes
docker compose exec app php artisan route:cache

# Cache views
docker compose exec app php artisan view:cache

# Optimize autoloader
docker compose exec app composer dump-autoload --optimize --classmap-authoritative
```

### Filament Optimization

```bash
# Optimize Filament assets
docker compose exec app php artisan filament:optimize
```

### Verify Optimizations

```bash
# Should show cached files
ls -la storage/framework/cache/
ls -la storage/framework/views/
```

---

## Step 10: Verify Services

### Check Queue Workers

```bash
# Verify Horizon running
docker compose logs -f horizon

# Access Horizon dashboard
https://paradocks.com/horizon
```

**Should show:**
- ✅ Active workers
- ✅ No failed jobs
- ✅ Processed jobs count

### Check Scheduler

```bash
# Verify scheduler running
docker compose logs -f scheduler

# Test scheduler manually
docker compose exec app php artisan schedule:run
```

### Check Email Sending

```bash
# Send test email
docker compose exec app php artisan tinker
>>> Mail::raw('Test email', function($msg) {
      $msg->to('patryk.gieloo@gmail.com')->subject('Test');
    });
>>> exit

# Check queue processed
# Visit: https://paradocks.com/horizon
```

---

## Security Checklist

**Before going live, verify:**

- [ ] `APP_ENV=production` in `.env`
- [ ] `APP_DEBUG=false` in `.env`
- [ ] Strong `APP_KEY` generated
- [ ] Strong database password (20+ characters)
- [ ] Strong Redis password (20+ characters)
- [ ] Gmail App Password configured (NOT regular password)
- [ ] Google Maps API key restricted (HTTP referrers)
- [ ] Strong admin password (12+ characters, not breached)
- [ ] SSL certificate installed (HTTPS working)
- [ ] Firewall configured (only 80, 443, 22 open)
- [ ] File permissions correct (`storage/` and `bootstrap/cache/` writable)
- [ ] `.env` file NOT in version control (`.gitignore` configured)
- [ ] Backups configured (database + uploads)
- [ ] Monitoring configured (error logging, uptime)

**Admin Panel Access:**
- [ ] Super admin account created (`patryk.gieloo@gmail.com`)
- [ ] Admin panel accessible (`/admin`)
- [ ] 2FA enabled (optional but recommended)
- [ ] Session timeout configured (default 120 minutes)

**Email System:**
- [ ] Test email sent successfully
- [ ] Queue workers processing emails
- [ ] Email templates loaded (28 templates)
- [ ] SMTP credentials valid

---

## Troubleshooting

### Issue: "Base table or view not found"

**Cause:** Migrations not run

**Fix:**
```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

### Issue: "No services available in booking wizard"

**Cause:** ServiceSeeder not run (bug in v0.2.x)

**Fix:**
```bash
# v0.3.0+ includes ServiceSeeder in DatabaseSeeder
docker compose exec app php artisan db:seed --force

# OR run directly:
docker compose exec app php artisan db:seed --class=ServiceSeeder
```

### Issue: "Permission denied: storage/framework/views"

**Cause:** File permissions incorrect

**Fix:**
```bash
# Inside container
docker compose exec app chmod -R 775 storage bootstrap/cache
docker compose exec app chown -R www-data:www-data storage bootstrap/cache

# Or rebuild container
docker compose build --no-cache app
docker compose restart app
```

### Issue: "Could not find driver (PDO MySQL)"

**Cause:** PHP MySQL extension not installed

**Fix:**
```bash
# Rebuild app container
docker compose build --no-cache app
docker compose up -d app
```

### Issue: "Connection refused (Redis)"

**Cause:** Redis container not running or wrong host

**Fix:**
```bash
# Verify .env
REDIS_HOST=redis  # NOT localhost!

# Restart Redis
docker compose restart redis
```

### Issue: "Emails not sending"

**Cause:** Queue workers not running or Gmail password wrong

**Fix:**
```bash
# Check Gmail App Password (16 characters, no spaces)
# NOT regular Gmail password!

# Restart queue workers
docker compose restart horizon queue

# Check Horizon dashboard
https://paradocks.com/horizon/failed
```

### Issue: "Admin panel 403 Forbidden"

**Cause:** User doesn't have admin role

**Fix:**
```bash
docker compose exec app php artisan tinker
>>> $user = App\Models\User::where('email', 'patryk.gieloo@gmail.com')->first();
>>> $user->assignRole('super-admin');
>>> exit
```

---

## Next Steps

After successful deployment:

1. **Configure domain DNS** - Point A record to server IP
2. **Install SSL certificate** - Use Let's Encrypt (Certbot)
3. **Configure backups** - Database dumps + file backups
4. **Set up monitoring** - Error tracking (Sentry), uptime monitoring
5. **Create staff accounts** - Add employees via `/admin/employees`
6. **Configure staff schedules** - Set working hours via `/admin/staff-schedules`
7. **Add content** - Create pages, posts, promotions via CMS
8. **Test booking flow** - Create test appointment as customer
9. **Configure Google Analytics** - Track visitor behavior
10. **Launch marketing** - Social media, Google Ads, SEO

---

## Related Documentation

- [Git Workflow](GIT_WORKFLOW.md) - Deployment via GitHub Actions
- [Environment Variables](environment-variables.md) - Complete .env reference
- [CI/CD Deployment Runbook](runbooks/ci-cd-deployment.md) - Automated deployments
- [Deployment History](deployment-history.md) - Version history and lessons learned
- [Quick Start Guide](../guides/quick-start.md) - Local development setup

---

**Last Updated:** 2025-12-01 (v0.3.0)
**Maintained By:** Paradocks Development Team
**Questions:** Open GitHub issue or contact patryk.gieloo@gmail.com
