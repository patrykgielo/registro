# Commands Reference

**Last Updated:** November 2025

This guide provides a comprehensive reference of all available commands for development, testing, and deployment.

## Backend Development Commands

### Composer Dependencies

```bash
# Install PHP dependencies
cd app && composer install

# Update dependencies
cd app && composer update

# Update specific package
cd app && composer update vendor/package-name
```

### Development Servers

```bash
# Start all services (RECOMMENDED)
cd app && composer run dev

# Start individual services
cd app && php artisan serve                    # Laravel dev server (port 8000)
cd app && php artisan queue:listen --tries=1   # Queue worker
cd app && php artisan pail --timeout=0         # Real-time log viewer
```

**Note:** `composer run dev` uses Laravel's concurrent command runner to start all services simultaneously.

### Database Operations

```bash
# Run migrations
cd app && php artisan migrate

# Run migrations (in Docker)
docker compose exec app php artisan migrate

# Rollback last migration
docker compose exec app php artisan migrate:rollback

# Fresh migrations with seeding
docker compose exec app php artisan migrate:fresh --seed

# ⚠️ IMPORTANT: After migrate:fresh, ALWAYS run these seeders:
docker compose exec app php artisan db:seed --class=VehicleTypeSeeder
docker compose exec app php artisan db:seed --class=RolePermissionSeeder
docker compose exec app php artisan db:seed --class=ServiceAvailabilitySeeder
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder
docker compose exec app php artisan db:seed --class=SettingSeeder

# Then recreate admin user
docker compose exec app php artisan make:filament-user
```

### Interactive Shell

```bash
# Laravel Tinker (interactive PHP REPL)
cd app && php artisan tinker

# In Docker
docker compose exec app php artisan tinker

# Example usage:
>>> $user = User::first();
>>> $user->name
>>> event(new \App\Events\UserRegistered($user));
>>> exit
```

## Frontend Development Commands

### npm Dependencies

```bash
# Install Node.js dependencies
cd app && npm install

# Update dependencies
cd app && npm update

# Audit for vulnerabilities
cd app && npm audit
cd app && npm audit fix
```

### Asset Compilation

```bash
# Development mode with hot reload
cd app && npm run dev

# Production build (generates public/build/ with manifest.json)
cd app && npm run build

# Verify build output
ls -la app/public/build/
cat app/public/build/.vite/manifest.json
```

**See Also:** [Production Build Guide](./production-build.md) for Tailwind CSS 4.0 specifics

## Testing Commands

### Run Tests

```bash
# Run all tests
cd app && composer run test
# OR
cd app && php artisan test

# Run specific test suite
cd app && php artisan test --testsuite=Unit
cd app && php artisan test --testsuite=Feature

# Run specific test file
cd app && php artisan test tests/Feature/AppointmentTest.php

# Run with coverage
cd app && php artisan test --coverage
```

### Code Quality

```bash
# Laravel Pint (code formatting)
cd app && ./vendor/bin/pint

# Format specific files
cd app && ./vendor/bin/pint app/Models/User.php

# Dry run (check without formatting)
cd app && ./vendor/bin/pint --test
```

## Docker Commands

### Container Management

```bash
# Start all containers
docker compose up -d

# Start specific service
docker compose up -d nginx mysql

# Stop all containers
docker compose down

# Stop and remove volumes (⚠️ DELETES DATABASE)
docker compose down -v

# Restart specific service
docker compose restart app nginx

# View running containers
docker compose ps
```

### Logs

```bash
# View logs from all services
docker compose logs -f

# View logs from specific service
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f mysql
docker compose logs -f horizon

# View last 100 lines
docker compose logs --tail=100 app
```

### Artisan Commands (via Docker)

```bash
# Run artisan commands
docker compose exec app php artisan <command>

# Examples:
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
```

### Composer Commands (via Docker)

```bash
# Run composer commands
docker compose exec app composer <command>

# Examples:
docker compose exec app composer install
docker compose exec app composer update
docker compose exec app composer require vendor/package
```

### npm Commands (via Docker)

```bash
# Run npm commands via Node container
docker compose exec node npm <command>

# Examples:
docker compose exec node npm install
docker compose exec node npm run build
docker compose exec node npm audit
```

