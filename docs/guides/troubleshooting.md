# Troubleshooting Guide

**Last Updated:** December 2025

This guide provides solutions to common issues across all parts of the application. For feature-specific troubleshooting, see the links at the bottom.

## Table of Contents

- [Build & Assets Issues](#build--assets-issues)
- [Database & Migration Issues](#database--migration-issues)
- [Queue & Jobs Issues](#queue--jobs-issues)
- [Cache & OPcache Issues](#cache--opcache-issues)
- [Filament Form Issues](#filament-form-issues) ⭐ NEW
- [Performance Issues](#performance-issues)
- [Docker Issues](#docker-issues)
- [Google Maps Issues](#google-maps-issues)

## Quick Diagnostics

### System Health Check

```bash
# Check Docker containers
docker compose ps

# Check Laravel application
docker compose exec app php artisan about

# Check database connection
docker compose exec app php artisan tinker
>>> DB::connection()->getPdo();

# Check queue status
docker compose exec app php artisan queue:monitor
```

## Build & Assets Issues

### CSS Not Loading / Styles Missing

**Symptoms:**
- Page loads but looks unstyled
- Tailwind classes don't work
- Browser shows 404 for CSS file

**Solutions:**
1. **Verify build ran successfully:**
   ```bash
   cd app && npm run build
   ls app/public/build/assets/
   ```

2. **Check manifest exists:**
   ```bash
   cat app/public/build/.vite/manifest.json
   ```

3. **Clear Laravel cache:**
   ```bash
   docker compose exec app php artisan optimize:clear
   ```

4. **Check @vite directive in layout:**
   ```blade
   @vite(['resources/css/app.css', 'resources/js/app.js'])
   ```

**See:** [Production Build Guide](./production-build.md)

### Vite Dev Server Not Working

**Symptoms:**
- `npm run dev` fails
- Port 5173 already in use
- HMR (hot reload) not working

**Solutions:**
1. **Kill existing Vite process:**
   ```bash
   lsof -ti:5173 | xargs kill -9
   ```

2. **Clear Vite cache:**
   ```bash
   rm -rf app/node_modules/.vite
   ```

3. **Reinstall dependencies:**
   ```bash
   cd app && npm ci
   ```

## Docker Issues

### Containers Won't Start

**Symptoms:**
- `docker compose up -d` fails
- Containers show "Exited" status
- Port binding errors

**Solutions:**
1. **Check ports are available:**
   ```bash
   sudo lsof -i :8444  # Nginx HTTPS
   sudo lsof -i :3306  # MySQL
   sudo lsof -i :6379  # Redis
   ```

2. **View container logs:**
   ```bash
   docker compose logs nginx
   docker compose logs app
   docker compose logs mysql
   ```

3. **Rebuild containers:**
   ```bash
   docker compose down
   docker compose up -d --build
   ```

**See:** [Docker Guide](./docker.md#troubleshooting)

### Permission Denied Errors

**Symptoms:**
- Laravel shows "Permission denied" for storage/logs
- Cannot write to cache
- File upload fails

**Solutions:**
```bash
# Fix storage permissions
docker compose exec app chmod -R 775 storage bootstrap/cache

# Reset file ownership
sudo chown -R $USER:$USER app/
```

## Database Issues

### Connection Refused

**Symptoms:**
- Laravel shows "Connection refused" error
- Cannot connect to MySQL
- Migration fails

**Solutions:**
1. **Check MySQL container:**
   ```bash
   docker compose ps mysql
   docker compose logs mysql
   ```

2. **Verify .env credentials:**
   ```bash
   DB_HOST=registro-mysql  # NOT localhost!
   DB_DATABASE=registro
   DB_USERNAME=registro
   DB_PASSWORD=password
   ```

3. **Restart MySQL:**
   ```bash
   docker compose restart mysql
   ```

**See:** [Database Schema](../architecture/database-schema.md#quick-mysql-access)

### Migration Fails

**Symptoms:**
- `php artisan migrate` shows SQL error
- Foreign key constraint fails
- Table already exists

**Solutions:**
1. **Fresh migrations (⚠️ DELETES DATA):**
   ```bash
   docker compose exec app php artisan migrate:fresh --seed
   ```

2. **Check migration status:**
   ```bash
   docker compose exec app php artisan migrate:status
   ```

3. **Rollback and retry:**
   ```bash
   docker compose exec app php artisan migrate:rollback
   docker compose exec app php artisan migrate
   ```

## Queue & Email Issues

### Emails Not Sending

**Symptoms:**
- Email notifications don't arrive
- Queue jobs stuck
- Horizon shows failed jobs

**Solutions:**
1. **Check queue worker:**
   ```bash
   docker compose ps queue horizon
   docker compose logs -f queue
   ```

2. **Check failed jobs:**
   ```bash
   docker compose exec app php artisan queue:failed
   docker compose exec app php artisan queue:retry all
   ```

3. **Verify SMTP settings:**
   - Admin Panel → System Settings → Email
   - Test connection button
   - Check `.env` for correct credentials

**See:** [Email System Troubleshooting](../features/email-system/troubleshooting.md)

### Queue Worker Not Processing Jobs

**Symptoms:**
- Jobs stay in "pending" status
- Horizon shows "inactive" supervisor
- Queue length keeps growing

**Solutions:**
1. **Restart queue worker:**
   ```bash
   docker compose restart queue horizon
   ```

2. **Check Redis connection:**
   ```bash
   docker compose exec redis redis-cli PING
   # Should return: PONG
   ```

3. **Monitor queue in real-time:**
   ```bash
   docker compose exec app php artisan queue:monitor redis:emails,redis:reminders
   ```

## Authentication & Access Issues

### Cannot Login to Admin Panel

**Symptoms:**
- Login page shows "These credentials do not match"
- User exists but can't access /admin
- Page redirects to /login

**Solutions:**
1. **Verify user has admin role:**
   ```bash
   docker compose exec app php artisan tinker
   >>> $user = User::where('email', 'admin@example.com')->first();
   >>> $user->roles()->pluck('name');
   ```

2. **Reset password:**
   ```bash
   docker compose exec app php artisan tinker
   >>> $user = User::where('email', 'admin@example.com')->first();
   >>> $user->password = Hash::make('newpassword');
   >>> $user->save();
   ```

3. **Check canAccessPanel() method:**
   ```php
   // app/Models/User.php
   public function canAccessPanel(): bool
   {
       return str_ends_with($this->email, '@example.com')
           && $this->hasVerifiedEmail();
   }
   ```

### 403 Forbidden on API Endpoints

**Symptoms:**
- Browser console shows 403 errors
- API returns "Unauthorized"
- Vehicle/service data won't load

**Solutions:**
1. **Check route middleware:**
   ```bash
   docker compose exec app php artisan route:list --path=api
   ```

2. **Verify authentication:**
   - User must be logged in
   - Check session/cookie in DevTools
   - Try logging out and back in

3. **Clear route cache:**
   ```bash
   docker compose exec app php artisan route:clear
   ```

## Feature-Specific Troubleshooting

### Google Maps Not Working

**See:** [Google Maps Troubleshooting](../features/google-maps/README.md#troubleshooting)

Common issues:
- API key not set or invalid
- Places API not enabled
- HTTP referrer restrictions blocking requests
- Map not displaying or marker not updating

### Vehicle Selection Broken

**See:** [Vehicle Management Troubleshooting](../features/vehicle-management/README.md#troubleshooting)

Common issues:
- Vehicle types not loading (seeder not run)
- Brands dropdown empty (no active brands)
- Models not showing (pivot table not populated)
- 403 errors on vehicle API endpoints

### Email Templates Not Rendering

**See:** [Email System Troubleshooting](../features/email-system/troubleshooting.md)

Common issues:
- Undefined variable in template
- SMTP authentication fails
- Gmail App Password required
- Preview button disabled (known Livewire bug)

### Settings Not Updating

**See:** [Settings System Troubleshooting](../features/settings-system/README.md#troubleshooting)

Common issues:
- ArgumentCountError in Filament page (Livewire constructor injection)
- Settings not saving (cache not cleared)
- Form validation fails (schema mismatch)

## Cache & OPcache Issues

### Blade Template Changes Not Showing

**Symptoms:**
- Manually edited Blade templates don't update in browser
- `php artisan view:clear` doesn't fix the issue
- Old HTML/CSS still renders despite file changes

**Root Cause:**
PHP OPcache caches compiled bytecode in memory. Even when Laravel recompiles Blade templates (storage/framework/views/), PHP-FPM serves old bytecode from OPcache memory.

**Cache Layers:**
```
1. Application Cache (database) ← php artisan cache:clear
2. Compiled Blade Views (storage/) ← php artisan view:clear
3. PHP OPcache (bytecode memory) ← Container restart OR dev config
```

**Solution (Local Development - v0.3.1+):**

This project includes **opcache-dev.ini** for local development:

```bash
# Verify dev config is active
docker compose exec app php -i | grep -A 5 opcache.validate_timestamps
# Should show: opcache.validate_timestamps => On => On
```

**If dev config is active:** Code changes apply immediately (no restart needed!)

**If dev config is NOT active (production-like environment):**
```bash
# Restart containers to clear OPcache memory
docker compose restart app horizon queue scheduler

# Then clear Laravel caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear
```

### Cache Clearing Cheat Sheet

| Command | Clears | When to Use | OPcache? |
|---------|--------|-------------|----------|
| `php artisan view:clear` | Compiled Blade templates | After editing Blade files | ❌ No |
| `php artisan cache:clear` | Application cache | After data structure changes | ❌ No |
| `php artisan config:clear` | Config cache | After `.env` changes | ❌ No |
| `php artisan route:clear` | Route cache | After modifying routes | ❌ No |
| `php artisan optimize:clear` | All Laravel caches (nuclear option) | When unsure what to clear | ❌ No |
| `docker compose restart app` | **PHP-FPM OPcache memory** | **Code changes not showing** | ✅ Yes |

**Nuclear Option (clears everything):**
```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear
docker compose restart app horizon queue scheduler
```

### Local vs Production OPcache Configuration

**Local Development (v0.3.1+):**
- File: `docker/php/opcache-dev.ini`
- Setting: `opcache.validate_timestamps=On`
- Behavior: PHP checks file changes on every request
- Result: Code changes apply immediately ✅
- Performance: Slightly slower (acceptable for dev)

**Production:**
- File: Default PHP OPcache settings
- Setting: `opcache.validate_timestamps=Off`
- Behavior: PHP never checks files (uses cached bytecode)
- Result: Maximum performance ✅
- Deployment: Container restart required after code deploy

**How It Works:**
```dockerfile
# Dockerfile uses build argument
ARG OPCACHE_MODE=production  # Default

# docker-compose.yml (local dev only)
build:
  args:
    OPCACHE_MODE: dev  # ← Uses opcache-dev.ini

# Production CI/CD
# (no ARG passed) → Uses default production settings
```

**Files:**
- `docker/php/opcache-dev.ini` - Dev config (validate_timestamps=On)
- `Dockerfile` - Conditional config copy
- `docker-compose.yml` - Passes OPCACHE_MODE=dev for local

**See:** [CLAUDE.md - OPcache Troubleshooting](../../CLAUDE.md#opcache--code-changes-not-applying)

## Filament Form Issues

### FileUpload Type Errors

**Symptoms:**
- Admin panel crashes with TypeError: `Filament\Forms\Components\FileUpload::imagePreviewHeight(): Argument #1 ($height) must be of type Closure|string|null, int given`
- Form fails to load with 500 Internal Server Error
- Error appears when visiting Filament admin pages with FileUpload components

**Root Cause:**

Filament v4.2+ requires **string values with CSS units** for visual properties like `imagePreviewHeight()` and `imagePreviewWidth()`. PHP's strict typing (`declare(strict_types=1)`) prevents automatic int-to-string coercion.

This is an API design decision:
- **Numeric properties** (file size, max length) accept `int`: `->maxSize(5120)`
- **CSS properties** (heights, widths) require `string`: `->imagePreviewHeight('200px')`

**Common Mistakes:**

```php
// ❌ WRONG - Integer causes TypeError
FileUpload::make('background_image')
    ->imagePreviewHeight(200)
    ->imagePreviewWidth(300)

// ✅ CORRECT - String with CSS units
FileUpload::make('background_image')
    ->imagePreviewHeight('200px')
    ->imagePreviewWidth('300px')
```

**Solution:**

1. **Fix the type error:**
   ```php
   // Change integer to string with units
   ->imagePreviewHeight(200)      // ❌ TypeError
   ->imagePreviewHeight('200px')  // ✅ Fixed
   ```

2. **Restart containers to clear OPcache:**
   ```bash
   docker compose restart app nginx
   ```

3. **Verify the fix:**
   ```bash
   # Check Laravel logs for errors
   docker compose exec app tail storage/logs/laravel.log

   # Visit admin page to confirm it loads
   curl -k https://registro.local:8444/admin/maintenance-settings
   ```

**Prevention:**

- Always check Filament v4 API documentation for parameter types
- Use string literals for all CSS-related properties (heights, widths, colors)
- Test admin pages immediately after adding new form fields
- Enable PHPStan static analysis to catch type mismatches before runtime:
  ```bash
  ./vendor/bin/phpstan analyse app/Filament/
  ```

**Related Files:**
- `app/Filament/Pages/MaintenanceSettings.php:245` - Correct implementation example
- Filament Docs: https://filamentphp.com/docs/4.x/forms/fields/file-upload#customizing-the-image-preview-height

**See Also:**
- [OPcache Issues](#cache--opcache-issues) - If changes don't apply after fix
- [Filament Optimization](#filament-optimization) - Form performance tips

## Performance Issues

### Slow Page Load

**Solutions:**
1. **Enable caching:**
   ```bash
   docker compose exec app php artisan optimize
   docker compose exec app php artisan config:cache
   docker compose exec app php artisan route:cache
   docker compose exec app php artisan view:cache
   ```

2. **Check query performance:**
   - Enable Query Log in AppServiceProvider
   - Use Laravel Debugbar (development only)
   - Check for N+1 queries

3. **Optimize assets:**
   ```bash
   cd app && npm run build  # Minify CSS/JS
   ```

### High Memory Usage

**Solutions:**
1. **Increase PHP memory limit:**
   ```php
   // docker/php/php.ini
   memory_limit = 256M
   ```

2. **Restart containers:**
   ```bash
   docker compose restart app queue
   ```

3. **Check for memory leaks in queue jobs:**
   ```bash
   docker compose logs -f queue | grep "memory"
   ```

## Getting Help

If you're still stuck after trying these solutions:

1. **Check Laravel logs:**
   ```bash
   docker compose exec app tail -f storage/logs/laravel.log
   ```

2. **Check Docker logs:**
   ```bash
   docker compose logs -f
   ```

3. **Enable debug mode (development only):**
   ```bash
   # .env
   APP_DEBUG=true
   LOG_LEVEL=debug
   ```

4. **Search documentation:**
   - [Feature Documentation](../features/)
   - [Architecture Decisions](../decisions/)
   - [Project Map](../project_map.md)

## See Also

- [Quick Start Guide](./quick-start.md) - Initial setup
- [Commands Reference](./commands.md) - All available commands
- [Docker Guide](./docker.md) - Docker-specific issues
- [Production Build](./production-build.md) - Build troubleshooting
- [Email System Troubleshooting](../features/email-system/troubleshooting.md)
- [Google Maps Troubleshooting](../features/google-maps/README.md#troubleshooting)
- [Vehicle Management Troubleshooting](../features/vehicle-management/README.md#troubleshooting)
