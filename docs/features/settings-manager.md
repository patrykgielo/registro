# Settings Manager System

**Status:** ✅ Production Ready
**Implemented:** November 2025
**Laravel Version:** 12
**Filament Version:** 3.3+

## Overview

The SettingsManager system provides a centralized, database-backed configuration management solution for the Registro application. Settings are organized into groups and accessible via dot notation, with Redis caching for performance.

## Architecture

### Database Schema

**Table:** `settings`
```sql
CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group` VARCHAR(255) NOT NULL COMMENT 'Settings group (booking, map, contact, marketing, email)',
    `key` VARCHAR(255) NOT NULL COMMENT 'Setting key within the group',
    value JSON NOT NULL COMMENT 'Setting value (can be string, number, array, etc.)',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY unique_group_key (`group`, `key`),
    INDEX idx_group (`group`),
    INDEX idx_key (`key`)
);
```

### Components

1. **Model:** `App\Models\Setting`
   - Fillable: `group`, `key`, `value`
   - Casts: `value` → `array` (JSON)
   - Scopes: `group($group)`, `key($key)`

2. **Service:** `App\Support\Settings\SettingsManager`
   - Singleton registered in `AppServiceProvider`
   - Methods: `get()`, `set()`, `updateGroups()`, `group()`, `all()`
   - Redis caching with 1-hour TTL
   - Cache keys: `settings:{group}:{key}` or `settings:{group}`

3. **Seeder:** `Database\Seeders\SettingSeeder`
   - Seeds 5 groups: booking, map, contact, marketing, email
   - 41 total settings across all groups

4. **Admin Page:** `App\Filament\Pages\SystemSettings`
   - URL: `/admin/system-settings`
   - Permission: `manage settings`
   - 5 tabs: Booking, Map, Contact, Marketing, Email
   - Test email connection button

## Settings Groups

### 1. Booking (6 settings)
```php
'business_hours_start' => '09:00'
'business_hours_end' => '18:00'
'slot_interval_minutes' => 30
'advance_booking_hours' => 24
'cancellation_hours' => 24
'max_service_duration_minutes' => 480
```

### 2. Map (6 settings)
```php
'default_latitude' => 52.2297
'default_longitude' => 21.0122
'default_zoom' => 13
'country_code' => 'pl'
'map_id' => null
'debug_panel_enabled' => false
```

### 3. Contact (5 settings)
```php
'email' => 'contact@example.com'
'phone' => '+48123456789'
'address_line' => 'ul. Marszałkowska 1'
'city' => 'Warszawa'
'postal_code' => '00-001'
```

### 4. Marketing (11 settings)
```php
'hero_title' => 'Profesjonalne Pranie Tapicerki Samochodowej'
'hero_subtitle' => 'Przywróć swojemu samochodowi pierwotny wygląd'
'services_heading' => 'Nasze Usługi'
'services_subheading' => 'Kompleksowa oferta detailingu'
'features_heading' => 'Dlaczego My?'
'features_subheading' => 'Gwarantujemy najwyższą jakość'
'features' => [array of feature objects]
'cta_heading' => 'Umów się już dziś'
'cta_subheading' => 'Skontaktuj się z nami i poznaj naszą ofertę'
'important_info_heading' => 'Ważne Informacje'
'important_info_points' => [array of strings]
```

### 5. Email (13 settings)
```php
'smtp_host' => 'smtp.gmail.com'
'smtp_port' => 587
'smtp_encryption' => 'tls'
'smtp_username' => null
'smtp_password' => null
'from_name' => 'Registro'
'from_address' => 'noreply@registro.local'
'retry_attempts' => 3
'backoff_seconds' => 60
'reminder_24h_enabled' => true
'reminder_2h_enabled' => true
'followup_enabled' => true
'admin_digest_enabled' => true
```

## Usage

### Accessing Settings in Code

```php
use App\Support\Settings\SettingsManager;

// Get SettingsManager instance (singleton)
$settings = app(SettingsManager::class);

// Get single setting by dot notation
$businessStart = $settings->get('booking.business_hours_start', '09:00');
$smtpHost = $settings->get('email.smtp_host');

// Get all settings for a group
$bookingSettings = $settings->group('booking');
// Returns: ['business_hours_start' => '09:00', 'business_hours_end' => '18:00', ...]

