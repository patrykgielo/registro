# Role-Based Access Control (RBAC)

**Status:** ✅ Production Ready
**Last Updated:** December 4, 2025
**Version:** 2.0 (Phase 2 completed)

Complete role-based authorization system for Filament admin panel using Spatie Laravel Permission.

---

## 📋 Overview

The application uses a hierarchical role system with granular permissions to control access to admin panel resources. Staff users have restricted access to only their own data and specific resources.

### Roles Hierarchy

```
super-admin (highest privileges)
  └─ admin (full administrative access)
      └─ staff (limited access to own data)
          └─ customer (no admin panel access)
```

---

## 🎯 Role Definitions

### Super-Admin
**Purpose:** System owner with unrestricted access
**Access:** ALL admin panel features + system-level operations

**Capabilities:**
- Full CRUD on all resources
- User and role management
- System settings configuration
- Email and SMS management
- Direct database access

---

### Admin
**Purpose:** Business administrators and managers
**Access:** All admin panel features except system-level operations

**Capabilities:**
- ✅ User management (create, edit, delete users)
- ✅ Employee scheduling and vacation approval
- ✅ Appointment management (all appointments)
- ✅ Service configuration
- ✅ Vehicle type management
- ✅ CMS (pages, posts, promotions, portfolio)
- ✅ Email logs and events
- ✅ SMS management
- ✅ System settings
- ✅ Maintenance mode

**Restrictions:**
- ❌ Cannot modify super-admin accounts
- ❌ Cannot access system-level permissions

---

### Staff
**Purpose:** Service employees (detailing technicians)
**Access:** LIMITED to appointments and own vacation management

**Capabilities:**
- ✅ **Appointments (Wizyty)** - View ALL appointments
- ✅ **Vacation Periods (Urlopy)** - Manage ONLY their own vacations
  - Create vacation requests (auto-filled employee field)
  - View only own vacations in table
  - Edit only own PENDING vacations
  - Delete only own PENDING vacations
  - Cannot approve vacations (admin-only)

**Restrictions:**
- ❌ Cannot access 19 other admin resources
- ❌ Cannot see System Settings
- ❌ Cannot see Email Logs or Email Events
- ❌ Cannot see approval toggle in vacation form
- ❌ Cannot create vacations for other employees
- ❌ Cannot edit approved vacations
- ❌ Cannot view other staff members' vacations

**Navigation (Visible Sections):**
```
Service Management
  └─ Wizyty (Appointments)

Harmonogramy
  └─ Urlopy (Vacation Periods)
```

---

### Customer
**Purpose:** End users booking appointments
**Access:** NONE to admin panel

**Capabilities:**
- ✅ Public booking wizard
- ✅ View own appointments (frontend)
- ✅ Cancel own appointments (frontend)

**Restrictions:**
- ❌ NO admin panel access
- ❌ Cannot view other customers' data

---

## 🔒 Authorization Implementation

### Phase 1: Resource-Level Authorization (Commit: ad2d9fc)

**Pattern:** Inline `canViewAny()` methods in Filament Resources

**Files Modified:** 21 Filament Resources

**Example - Admin-Only Resource:**
```php
// app/Filament/Resources/UserResource.php
public static function canViewAny(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
}
```

**Example - Staff-Accessible Resource:**
```php
// app/Filament/Resources/AppointmentResource.php
public static function canViewAny(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
}
```

---

### Phase 2: Additional Restrictions (Commit: d557008)

**Pattern:** Page-level authorization + field-level visibility + permission removal

**1. System Settings Page**
```php
// app/Filament/Pages/SystemSettings.php
public static function canAccess(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
}
```
**Why:** Overrides permission-based auth for stricter role control

**2. Email Resources**
- Removed `'communication.view_logs'` and `'communication.view_events'` from Staff role permissions
- Phase 6: EmailEvent/SmsEvent/Suppressions restricted to super-admin only

