# Maintenance Mode - User Guide

## Overview

The maintenance mode system provides a professional way to display maintenance pages to users while allowing authorized personnel to access the system. All unauthorized requests are redirected to the home page which displays a branded maintenance template.

## Quick Start

### Enable Maintenance Mode

```bash
# Enable pre-launch mode (complete lockdown, no bypass)
docker compose exec app php artisan maintenance:enable --type=prelaunch

# Enable deployment mode (admins can bypass)
docker compose exec app php artisan maintenance:enable --type=deployment --message="System upgrade in progress"

# Enable scheduled maintenance
docker compose exec app php artisan maintenance:enable --type=scheduled --duration="30 minutes"
```

### Check Status

```bash
docker compose exec app php artisan maintenance:status
```

### Disable Maintenance Mode

```bash
docker compose exec app php artisan maintenance:disable
```

## How It Works

### Request Flow

1. **User visits any URL** (e.g., `/strona/o-nas`, `/admin`, `/api/users`)
2. **CheckMaintenanceMode middleware executes** (in web middleware group, AFTER session/auth)
3. **Middleware checks (in order):**
   - Is this the health endpoint (`/up`)? → **Bypass** (always 200 OK)
   - Is maintenance mode NOT active? → **Bypass** (continue to app)
   - Is this an authentication route (`/login`, `/admin/login`, etc.)? → **Bypass** (allow login process)
   - Is authenticated user admin/super-admin? → **Bypass** (role-based, checks Auth::user())
   - Has valid secret token (query or session)? → **Bypass**
   - Otherwise → **Block** with 503 or redirect to `/`
4. **Response:**
   - **If on `/up`**: Always bypass (200 OK for Docker healthchecks)
   - **If on authentication route**: Allow access (needed for login)
   - **If authenticated admin**: Continue to application (role-based bypass)
   - **If already on `/`**: Return 503 with maintenance template
   - **Otherwise**: Redirect 302 to `/` (which shows maintenance page)

### Bypass Methods

#### 1. Role-Based Bypass (Admins)

**Administrators (super-admin, admin) can ALWAYS bypass maintenance mode, including PRELAUNCH.**

**Implementation:**
- Middleware runs in web group (AFTER session/auth middleware)
- `Auth::user()` is available for role-based bypass checks
- Authentication routes (`/admin/login`, `/login`, etc.) are always accessible
- After login, authenticated admin users bypass maintenance automatically
- Role check uses `MaintenanceService::canBypass()` which verifies super-admin/admin roles

**Example Flow:**
```php
// 1. Admin visits /admin/login during maintenance
// 2. CheckMaintenanceMode checks isAuthenticationRoute() → true
// 3. Login page shows ✅

// 4. Admin submits credentials → authentication succeeds
// 5. Browser redirects to GET /admin
// 6. CheckMaintenanceMode runs (AFTER StartSession middleware)
// 7. Auth::user() returns authenticated admin user
// 8. canUserBypassMaintenance() calls MaintenanceService::canBypass($user)
// 9. Service checks: user has super-admin/admin role → true
// 10. Admin accesses panel ✅

// Non-admin flow:
// 1. Customer visits /login during maintenance
// 2. Login page shows ✅
// 3. Customer submits credentials → authentication succeeds
// 4. Browser redirects to /
// 5. CheckMaintenanceMode runs
// 6. Auth::user() returns customer user
// 7. canUserBypassMaintenance() checks roles → customer NOT admin → false
// 8. Blocked with 503 ❌
```

**Why admins always have access:**
- Monitor deployment progress
- Respond to critical issues
- Manage system configuration
- Disable maintenance mode if needed

**Non-admin users** (customer, staff, guest) are blocked during ALL maintenance types and redirected to home page showing maintenance template.

#### 2. Secret Token Bypass

Generate a secret token for temporary access:

```bash
docker compose exec app php artisan maintenance:enable --type=deployment
# Token generated: registro-abc123xyz...
```

**Usage:**
1. Visit `https://registro.com?maintenance_token=registro-abc123xyz`
2. Token stored in session
3. Subsequent requests bypass maintenance mode
4. Token expires when maintenance mode is disabled

#### 3. Health Endpoint

`/up` always returns 200 OK (Docker healthcheck requirement).

### Maintenance Types