// Get all settings (all groups)
$allSettings = $settings->all();
// Returns: ['booking' => [...], 'map' => [...], ...]

// Set single setting
$settings->set('booking.business_hours_start', '10:00');

// Bulk update multiple groups
$settings->updateGroups([
    'booking' => [
        'business_hours_start' => '10:00',
        'business_hours_end' => '19:00',
    ],
    'email' => [
        'smtp_host' => 'smtp.sendgrid.net',
        'smtp_port' => 587,
    ],
]);
```

### Using in Blade Templates

```blade
@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
    $heroTitle = $settings->get('marketing.hero_title');
@endphp

<h1>{{ $heroTitle }}</h1>
```

### Using in Controllers

```php
use App\Support\Settings\SettingsManager;

class BookingController extends Controller
{
    public function __construct(
        private SettingsManager $settings
    ) {}

    public function index()
    {
        $businessHours = [
            'start' => $this->settings->get('booking.business_hours_start'),
            'end' => $this->settings->get('booking.business_hours_end'),
        ];

        return view('booking.index', compact('businessHours'));
    }
}
```

## Runtime Configuration Override

The system automatically overrides Laravel's mail configuration with database settings on application boot.

**AppServiceProvider@boot:**
```php
private function configureMailFromDatabase(): void
{
    $settingsManager = app(SettingsManager::class);
    $emailSettings = $settingsManager->group('email');

    if (!empty($emailSettings['smtp_host'])) {
        config([
            'mail.mailers.smtp.host' => $emailSettings['smtp_host'],
            'mail.mailers.smtp.port' => $emailSettings['smtp_port'],
            // ... etc
        ]);
    }
}
```

**Benefits:**
- No need to modify `.env` file for SMTP changes
- Settings can be updated via admin panel
- Changes take effect immediately (after cache clear)

## Admin Panel Usage

### Accessing Settings Page

1. Navigate to `/admin/system-settings`
2. Or click "System Settings" in the admin sidebar (Settings group)

### Editing Settings

1. Select the appropriate tab (Booking, Map, Contact, Marketing, Email)
2. Modify field values
3. Click "Save Settings" (or press Cmd/Ctrl+S)
4. Settings are saved and cache is cleared automatically

### Testing Email Configuration

1. Go to Email tab
2. Configure SMTP settings
3. Click "Test Email Connection"
4. Check your inbox for test email

## Caching Strategy

### Cache Keys
- Single setting: `settings:{group}:{key}` (e.g., `settings:booking:business_hours_start`)
- Group: `settings:{group}` (e.g., `settings:booking`)

### Cache TTL
- 1 hour (3600 seconds)

### Cache Invalidation
- Automatic on `set()` or `updateGroups()`
- Manual: `php artisan cache:clear`

### Cache Driver
- Uses default Laravel cache driver (Redis recommended)

## Database Commands

### Seed Settings
```bash
docker compose exec app php artisan db:seed --class=SettingSeeder
```

### Query Settings
```sql
-- View all settings
SELECT `group`, `key`, value FROM settings;

-- View specific group
SELECT `key`, value FROM settings WHERE `group` = 'booking';

-- Count settings by group
SELECT COUNT(*) as total, `group` FROM settings GROUP BY `group`;
```

### Manual Updates
```sql
-- Update single setting
UPDATE settings
SET value = '["10:00"]'
WHERE `group` = 'booking' AND `key` = 'business_hours_start';

-- Add new setting
INSERT INTO settings (`group`, `key`, value)
VALUES ('booking', 'new_setting', '["value"]');
```

## Permissions

**Permission:** `manage settings`

**Assigned to:**
- Super Admin role (by default)

**Grant to user:**
```php
$user->givePermissionTo('settings.manage');
```

**Check permission:**
```php
$user->hasPermissionTo('settings.manage');
```

## Testing

### Tinker Examples

```bash
docker compose exec app php artisan tinker
```

```php
// Get SettingsManager
$sm = app(\App\Support\Settings\SettingsManager::class);

// Test get()
$sm->get('booking.business_hours_start');
// Output: "09:00"

// Test set()
$sm->set('booking.business_hours_start', '08:00');
$sm->get('booking.business_hours_start');
// Output: "08:00"