**3. Approval Toggle (Staff Vacation Form)**
```php
// app/Filament/Resources/StaffVacationPeriodResource.php
Forms\Components\Toggle::make('is_approved')
    ->visible(fn () => auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false)
    ->required(fn () => auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false),
```
**Why:** Staff cannot see or toggle approval status

---

## 🛡️ Security Features

### 1. Record Ownership Checks
```php
public static function canEdit($record): bool
{
    $user = auth()->user();

    if (!$user) {
        return false;
    }

    // Admins can edit any record
    if ($user->hasAnyRole(['admin', 'super-admin'])) {
        return true;
    }

    // Staff can only edit their own pending records
    return $record->user_id === $user->id && !$record->is_approved;
}
```

### 2. Query Scoping (Data Isolation)
```php
public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    // Staff see only their own records
    if (auth()->user()?->hasRole('staff')) {
        return $query->where('user_id', auth()->id());
    }

    // Admins see all records
    return $query;
}
```

### 3. Form Field Auto-Fill + Disable
```php
Forms\Components\Select::make('user_id')
    ->label('Pracownik')
    ->default(function () {
        $user = auth()->user();
        return $user?->hasRole('staff') ? $user->id : null;
    })
    ->disabled(fn () => auth()->user()?->hasRole('staff') ?? false)
    ->dehydrated(true), // CRITICAL: Saves value even when disabled
```

### 4. Null-Safe Operators
```php
// Always use null-safe operators + fallback
auth()->user()?->hasRole('staff') ?? false
```
**Why:** Prevents errors if user not logged in, secure default (deny access)

---

## 📊 Staff Role Permissions (Database)

**Current Permissions** (Phase 6 — module-namespaced format):
```php
[
    'services.view',
    'bookings.view',
    'bookings.create',
    'bookings.edit',
    'staff.manage_availability',
    'staff.view_availability',
]
```

**Removed for security:**
```php
// Staff should not access these
'communication.view_logs',        // Email logs
'communication.view_events',      // Email events
'settings.manage',                // System Settings
```

**Phase 6 migration** automatically renamed permissions:
```
'view services' → 'services.view'
'view appointments' → 'bookings.view'
'create appointments' → 'bookings.create'
'edit appointments' → 'bookings.edit'
'manage availability' → 'staff.manage_availability'
'view availability' → 'staff.view_availability'
```

---

## 🧪 Testing Checklist

### Staff User Tests

**Navigation:**
- [ ] Sidebar shows ONLY 2 sections: "Wizyty" + "Urlopy"
- [ ] All other 19 resources hidden from navigation

**Appointments:**
- [ ] Can view all appointments in table
- [ ] Cannot filter by other staff members

**Vacation Management:**
- [ ] Employee field auto-filled with own name (disabled)
- [ ] Table shows ONLY own vacations
- [ ] Can create new vacation request
- [ ] Can edit own PENDING vacations
- [ ] Cannot edit own APPROVED vacations
- [ ] Cannot see approval toggle in form
- [ ] Cannot delete approved vacations

**Restricted Access:**
- [ ] Cannot see "System Settings" in navigation
- [ ] Cannot access `/admin/system-settings` URL (403)
- [ ] Cannot see "Email Logs" in navigation
- [ ] Cannot see "Email Events" in navigation
- [ ] Cannot access `/admin/users`, `/admin/services`, etc. (403)

### Admin Regression Tests

**Full Access Verification:**
- [ ] All 21+ resources visible in navigation
- [ ] Can access System Settings
- [ ] Can view Email Logs and Events
- [ ] Can see approval toggle in vacation form
- [ ] Can edit ANY vacation (pending or approved)
- [ ] Can create vacations for ANY employee

---

## 🚀 Deployment Guide

### Local Testing

```bash
# Clear caches
docker compose exec app php artisan optimize:clear
docker compose restart app horizon queue scheduler

# Test as staff user
# - Login with staff account
# - Verify navigation shows only 2 sections
# - Try to access restricted URLs (should get 403)

# Test as admin user
# - Verify all resources accessible
# - Verify approval toggle visible
```

### Production Deployment