| Type | Duration | Bypass Allowed | Use Case |
|------|----------|----------------|----------|
| **PRELAUNCH** | 1 hour | ✅ Admins only | Before official launch |
| **DEPLOYMENT** | 5 minutes | ✅ Admins + token | Code deployments |
| **SCHEDULED** | 5 minutes | ✅ Admins + token | Planned maintenance |
| **EMERGENCY** | 2 minutes | ✅ Admins + token | Critical fixes |

## Maintenance Templates

### Pre-Launch Template

**File:** `resources/views/errors/maintenance-prelaunch.blade.php`

**Features:**
- Registro branding (logo, colors)
- Launch date: 03.01.2026
- Atmospheric teal background
- Contact information
- Responsive design

**Preview:** Visit `/` when maintenance mode is active.

### Deployment Template

**File:** `resources/views/errors/maintenance-deployment.blade.php`

**Features:**
- Generic maintenance message
- Estimated return time
- Contact information
- Retry-After header

## Testing

### Local Testing

1. **Enable maintenance mode:**
   ```bash
   docker compose exec app php artisan maintenance:enable --type=prelaunch
   ```

2. **Test redirects:**
   ```bash
   # Should redirect to /
   curl -I https://registro.local:8444/strona/o-nas
   curl -I https://registro.local:8444/admin

   # Should show 503
   curl -I https://registro.local:8444/

   # Should bypass (200 OK)
   curl -I https://registro.local:8444/up
   ```

3. **Disable maintenance mode:**
   ```bash
   docker compose exec app php artisan maintenance:disable
   ```

### Production Testing

**Before deploying to production:**

1. Test on staging environment
2. Verify admin bypass works
3. Verify secret token bypass works
4. Test health endpoint remains accessible
5. Verify all routes redirect correctly

## Common Issues

### Issue: Old template showing

**Symptom:** Purple gradient instead of teal Registro branding

**Solution:**
```bash
docker compose exec app php artisan view:clear
docker compose restart app
```

### Issue: Admin panel not accessible

**Symptom:** Admins can't access `/admin`

**Solution:** Check maintenance type. PRELAUNCH blocks everyone. Use DEPLOYMENT instead:
```bash
docker compose exec app php artisan maintenance:disable
docker compose exec app php artisan maintenance:enable --type=deployment
```

### Issue: Routes still accessible

**Symptom:** Pages load normally despite maintenance mode

**Solution:** Clear all caches and restart:
```bash
docker compose exec app php artisan optimize:clear
docker compose restart app horizon scheduler
```

## Configuration

### Customizing Templates

Edit template files:
- `resources/views/errors/maintenance-prelaunch.blade.php`
- `resources/views/errors/maintenance-deployment.blade.php`

After changes:
```bash
docker compose exec app php artisan view:clear
docker compose restart app
```

### Customizing Durations

Edit `app/Enums/MaintenanceType.php`:

```php
public function retryAfter(): int
{
    return match($this) {
        self::PRELAUNCH => 3600,      // 1 hour
        self::DEPLOYMENT => 300,      // 5 minutes (change here)
        self::SCHEDULED => 300,       // 5 minutes
        self::EMERGENCY => 120,       // 2 minutes
    };
}
```

## Architecture

### Middleware Stack

```
Request → StartSession → Auth → CheckMaintenanceMode → Filament → Application
                                 ↑
                                 Web middleware group (append)
```

**Why in web group (AFTER session/auth)?**
- Session is loaded before maintenance check
- `Auth::user()` is available for role-based bypass
- Prevents session loss and redirect loops
- Allows admin login to work correctly during maintenance

### State Storage

Maintenance state stored in **Redis**:
- `maintenance:enabled` - Boolean flag
- `maintenance:type` - Enum value
- `maintenance:config` - JSON configuration
- `maintenance:secret_token` - Bypass token

**Persistence:** Redis data persists across container restarts.

## Best Practices

1. **Always test on staging first**
2. **Use DEPLOYMENT mode for deployments** (not PRELAUNCH)
3. **Communicate maintenance windows to users**
4. **Keep maintenance duration short** (< 5 minutes)
5. **Monitor health endpoint** during maintenance
6. **Clear caches after enabling/disabling**
7. **Never force-push maintenance mode changes** to production

## Related Documentation

- [Maintenance Mode Architecture](./README.md)
- [Maintenance Service API](./service-api.md)
- [Deployment Procedures](../../deployment/runbooks/ci-cd-deployment.md)