// Test group()
$bookingSettings = $sm->group('booking');
count($bookingSettings);
// Output: 6

// Test all()
$allSettings = $sm->all();
array_keys($allSettings);
// Output: ["booking", "map", "contact", "marketing", "email"]

// Test updateGroups()
$sm->updateGroups([
    'booking' => ['business_hours_start' => '09:00'],
    'email' => ['smtp_host' => 'smtp.gmail.com'],
]);
```

## Troubleshooting

### Settings Not Loading
```bash
# Clear cache
docker compose exec app php artisan optimize:clear

# Verify settings exist
docker compose exec mysql mysql -u registro -ppassword -e "SELECT COUNT(*) FROM settings;" registro
```

### Admin Page Not Accessible
```bash
# Check route exists
docker compose exec app php artisan route:list --path=admin/system-settings

# Verify permission
docker compose exec app php artisan tinker
>>> $user = \App\Models\User::find(1);
>>> $user->hasPermissionTo('settings.manage');
```

### Mail Configuration Not Applied
```bash
# Verify email settings exist
docker compose exec app php artisan tinker
>>> $sm = app(\App\Support\Settings\SettingsManager::class);
>>> $sm->group('email');

# Check if smtp_host is set (required for override)
>>> $sm->get('email.smtp_host');
```

### Cache Issues
```bash
# Clear Redis cache
docker compose exec app php artisan cache:clear

# Flush Redis entirely
docker compose exec app php artisan cache:flush

# Test without cache
docker compose exec app php artisan tinker
>>> Cache::forget('settings:booking:business_hours_start');
>>> app(\App\Support\Settings\SettingsManager::class)->get('booking.business_hours_start');
```

## Future Enhancements

### Planned Features
- ❌ Settings versioning (track changes)
- ❌ Settings import/export (JSON/YAML)
- ❌ Settings validation schemas
- ❌ Settings audit log
- ❌ Multi-language support for marketing content

### Integration Opportunities
- Use settings for dynamic pricing rules
- Configure notification preferences per user
- Store feature flags
- Configure payment gateway settings

## Files Reference

**Model:**
- `/app/Models/Setting.php`

**Service:**
- `/app/Support/Settings/SettingsManager.php`

**Seeder:**
- `/database/seeders/SettingSeeder.php`

**Migration:**
- `/database/migrations/2025_11_09_004042_create_settings_table.php`

**Filament Page:**
- `/app/Filament/Pages/SystemSettings.php`
- `/resources/views/filament/pages/system-settings.blade.php`

**Provider:**
- `/app/Providers/AppServiceProvider.php` (singleton registration + mail config override)

## API Reference

### SettingsManager Methods

```php
/**
 * Get a setting value by dot notation path.
 *
 * @param string $path Dot notation path (group.key)
 * @param mixed $default Default value if setting not found
 * @return mixed
 */
public function get(string $path, mixed $default = null): mixed

/**
 * Set a setting value by dot notation path.
 *
 * @param string $path Dot notation path (group.key)
 * @param mixed $value Value to store
 * @return bool
 */
public function set(string $path, mixed $value): bool

/**
 * Bulk update multiple groups of settings.
 *
 * @param array<string, array<string, mixed>> $groups Associative array of groups
 * @return bool
 */
public function updateGroups(array $groups): bool

/**
 * Get all settings for a specific group.
 *
 * @param string $group Group name
 * @return array<string, mixed>
 */
public function group(string $group): array

/**
 * Get all settings grouped by group.
 *
 * @return array<string, array<string, mixed>>
 */
public function all(): array
```

## Best Practices

1. **Always use dot notation** for getting/setting individual settings
2. **Use group()** when you need multiple settings from the same group
3. **Cache clearing** is automatic on write operations
4. **Permission check** before allowing settings modification
5. **Validate input** in Filament form before saving
6. **Test email** configuration after changing SMTP settings
7. **Backup settings** before major updates (export JSON from database)

## Security Considerations

- SMTP passwords are stored in plain text in database (encrypt if needed)
- Only users with `manage settings` permission can access the page
- Settings are cached in Redis (ensure Redis is properly secured)
- No input sanitization (Filament handles this)
- Audit log not implemented (consider adding for compliance)

---

**Last Updated:** November 2025
**Maintained by:** Registro Development Team
