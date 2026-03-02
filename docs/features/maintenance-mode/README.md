# Maintenance Mode System

Professional maintenance mode system for Laravel 12 + Filament application with Redis-based state management, multiple maintenance types, and Docker-aware architecture.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Quick Start](#quick-start)
- [Maintenance Types](#maintenance-types)
- [Request Flow](#request-flow)
- [Usage](#usage)
  - [Filament Admin Panel](#filament-admin-panel)
  - [CLI Commands](#cli-commands)
  - [Programmatic Usage](#programmatic-usage)
- [Bypass Methods](#bypass-methods)
- [Docker Integration](#docker-integration)
- [Files & Components](#files--components)
- [Troubleshooting](#troubleshooting)
- [Related Documentation](#related-documentation)

---

## Overview

This maintenance mode system provides:

- **4 Maintenance Types**: Deployment, Pre-launch, Scheduled, Emergency
- **Multiple Bypass Methods**: Admin override (all types), secret token (non-prelaunch)
- **Redis State Storage**: Survives container restarts
- **Nginx-Level Optimization**: Zero PHP overhead for pre-launch mode
- **Filament Admin UI**: Toggle maintenance with visual status indicators
- **CLI Commands**: `maintenance:enable`, `maintenance:disable`, `maintenance:status`
- **Audit Trail**: All events logged to `maintenance_events` table
- **Professional Views**: Custom error pages with Tailwind CSS

---

## Architecture

### State Storage (Redis)

Maintenance state is stored in Redis (NOT files) with 4 cache keys:

```
maintenance:mode           → MaintenanceType enum value (e.g., "deployment")
maintenance:config         → array (message, estimated_duration, etc.)
maintenance:enabled_at     → ISO8601 timestamp
maintenance:secret_token   → bypass token (except for PRELAUNCH)
```

**Why Redis?**
- Survives container restarts (unlike PHP variables)
- Fast reads (middleware performance)
- Single source of truth across all containers

### Middleware Flow

```
Request → CheckMaintenanceMode middleware
          ↓
          Is Active? → NO → Continue to app
          ↓ YES
          Check bypass:
          1. Secret token (query param or session)
          2. User role (admin, super-admin)
          ↓
          Bypass OK? → YES → Continue to app
          ↓ NO
          Return 503 (maintenance-deployment or maintenance-prelaunch view)
```

---

## Quick Start

### 1. Enable Maintenance (CLI)

```bash
# Deployment mode (admins can bypass)
docker compose exec app php artisan maintenance:enable --type=deployment --message="System update in progress" --duration="15 minutes"

# Pre-launch mode (NO bypass, complete lockdown)
docker compose exec app php artisan maintenance:enable --type=prelaunch --message="Coming soon!" --launch-date="2025-12-01"
```

### 2. Enable Maintenance (Filament Admin)

1. Navigate to **Admin Panel** → **System** → **Maintenance Mode**
2. Fill in configuration form
3. Click **Enable Maintenance**
4. Copy the secret token for authorized users

### 3. Check Status

```bash
docker compose exec app php artisan maintenance:status --history
```

### 4. Disable Maintenance

```bash
docker compose exec app php artisan maintenance:disable
```

---

## Maintenance Types

### 1. DEPLOYMENT (60s retry)
- **Use Case**: Code deployments, updates
- **Bypass**: ✅ Admins + secret token
- **View**: `errors/maintenance-deployment.blade.php`
- **Message**: "System update in progress"

### 2. PRELAUNCH (3600s retry)
- **Use Case**: Before application launch (complete lockdown)
- **Bypass**: ❌ NO bypass (not even admins!)
- **View**: `errors/maintenance-prelaunch.blade.php`
- **Message**: "Coming soon! We're preparing something special"
- **Extra Config**: 10 configurable fields (see Pre-Launch Page Configuration below)

#### Pre-Launch Page Configuration

The PRELAUNCH mode includes a fully configurable landing page with 10 customizable fields available in the Filament admin panel at `/admin/maintenance-settings`:

| Field | Type | Description | Default Source |
|-------|------|-------------|----------------|
| `page_title` | TextInput | HTML `<title>` tag (SEO, browser tab) | Settings: `prelaunch.page_title` |
| `main_heading` | TextInput | Main H1 heading | Settings: `prelaunch.heading` |
| `tagline` | Textarea | Subtitle/tagline text | Settings: `prelaunch.tagline` |
| `launch_date_label` | TextInput | Label for launch date section | Settings: `prelaunch.date_label` |
| `launch_date` | DatePicker | Actual launch date (formatted as d.m.Y) | Required field |
| `description_part1` | Textarea | First description paragraph | Settings: `prelaunch.description_1` |
| `description_part2` | Textarea | Second description paragraph | Settings: `prelaunch.description_2` |
| `contact_heading` | TextInput | Contact section heading | Settings: `prelaunch.contact_heading` |
| `copyright_text` | TextInput | Footer copyright text | Settings: `prelaunch.copyright_text` |
| `html_lang` | Select | HTML lang attribute (pl/en) | Defaults to `pl` |
| `background_image` | FileUpload | Custom background image (max 5MB) | Defaults to `/images/maintenance-background.png` |

**Contact Information** (managed in System Settings → Contact):
- Email: `contact.email`
- Phone: `contact.phone`
- Logo: `contact.logo_path`, `contact.logo_alt`

**Fallback Chain**:
1. **Redis Config** (custom values from admin panel)
2. **Settings Defaults** (configurable in System Settings)
3. **Hardcoded Fallback** (emergency defaults in Blade template)

**Example: Enabling with Custom Config**
```php
$service->enable(
    type: MaintenanceType::PRELAUNCH,
    user: Auth::user(),
    config: [
        'launch_date' => '2026-01-10',
        'page_title' => 'Custom Page Title',
        'main_heading' => 'Niestandardowy Nagłówek!',
        'tagline' => 'Custom tagline text',
        'html_lang' => 'en',
        'background_image' => 'maintenance/backgrounds/custom-bg.jpg', // FileUpload path
    ]
);
```

**Files**:
- Template: `resources/views/errors/maintenance-prelaunch.blade.php`
- Form: `app/Filament/Pages/MaintenanceSettings.php` (Section: "Pre-Launch Page Content")
- Settings Seeder: `database/seeders/SettingSeeder.php::seedPrelaunchSettings()`
- Migration: `database/migrations/2025_12_06_142446_add_prelaunch_settings.php`

**Security & Operations**:
- [FileUpload Security Pattern](../../security/patterns/file-upload-security.md) - Image upload security controls (SVG blocking, MIME validation)
- [Rollback Procedures](../../deployment/known-issues.md#issue-13-pre-launch-configuration-corruption) - Emergency recovery if configuration corrupted
- [Troubleshooting](../../guides/troubleshooting.md#filament-form-issues) - Common FileUpload type errors

### 3. SCHEDULED (300s retry)
- **Use Case**: Planned maintenance windows
- **Bypass**: ✅ Admins + secret token
- **View**: `errors/maintenance-deployment.blade.php`
- **Message**: "Scheduled maintenance in progress"

### 4. EMERGENCY (120s retry)
- **Use Case**: Urgent fixes, security patches
- **Bypass**: ✅ Admins + secret token
- **View**: `errors/maintenance-deployment.blade.php`
- **Message**: "Emergency maintenance"

---

## Usage

### Filament Admin Panel

**Location**: `/admin/system/maintenance-mode`

**Features**:
- Live status indicator (red/green)
- Enable/Update/Disable actions
- Secret token display with copy-to-clipboard
- Regenerate token button
- View Event Log button (links to audit trail)

**Access Control**: Only `super-admin` and `admin` roles

---

### CLI Commands

#### `maintenance:enable`

Enable maintenance mode with configuration:

```bash
# Basic deployment mode
php artisan maintenance:enable --type=deployment

# With custom message and duration
php artisan maintenance:enable \
    --type=deployment \
    --message="Deploying v2.0" \
    --duration="30 minutes"

# Pre-launch mode
php artisan maintenance:enable \
    --type=prelaunch \
    --message="Launching soon!" \
    --launch-date="2025-12-15" \
    --image="https://example.com/coming-soon.jpg"
```

**Options**:
- `--type=` : Maintenance type (`deployment`, `scheduled`, `emergency`, `prelaunch`)
- `--message=` : Custom message for users
- `--duration=` : Estimated duration (deployment/scheduled/emergency only)
- `--launch-date=` : Launch date (prelaunch only)
- `--image=` : Custom image URL (prelaunch only)

#### `maintenance:disable`

Disable maintenance mode:

```bash
# With confirmation prompt
php artisan maintenance:disable

# Force (skip confirmation)
php artisan maintenance:disable --force
```

#### `maintenance:status`

Check current status:

```bash
# Basic status
php artisan maintenance:status

# With event history (last 10)
php artisan maintenance:status --history
```

---

### Programmatic Usage

```php
use App\Enums\MaintenanceType;
use App\Services\MaintenanceService;

$service = app(MaintenanceService::class);

// Enable maintenance
$service->enable(
    type: MaintenanceType::DEPLOYMENT,
    user: Auth::user(), // or null for CLI
    config: [
        'message' => 'Deploying new features',
        'estimated_duration' => '20 minutes',
    ]
);

// Check if active
if ($service->isActive()) {
    $type = $service->getType(); // MaintenanceType enum
    $config = $service->getConfig(); // array
}

// Check if user can bypass
if ($service->canBypass(Auth::user())) {
    // Allow access
}

// Get secret token
$token = $service->getSecretToken(); // "registro-xxxxx..."

// Verify token
if ($service->checkSecretToken($providedToken)) {
    // Valid token
}

// Disable maintenance
$service->disable(user: Auth::user());

// Get full status
$status = $service->getStatus();
/*
[
    'active' => true,
    'type' => 'deployment',
    'type_label' => 'Deployment',
    'can_bypass' => true,
    'retry_after' => 60,
    'enabled_at' => '2025-11-28T12:00:00+01:00',
    'config' => [...],
]
*/
```

---

## Bypass Methods

### Bypass Priority (Checked in Order)

1. **Admin Override** (HIGHEST PRIORITY)
   - Checks: User has `super-admin` or `admin` role
   - Result: Always bypass (applies to ALL maintenance types including PRELAUNCH)
   - Log: "Maintenance bypass granted (admin override)"
   - Why: Admins need access to manage system during maintenance

2. **Maintenance Type Check**
   - PRELAUNCH: Block all non-admins (no secret token bypass)
   - DEPLOYMENT/SCHEDULED/EMERGENCY: Allow secret token bypass

3. **Secret Token** (if applicable)
   - Token provided via query param or session
   - Only works for non-PRELAUNCH modes

### 1. Role-Based Bypass (Admins)

**Administrators (super-admin, admin) can ALWAYS bypass ALL maintenance types, including PRELAUNCH.**

```php
// CheckMaintenanceMode middleware
if ($service->canBypass(Auth::user())) {
    return $next($request); // Admin always allowed
}
```

**Why admins have access:**
- Monitor deployment progress
- Respond to critical issues
- Manage system configuration
- Disable maintenance mode if needed

**Non-admin users** (customer, staff, guest) are blocked during ALL maintenance types.

### 2. Secret Token Bypass

Share the secret token with authorized users to grant bypass access:

```
https://yourdomain.com?maintenance_token=registro-xxxxx...
```

Token is stored in session for subsequent requests.

**NOT applicable to PRELAUNCH mode** - only admins can bypass.

**How to get token**:
- CLI: `php artisan maintenance:status`
- Filament: **Maintenance Mode** page (token displayed after enabling)
- Programmatic: `$service->getSecretToken()`

**Regenerate token**:
```bash
# CLI
php artisan maintenance:enable --type=deployment # generates new token

# Filament
Click "Regenerate Secret Token" button

# Programmatic
$newToken = $service->regenerateSecretToken(user: Auth::user());
```

---

## Docker Integration

### Health Check Endpoint

Laravel 11+ includes `/up` health endpoint by default (`bootstrap/app.php`).

This endpoint bypasses maintenance mode for Docker healthchecks:

```yaml
# docker-compose.yml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost/up"]
  interval: 10s
  timeout: 5s
  retries: 3
```

---

## Files & Components

### Core Files

| File | Purpose |
|------|---------|
| `app/Services/MaintenanceService.php` | Core business logic |
| `app/Enums/MaintenanceType.php` | Enum for 4 maintenance types |
| `app/Models/MaintenanceEvent.php` | Audit trail model |
| `app/Http/Middleware/CheckMaintenanceMode.php` | Middleware for request filtering |
| `database/migrations/*_create_maintenance_events_table.php` | Database schema |

### Filament Admin

| File | Purpose |
|------|---------|
| `app/Filament/Pages/MaintenanceSettings.php` | Admin page for managing maintenance |
| `resources/views/filament/pages/maintenance-settings.blade.php` | Blade view with status card |
| `app/Filament/Resources/MaintenanceEventResource.php` | Audit log resource |
| `app/Filament/Resources/MaintenanceEventResource/Pages/ListMaintenanceEvents.php` | List page |
| `app/Filament/Resources/MaintenanceEventResource/Pages/ViewMaintenanceEvent.php` | View page |

### Views

| File | Purpose |
|------|---------|
| `resources/views/errors/maintenance-deployment.blade.php` | Deployment/scheduled/emergency error page |
| `resources/views/errors/maintenance-prelaunch.blade.php` | Pre-launch Blade template |

### CLI Commands

| File | Purpose |
|------|---------|
| `app/Console/Commands/MaintenanceEnableCommand.php` | `php artisan maintenance:enable` |
| `app/Console/Commands/MaintenanceDisableCommand.php` | `php artisan maintenance:disable` |
| `app/Console/Commands/MaintenanceStatusCommand.php` | `php artisan maintenance:status` |

### Configuration

| File | Purpose |
|------|---------|
| `docker/nginx/app.conf` | Nginx config with pre-launch file check |
| `bootstrap/app.php` | Middleware registration |
| `app/Providers/AppServiceProvider.php` | MaintenanceService singleton registration |

---

## Troubleshooting

### Maintenance mode not activating

**Check Redis connection**:
```bash
docker compose exec redis redis-cli ping
# Should return: PONG
```

**Check cache keys**:
```bash
docker compose exec redis redis-cli
> GET maintenance:mode
> GET maintenance:config
```

**Restart containers** (OPcache):
```bash
docker compose restart app nginx
```

### Can't access admin panel during maintenance

**Admins (super-admin, admin) can ALWAYS access admin panel** during any maintenance mode, including PRELAUNCH.

If admin access is not working, check:

1. **User has correct role**:
```bash
docker compose exec app php artisan tinker
$user = User::where('email', 'admin@registro.com')->first();
$user->roles->pluck('name'); // Should show 'super-admin' or 'admin'
```

2. **Middleware is registered** (bootstrap/app.php):
```php
$middleware->prepend(\App\Http\Middleware\CheckMaintenanceMode::class);
```

3. **Clear OPcache**:
```bash
docker compose restart app horizon queue scheduler
```

### Secret token not working

**Regenerate token**:
```bash
docker compose exec app php artisan maintenance:status # Copy current token
# Or regenerate via Filament UI
```

**Check token in Redis**:
```bash
docker compose exec redis redis-cli GET maintenance:secret_token
```

### Code changes not applying (OPcache)

**Problem:** Changes to Filament Resources or PHP files don't take effect, old code still executes.

**Cause:** PHP OPcache in Docker containers caches bytecode. The `php artisan optimize:clear` command only clears **CLI** OPcache, not **PHP-FPM workers** (web server).

**Docker OPcache settings** (from `Dockerfile` lines 25-32):
```
opcache.validate_timestamps=0  # Disables file change detection
opcache.revalidate_freq=0      # Never revalidates
```

These aggressive settings improve production performance but require container restart for code changes.

**Solution - Restart containers to clear PHP-FPM OPcache**:
```bash
# Restart all PHP containers
docker compose restart app horizon queue scheduler

# Then clear Laravel caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear

# Verify containers restarted (check "STATUS" for recent timestamp)
docker compose ps
```

**When to restart containers:**
- After changing Filament Resources, Pages, or Actions
- After Composer updates (composer.json changes)
- When code changes don't appear in browser
- After modifying .env file
- When seeing "Class not found" errors after adding code

**Note:** This is **expected behavior** in production-optimized Docker setup, not a bug.

---

## Real-World Scenarios

### Scenario 1: Quick Deployment (15 minutes)

**Use Case:** Deploying a critical bug fix with minimal downtime.

```bash
# 1. Enable maintenance mode (admins can still access)
docker compose exec app php artisan maintenance:enable \
    --type=deployment \
    --message="Deploying security patch" \
    --duration="15 minutes"

# Output shows secret token:
# Secret token: registro-abc123...
# Share with team members who need access

# 2. Deploy changes
git pull origin main
docker compose exec app composer install --no-dev
docker compose exec app php artisan migrate --force
docker compose restart app horizon queue scheduler

# 3. Test application
# Visit https://yourdomain.com?maintenance_token=registro-abc123...

# 4. Disable maintenance mode
docker compose exec app php artisan maintenance:disable --force
```

**Admin bypass:** Super-admin and admin users can access without token.

---

### Scenario 2: Pre-launch Mode (Complete Lockdown)

**Use Case:** Application not yet launched, showing "Coming Soon" page to everyone.

```bash
# 1. Enable pre-launch mode (NO bypass for anyone)
docker compose exec app php artisan maintenance:enable \
    --type=prelaunch \
    --message="We're preparing something special for you!" \
    --launch-date="2025-12-01" \
    --image="https://yourdomain.com/images/coming-soon.jpg"

# 2. Verify file trigger created
docker compose exec app ls -la storage/framework/maintenance.mode
# Output: -rw-r--r-- 1 www-data www-data 0 Nov 28 12:00 maintenance.mode

# 3. Test (even as admin)
# Visit https://yourdomain.com → Shows coming soon page
# Visit https://yourdomain.com/admin → Shows coming soon page

# 4. When ready to launch
docker compose exec app php artisan maintenance:disable --force
```

**Important:** Pre-launch mode is the ONLY type that blocks admin access. Use CLI to disable.

---

### Scenario 3: Scheduled Maintenance Window

**Use Case:** Planned database migration during low-traffic hours.

```bash
# 1. Schedule maintenance (use cron or manual)
# Example: Saturday 2 AM - 4 AM

docker compose exec app php artisan maintenance:enable \
    --type=scheduled \
    --message="Scheduled database upgrade in progress" \
    --duration="2 hours"

# 2. Share token with support team
# Token: registro-xyz789...
# URL: https://yourdomain.com?maintenance_token=registro-xyz789...

# 3. Perform maintenance tasks
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=ImportantDataSeeder
docker compose exec mysql mysql -u root -p yourdatabase < backup.sql

# 4. Test thoroughly with token

# 5. Disable when complete
docker compose exec app php artisan maintenance:disable
```

**Retry-after:** Users see "retry after 300 seconds" in browser.

---

### Scenario 4: Emergency Hotfix

**Use Case:** Critical production issue requires immediate fix.

```bash
# 1. Enable emergency mode immediately
docker compose exec app php artisan maintenance:enable \
    --type=emergency \
    --message="We're fixing a critical issue. Back soon!"

# 2. Apply hotfix
git checkout hotfix/critical-bug
docker compose exec app composer install --no-dev
docker compose restart app

# 3. Monitor logs
docker compose logs -f app

# 4. Test fix (admins bypass automatically)
# Visit application as admin

# 5. Disable after verification
docker compose exec app php artisan maintenance:disable
```

**Retry-after:** Users see "retry after 120 seconds" for faster recovery.

---

### Scenario 5: Token Sharing with Remote Team

**Use Case:** Maintenance enabled, but remote developers need access.

```bash
# 1. Check current status and get token
docker compose exec app php artisan maintenance:status

# Output:
# Status: ACTIVE
# Type: deployment
# Secret Token: registro-abc123def456...

# 2. Share token securely (Slack, email, etc.)
# "Access URL: https://yourdomain.com?maintenance_token=registro-abc123def456..."

# 3. Token is stored in session after first visit
# Team members don't need to append token to every URL

# 4. If token compromised, regenerate via Filament
# Admin Panel → Maintenance Mode → "Regenerate Secret Token"
```

---

### Scenario 6: Monitoring Active Maintenance

**Use Case:** Track who enabled/disabled maintenance and when.

```bash
# 1. Check maintenance event history
docker compose exec app php artisan maintenance:status --history

# Output shows last 10 events:
# [2025-11-28 14:30] Admin User enabled DEPLOYMENT mode
# [2025-11-28 14:45] Admin User disabled maintenance

# 2. View full audit log in Filament
# Visit /admin/maintenance-events
# Filter by type, user, date range

# 3. Export events for compliance
# Filament UI → Export to CSV
```

---

## Request Flow

### How Maintenance Mode Works (v0.3.4+)

**Middleware Priority:** `CheckMaintenanceMode` is **prepended globally** (first middleware in stack).

```
HTTP Request
    ↓
CheckMaintenanceMode (FIRST - before auth, before Filament)
    ↓
    ├─ Is /up health endpoint? → ✅ Bypass (200 OK)
    ↓
    ├─ Is maintenance active?
    │   ↓
    │   ├─ Is /admin or /admin/*? → ✅ Bypass (let Filament handle auth) [PR #40]
    │   ├─ Already on / → 503 + maintenance template
    │   ├─ Can bypass (admin/token)? → ✅ Continue to app
    │   └─ Otherwise → 302 Redirect to /
    ↓
Auth Middleware
    ↓
Filament Middleware (for /admin routes)
    ↓
    ├─ User::canAccessPanel() checks maintenance + role
    │   ↓
    │   ├─ Admin/super-admin? → ✅ Access granted
    │   └─ Staff/customer? → ❌ Blocked by Filament
    ↓
Application
```

### Redirect Behavior

**All unauthorized requests redirect to home page (`/`) which displays maintenance template:**

**Note:** `/admin` routes are exempted from middleware blocking (as of PR #40). Filament handles authorization via `User::canAccessPanel()`.

```bash
# Examples:
GET /strona/o-nas → 302 → /
GET /api/users → 302 → /

# Admin routes exempted (Filament handles auth):
GET /admin → Filament auth flow (redirects to /admin/login if not authenticated)
GET /admin/login → Filament auth flow

# Home page shows maintenance template:
GET / → 503 Service Unavailable (with branded template)

# Health check bypasses:
GET /up → 200 OK
```

**Why redirect instead of direct 503?**
- Single source of truth for maintenance template (home page)
- Easier caching and CDN integration
- Better UX (users always see same URL)
- Admins can bypass and access panel normally

---

## Related Documentation

- [Usage Guide](./USAGE.md) - Detailed user guide with examples
- [Project Map](../../project_map.md) - System topology
- [Database Schema](../../architecture/database-schema.md) - Tables and relationships
- [Quick Start Guide](../../guides/quick-start.md) - Setup instructions

---

**Last Updated**: 2025-12-05
**Version**: 1.2.0
**Changelog**:
- v1.2.0 (2025-12-05): Fixed middleware priority (global prepend), redirect logic, template loading
- v1.1.0 (2025-11-28): Added OPcache troubleshooting, scenario examples
- v1.0.0 (2025-11-19): Initial release
