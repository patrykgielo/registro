# Audit Logging - Quick Start Guide

## TL;DR - What You Need to Know

**Status:** ✅ Backend implemented, ❌ No admin panel

**What works:**
- All user/appointment changes are logged automatically
- Login/logout events are tracked
- Data is stored in `audit_logs` table

**What's missing:**
- No way to VIEW the logs in admin panel
- Need to create Filament resource (2-3 hours work)

**Who should access:**
- Admins only (GDPR compliance requirement)

**When to use:**
- Customer disputes data changes
- Security breach investigation
- GDPR subject access requests
- Compliance audits

---

## 5-Minute Setup (For Developers)

### Step 1: Verify Database Migration

```bash
docker compose exec app php artisan migrate:status | grep audit_logs
```

Expected output:
```
[2025_12_28_234848] CreateAuditLogsTable ........................... Ran
```

If missing, run:
```bash
docker compose exec app php artisan migrate
```

---

### Step 2: Verify Models Are Auditable

Check `app/Models/User.php`:
```php
use Auditable; // ✅ Should be present
```

Check `app/Models/Appointment.php`:
```php
use Auditable; // ✅ Should be present
```

---

### Step 3: Verify Listener is Registered

Check `app/Providers/AppServiceProvider.php`:
```php
Event::subscribe(LogAuthenticationEvents::class); // ✅ Should be present
```

---

### Step 4: Test Audit Logging

```bash
docker compose exec app php artisan tinker
```

```php
// Create test user
$user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User']);

// Update user (triggers audit log)
$user->update(['first_name' => 'Updated']);

// Check audit logs
AuditLog::latest()->take(5)->get(['id', 'event', 'auditable_type', 'created_at']);
```

Expected output:
```
Collection {
  #items: [
    {
      "id": 123,
      "event": "updated",
      "auditable_type": "App\\Models\\User",
      "created_at": "2025-12-28 23:30:45"
    },
    {
      "id": 122,
      "event": "created",
      "auditable_type": "App\\Models\\User",
      "created_at": "2025-12-28 23:30:40"
    },
  ]
}
```

✅ If you see audit logs, backend is working!

---

### Step 5: View Logs (Temporary Method)

**Option A: Tinker (Command Line)**
```bash
docker compose exec app php artisan tinker
```

```php
// View recent logs
AuditLog::latest()->take(10)->get(['id', 'event', 'user_id', 'auditable_type', 'created_at']);

// View logs for specific user
AuditLog::where('user_id', 1)->latest()->take(10)->get();

// View logs for specific model
AuditLog::where('auditable_type', 'App\\Models\\User')
    ->where('auditable_id', 123)
    ->get(['event', 'old_values', 'new_values', 'created_at']);

// View failed login attempts
AuditLog::where('event', 'login_failed')->latest()->take(10)->get();
```

---

**Option B: Database Query**
```bash
docker compose exec mysql mysql -u registro -ppassword registro
```

```sql
-- View recent logs
SELECT id, created_at, user_id, event, auditable_type, auditable_id
FROM audit_logs
ORDER BY created_at DESC
LIMIT 10;

-- View logs for specific event
SELECT * FROM audit_logs WHERE event = 'login_failed' ORDER BY created_at DESC LIMIT 10;

-- Count logs by event type
SELECT event, COUNT(*) as count
FROM audit_logs
GROUP BY event
ORDER BY count DESC;
```

---

## 30-Minute Setup (Create Admin Panel)

### Prerequisites
- PHP 8.2+
- Filament v4.2.3+
- Admin user with 'admin' role

### Quick Implementation

1. **Generate Resource:**
```bash
docker compose exec app php artisan make:filament-resource AuditLog --generate
```

2. **Configure Access Control:**

Edit `app/Filament/Resources/AuditLogResource.php`:
```php
public static function canCreate(): bool
{
    return false; // Audit logs are auto-generated
}

public static function canEdit(Model $record): bool
{
    return false; // Audit logs are immutable
}

public static function canDelete(Model $record): bool
{
    return false; // Never delete audit logs manually
}

public static function canViewAny(): bool
{
    return auth()->user()?->hasRole('admin');
}
```

3. **Configure Navigation:**
```php
protected static ?string $navigationIcon = 'heroicon-o-shield-check';
protected static ?string $navigationLabel = 'Dziennik Audytu';
protected static ?string $navigationGroup = 'Administracja';
```

4. **Test:**
```bash
# Navigate to https://registro.local:8444/admin/audit-logs
# Should see audit logs table
```

**For complete implementation, see:** [Implementation Guide](./implementation-guide.md)

---

## Common Tasks

### View All Changes to a Specific User

```bash
docker compose exec app php artisan tinker
```

```php
$userId = 123;
AuditLog::forModel(User::find($userId))->get(['event', 'old_values', 'new_values', 'created_at']);
```

---

### Export Last 30 Days for GDPR Request

```bash
docker compose exec app php artisan tinker
```

```php
$userId = 123;
$logs = AuditLog::where('user_id', $userId)
    ->where('created_at', '>=', now()->subDays(30))
    ->get(['event', 'auditable_type', 'created_at', 'ip_address']);

file_put_contents('gdpr-export-user-123.json', json_encode($logs, JSON_PRETTY_PRINT));
echo "Exported to gdpr-export-user-123.json\n";
```

---

### Check for Suspicious Failed Logins