### MySQL Commands (via Docker)

```bash
# Interactive MySQL shell
docker compose exec mysql mysql -u registro -ppassword registro

# Run SQL query
docker compose exec mysql mysql -u registro -ppassword -e "SELECT * FROM users LIMIT 5;" registro

# Check table structure
docker compose exec mysql mysql -u registro -ppassword -e "DESCRIBE appointments;" registro

# Backup database
docker compose exec mysql mysqldump -u registro -ppassword registro > backup_$(date +%Y%m%d).sql

# Restore database
docker compose exec -T mysql mysql -u registro -ppassword registro < backup.sql
```

## Queue Commands

### Queue Workers

```bash
# Start queue worker (development)
docker compose exec app php artisan queue:work redis --queue=emails,reminders,default --tries=3

# Start Horizon (production - preferred)
docker compose exec app php artisan horizon

# Check queue status
docker compose exec app php artisan queue:monitor redis:emails,redis:reminders

# Retry failed jobs
docker compose exec app php artisan queue:retry all

# Retry specific job
docker compose exec app php artisan queue:retry <job-id>

# Clear failed jobs
docker compose exec app php artisan queue:flush

# List failed jobs
docker compose exec app php artisan queue:failed
```

**Note:** Horizon provides a web dashboard at `/horizon`

## Scheduler Commands

```bash
# Manually run scheduled tasks
docker compose exec app php artisan schedule:run --verbose

# List scheduled tasks
docker compose exec app php artisan schedule:list

# Test scheduled task (via Tinker)
docker compose exec app php artisan tinker
>>> \App\Jobs\Email\SendReminderEmailsJob::dispatch();
```

**Note:** Scheduler runs automatically in Docker via the `scheduler` container

## Filament Commands

```bash
# Create Filament resource
docker compose exec app php artisan make:filament-resource ModelName

# Create Filament page
docker compose exec app php artisan make:filament-page PageName

# Create Filament widget
docker compose exec app php artisan make:filament-widget WidgetName

# Create admin user
docker compose exec app php artisan make:filament-user

# Optimize Filament for production
docker compose exec app php artisan filament:optimize

# Clear Filament cache
docker compose exec app php artisan filament:clear-cached-components
```

## Cache & Optimization Commands

```bash
# Clear all caches
docker compose exec app php artisan optimize:clear

# Individual cache commands
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear

# Optimize for production
docker compose exec app php artisan optimize
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

## Email Testing Commands

```bash
# Test email flow (all scenarios)
docker compose exec app php artisan email:test-flow --all

# Test specific user
docker compose exec app php artisan email:test-flow --user=1

# Test specific appointment
docker compose exec app php artisan email:test-flow --appointment=1

# Simple email test
docker compose exec app php artisan email:test
```

**See Also:** [Email System Documentation](../features/email-system/README.md)

## Debugging Commands

```bash
# View Laravel logs
docker compose exec app tail -f storage/logs/laravel.log

# View logs with filtering
docker compose exec app tail -f storage/logs/laravel.log | grep Error

# Check environment variables
docker compose exec app php artisan tinker
>>> config('app.name')
>>> config('services.google_maps.api_key')

# Check routes
docker compose exec app php artisan route:list

# Check routes with filtering
docker compose exec app php artisan route:list --path=api
docker compose exec app php artisan route:list --name=admin
```

## Maintenance Commands

```bash
# Put application in maintenance mode
docker compose exec app php artisan down

# Bring application up
docker compose exec app php artisan up

# Allow specific IPs during maintenance
docker compose exec app php artisan down --allow=127.0.0.1

# Custom maintenance message
docker compose exec app php artisan down --message="Scheduled maintenance"
```

## Custom Scripts (composer.json)

```bash
# Run all development services
cd app && composer run dev

# Run tests
cd app && composer run test

# Format code
cd app && composer run format
```

## See Also

- [Quick Start Guide](./quick-start.md) - Initial setup
- [Docker Guide](./docker.md) - Docker architecture and services
- [Production Build](./production-build.md) - Asset compilation for production
- [Troubleshooting](./troubleshooting.md) - Common issues and solutions