```bash
# 1. Commit and push changes
git add app/Filament/Pages/SystemSettings.php
git add app/Filament/Resources/StaffVacationPeriodResource.php
git add database/seeders/RolePermissionSeeder.php
git commit -m "fix(admin): staff role access restrictions"
git push origin main

# 2. Wait for GitHub Actions deployment (~6 min)
gh run watch

# 3. CRITICAL: Manually revoke permissions on production
ssh root@72.60.17.138
cd /var/www/registro
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="\$staff = Spatie\\Permission\\Models\\Role::findByName('staff'); \$staff->revokePermissionTo(['communication.view_logs', 'communication.view_events', 'settings.manage']); echo 'Permissions revoked';"

# 4. Clear caches
docker compose -f docker-compose.prod.yml restart app horizon scheduler
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec -T app php artisan filament:optimize-clear
```

**Why Manual Revoke?**
Seeder changes only apply to new role assignments. Existing permissions must be manually revoked.

---

## 🔧 Troubleshooting

### Issue: Staff can still see restricted resources after deployment

**Cause:** Permissions not revoked from database, OPcache serving old code

**Solution:**
```bash
# 1. Verify current permissions
docker compose exec app php artisan tinker --execute="\$staff = Spatie\\Permission\\Models\\Role::findByName('staff'); \$staff->permissions->pluck('name');"

# 2. Revoke incorrect permissions
docker compose exec app php artisan tinker --execute="\$staff = Spatie\\Permission\\Models\\Role::findByName('staff'); \$staff->revokePermissionTo(['communication.view_logs', 'communication.view_events', 'settings.manage']);"

# 3. Clear caches and restart
docker compose restart app horizon scheduler
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear
```

### Issue: Staff sees approval toggle in form

**Cause:** Browser cache or Filament cache

**Solution:**
```bash
# Clear Filament cache
docker compose exec app php artisan filament:optimize-clear

# Hard refresh browser (Ctrl+Shift+R or Cmd+Shift+R)
```

### Issue: Direct URL access bypasses authorization

**Cause:** Missing `canViewAny()` method in resource

**Solution:**
```php
// Add to resource class
public static function canViewAny(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
}
```

---

## 📁 Key Files

### Authorization Logic
- `app/Filament/Resources/StaffVacationPeriodResource.php` - Staff vacation management (Phase 1+2)
- `app/Filament/Resources/AppointmentResource.php` - Appointments (Phase 1)
- `app/Filament/Pages/SystemSettings.php` - System settings (Phase 2)
- 19 other Filament Resources (admin-only, Phase 1)

### Permission Management
- `database/seeders/RolePermissionSeeder.php` - Role and permission seeding
- `app/Models/User.php` - Helper methods (`isStaff()`, `isAdmin()`)

### Configuration
- `config/permission.php` - Spatie Laravel Permission config

---

## 📚 Related Documentation

- **[User Model](../../architecture/user-model.md)** - User model helpers and relationships
- **[Database Schema](../../architecture/database-schema.md)** - Complete database structure
- **[Deployment History](../../deployment/deployment-history.md)** - Deployment timeline and issues

---

## 🔄 Version History

### v2.0 - Phase 2 (December 4, 2025) - Commit: d557008
- Added `canAccess()` to SystemSettings page (role-based override)
- Removed email permissions from Staff role
- Hidden approval toggle from staff in vacation form
- Manual permission revocation on production

### v1.0 - Phase 1 (December 4, 2025) - Commit: ad2d9fc
- 21 Filament Resources updated with `canViewAny()` authorization
- StaffVacationPeriodResource: employee field auto-fill + disabled
- StaffVacationPeriodResource: query scoping (staff see only own records)
- StaffVacationPeriodResource: edit/delete restrictions (pending only)
- AppointmentResource: explicit staff access
- 19 resources: admin-only access

---

**Next Steps:**
- Consider implementing Laravel Policies for more complex authorization logic
- Add audit logging for sensitive actions (vacation approval, user deletion)
- Implement rate limiting for staff actions