```bash
docker compose exec app php artisan tinker
```

```php
// Count failed login attempts in last 24 hours
AuditLog::where('event', 'login_failed')
    ->where('created_at', '>=', now()->subDay())
    ->count();

// View details
AuditLog::where('event', 'login_failed')
    ->where('created_at', '>=', now()->subDay())
    ->get(['id', 'created_at', 'metadata', 'ip_address'])
    ->each(function ($log) {
        echo sprintf(
            "[%s] IP: %s | Email: %s\n",
            $log->created_at,
            $log->ip_address,
            $log->metadata['credentials']['email'] ?? 'unknown'
        );
    });
```

---

### Find All Data Exports (Compliance Tracking)

```bash
docker compose exec app php artisan tinker
```

```php
AuditLog::where('event', 'exported')
    ->latest()
    ->get(['user_id', 'created_at', 'metadata'])
    ->each(function ($log) {
        $user = User::find($log->user_id);
        echo sprintf(
            "[%s] User: %s | Type: %s\n",
            $log->created_at,
            $user->name ?? 'Unknown',
            $log->metadata['export_type'] ?? 'General'
        );
    });
```

---

## Troubleshooting

### Issue: No audit logs being created

**Check 1: Verify trait is used**
```bash
docker compose exec app php artisan tinker
```

```php
// Should return true
in_array('Auditable', class_uses(User::class));
```

**Check 2: Verify events are registered**
```bash
docker compose exec app php artisan event:list | grep Audit
```

Should show:
```
\Illuminate\Auth\Events\Login  →  \App\Listeners\LogAuthenticationEvents@handleLogin
\Illuminate\Auth\Events\Logout →  \App\Listeners\LogAuthenticationEvents@handleLogout
```

**Check 3: Test manually**
```bash
docker compose exec app php artisan tinker
```

```php
$user = User::first();
$user->update(['first_name' => 'Test']);

// Should create audit log
AuditLog::latest()->first();
```

---

### Issue: Too many audit logs (performance concern)

**Solution 1: Limit audited fields**

Edit `app/Models/User.php`:
```php
// Only audit these fields (instead of all fillable)
protected array $auditInclude = [
    'first_name',
    'last_name',
    'email',
    'phone_e164',
];
```

**Solution 2: Exclude internal timestamps**
```php
protected array $auditExclude = [
    'password',
    'remember_token',
    'updated_at', // Don't audit timestamp changes
    'created_at',
];
```

**Solution 3: Schedule cleanup**
```bash
docker compose exec app php artisan make:command CleanupOldAuditLogs
```

```php
// Delete logs older than 1 year
AuditLog::where('created_at', '<', now()->subYear())->delete();
```

---

### Issue: Audit logs growing database size

**Check current size:**
```bash
docker compose exec mysql mysql -u registro -ppassword registro -e "
SELECT
    table_name AS 'Table',
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.TABLES
WHERE table_schema = 'registro'
    AND table_name = 'audit_logs';
"
```

**Solution: Enable automatic cleanup**
```bash
# Create cleanup command
docker compose exec app php artisan make:command CleanupOldAuditLogs

# Schedule in app/Console/Kernel.php
$schedule->command('audit:cleanup')->daily();
```

---

## FAQ

**Q: How long should we keep audit logs?**
A: GDPR recommendation: 6-12 months for operational, 3-7 years for legal compliance. We recommend 1 year.

**Q: Can customers see their own audit logs?**
A: Not in the admin panel. Create a separate customer-facing "/profile/activity" page with simplified view.

**Q: What if an admin deletes audit logs?**
A: Currently possible but not recommended. Future enhancement: make logs immutable or log deletions.

**Q: Do audit logs slow down the application?**
A: Minimal impact (<5ms per request). Logs are written after the response is sent.

**Q: Can we audit logs from external systems (e.g., Google Maps API)?**
A: Not automatically. Use `AuditLog::logEvent()` to manually log external API calls.

**Q: How do we export audit logs for GDPR requests?**
A: Use CSV export in admin panel (once created) or manual export via Tinker (see above).

---

## Next Steps

1. **Immediate (15 min):**
   - Test audit logging with the commands above
   - Verify logs are being created

2. **Short-term (2-3 hours):**
   - Create Filament admin panel (see [Implementation Guide](./implementation-guide.md))
   - Configure filters and export

3. **Medium-term (1 week):**
   - Create customer-facing "My Activity" page
   - Schedule automatic cleanup
   - Add email alerts for suspicious activity

4. **Long-term (1 month):**
   - Dashboard widget for recent activity
   - Advanced analytics (who accessed what)
   - SIEM integration for enterprise security

---

## Related Documentation

- [Complete Audit Logging Guide](./README.md)
- [Implementation Guide](./implementation-guide.md)
- [Practical Use Cases](./use-cases.md)
- [GDPR Compliance Overview](../gdpr-compliance/README.md)
- [Security Best Practices](../../security/README.md)

---

## Need Help?

**For technical questions:**
- Check [Troubleshooting](#troubleshooting) section
- Review [Implementation Guide](./implementation-guide.md)

**For GDPR compliance questions:**
- Review [GDPR Compliance Overview](../gdpr-compliance/README.md)
- Consult legal team

**For security concerns:**
- Review [Security Best Practices](../../security/README.md)
- Run security audit (see [Security Documentation](../../security/README.md))
