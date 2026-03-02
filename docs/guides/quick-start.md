# Quick Start Guide

**Last Updated:** November 2025
**Estimated Time:** 15 minutes

## Prerequisites

Before starting, ensure you have:

- **Docker** 20.10+ and **Docker Compose** 2.0+
- **Git** for version control
- **Terminal** access (bash, zsh, or equivalent)
- **Text editor** or IDE (VS Code, PHPStorm, etc.)

## Step 1: Clone Repository

```bash
git clone <repository-url> registro
cd registro
```

## Step 2: One-Command Setup

Run the automated initialization script:

```bash
./docker-init.sh
```

This script automatically:
1. Generates self-signed SSL certificates (`docker/ssl/`)
2. Builds Docker containers (PHP, Nginx, MySQL, Node, Redis)
3. Installs PHP dependencies (`composer install`)
4. Installs Node.js dependencies (`npm install`)
5. Runs database migrations
6. Seeds initial data

**Expected output:** You should see green success messages for each step.

## Step 3: Add Domain to Hosts File

Add the local domain to your system's hosts file:

```bash
sudo ./add-hosts-entry.sh
```

**What it does:** Adds `127.0.0.1 registro.local` to `/etc/hosts`

**Manual alternative:**
```bash
echo "127.0.0.1 registro.local" | sudo tee -a /etc/hosts
```

## Step 4: Run Migrations & Seeders

✅ **AUTOMATIC SETUP (v0.3.1+):** Migrations handle both schema AND reference data automatically:

```bash
# One command for everything (schema + data)
docker compose exec app php artisan migrate:fresh --seed

# What happens:
# 1. Schema migrations create tables (users, appointments, email_templates, etc.)
# 2. Data migrations seed reference data (email templates, SMS templates)
# 3. DatabaseSeeder adds development data (settings, roles, vehicle types, services)
```

**Development vs Production:**
- **Development:** `migrate:fresh --seed` (wipes DB, creates schema, seeds ALL data)
- **Production:** `migrate --force` (runs new migrations only, preserves existing data)

**Note:** Email/SMS templates are now seeded via **data migrations** (not seeders), ensuring proper versioning in production.

## Step 5: Create Admin User

Create your Filament admin panel user:

```bash
docker compose exec app php artisan make:filament-user
```

**Prompts:**
- **Name:** Your full name
- **Email:** your-email@example.com
- **Password:** Choose a secure password

## Step 6: Access Application

### Main URLs

- **Application:** https://registro.local:8444
- **Admin Panel:** https://registro.local:8444/admin
- **Horizon (Queue Monitor):** https://registro.local:8444/horizon

### Accept SSL Certificate

Since we use self-signed certificates, you'll see a security warning on first visit.

**Chrome/Edge:** Click "Advanced" → "Proceed to registro.local (unsafe)"

**Firefox:** Click "Advanced" → "Accept the Risk and Continue"

**For permanent trust:** See [docker/ssl/README.md](../../docker/ssl/README.md)

## Step 7: Start Development Servers

### Backend (Laravel + Queue + Logs)

```bash
cd app && composer run dev
```

**This starts:**
- Laravel development server (port 8000)
- Queue worker (Redis backend)
- Real-time log viewer (Pail)
- Vite dev server (port 5173)

### Frontend Only (Tailwind CSS Hot Reload)

```bash
cd app && npm run dev
```

## Verify Installation

### Check Docker Containers

```bash
docker compose ps
```

**Expected:** All services should show "Up" status:
- `app` (PHP-FPM)
- `nginx` (Reverse proxy)
- `mysql` (Database)
- `node` (Vite dev server)
- `redis` (Queue backend)
- `queue` (Queue worker)
- `horizon` (Queue monitor)
- `scheduler` (Task scheduler)

### Test Database Connection

```bash
docker compose exec mysql mysql -u registro -ppassword -e "SELECT COUNT(*) FROM users;" registro
```

**Expected:** Should return user count (at least 1 after creating admin)

### Test Admin Panel Login

1. Visit https://registro.local:8444/admin
2. Login with credentials from Step 5
3. You should see the Filament dashboard

## Common Issues

### Issue: Port Already in Use

**Error:** `Bind for 0.0.0.0:8444 failed: port is already allocated`

**Solution:**
```bash
# Find process using port
sudo lsof -i :8444

# Kill process or change port in docker-compose.yml
```

### Issue: Permission Denied on docker-init.sh

**Error:** `bash: ./docker-init.sh: Permission denied`

**Solution:**
```bash
chmod +x docker-init.sh add-hosts-entry.sh
```

### Issue: MySQL Connection Refused

**Solution:**
```bash
# Check if MySQL container is running
docker compose ps mysql

# Restart MySQL container
docker compose restart mysql

# Check logs
docker compose logs mysql
```

### Issue: npm run dev Fails (Vite)

**Solution:**
```bash
# Clear Vite cache
rm -rf app/node_modules/.vite

# Reinstall Node dependencies
cd app && npm ci
```

## Next Steps

Now that your environment is set up:

1. **Explore the codebase:** See [Project Map](../project_map.md)
2. **Run tests:** `cd app && composer run test`
3. **Read architecture:** [Architecture Overview](../architecture/)
4. **Configure settings:** Admin Panel → System Settings
5. **Set up email:** Configure Gmail SMTP in System Settings

## Daily Workflow

### Start Development

```bash
# Start Docker containers
docker compose up -d

# Start development servers
cd app && composer run dev
```

### Stop Development

```bash
# Stop dev servers: Ctrl+C in terminal

# Stop Docker containers
docker compose down
```

### Reset Database

```bash
docker compose exec app php artisan migrate:fresh --seed

# IMPORTANT: Run required seeders (see Step 4)
docker compose exec app php artisan db:seed --class=VehicleTypeSeeder
docker compose exec app php artisan db:seed --class=RolePermissionSeeder
docker compose exec app php artisan db:seed --class=ServiceAvailabilitySeeder
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder
docker compose exec app php artisan db:seed --class=SettingSeeder

# Recreate admin user
docker compose exec app php artisan make:filament-user
```

## See Also

- [Docker Guide](./docker.md) - Detailed Docker commands and architecture
- [Commands Reference](./commands.md) - All available commands
- [Database Schema](../architecture/database-schema.md) - Database structure
- [Troubleshooting](./troubleshooting.md) - Common issues and solutions
