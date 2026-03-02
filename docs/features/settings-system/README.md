# Settings System

Centralized configuration management via Filament admin panel.

## Overview

- **SettingsManager** singleton service
- Settings stored in database (`settings` table)
- 4 setting groups: booking, map, contact, marketing
- Filament page: `/admin/system-settings`

## Setting Groups

**1. Booking** (`booking` group):
```php
business_hours_start, business_hours_end, slot_interval_minutes,
advance_booking_hours, cancellation_hours, max_service_duration_minutes
```

**2. Map** (`map` group):
```php
default_latitude, default_longitude, default_zoom, country_code,
map_id, debug_panel_enabled
```

**3. Contact** (`contact` group):
```php
email, phone, address_line, city, postal_code
```

**4. Marketing** (`marketing` group):
```php
hero_title, hero_subtitle, services_heading, features_heading, cta_heading, ...
```

## Usage in Code

### Accessing Settings

**Via Dependency Injection (Controllers, Services):**
```php
use App\Support\Settings\SettingsManager;

class BookingController extends Controller
{
    public function __construct(protected SettingsManager $settings)
    {
    }

    public function index()
    {
        // Get all booking configuration
        $bookingConfig = $this->settings->bookingConfiguration();
        // Returns: ['business_hours_start' => '09:00', 'business_hours_end' => '18:00', ...]

        // Get specific helper methods
        $businessHours = $this->settings->bookingBusinessHours();
        // Returns: ['start' => '09:00', 'end' => '18:00']

        $advanceHours = $this->settings->advanceBookingHours();
        // Returns: 24 (integer)

        // Get map configuration
        $mapConfig = $this->settings->mapConfiguration();
        // Returns: ['default_latitude' => 52.2297, 'default_longitude' => 21.0122, ...]

        // Get contact information
        $contact = $this->settings->contactInformation();
        // Returns: ['email' => 'contact@example.com', 'phone' => '+48123456789', ...]

        // Get marketing content
        $marketing = $this->settings->marketingContent();
        // Returns: ['hero_title' => '...', 'hero_subtitle' => '...', ...]

        return view('booking.index', compact('bookingConfig', 'mapConfig', 'contact'));
    }
}
```

**Via app() Helper (Blade Views, One-Off Access):**
```php
// In a controller method
$settings = app(SettingsManager::class);
$email = $settings->get('contact.email', 'default@example.com');

// Get nested value with dot notation
$latitude = $settings->get('map.default_latitude', 52.2297);

// Get entire group
$bookingSettings = $settings->getGroup('booking');
```

**In Blade Templates:**
```blade
@php
    $settings = app(\App\Support\Settings\SettingsManager::class);
    $marketing = $settings->marketingContent();
@endphp

<h1>{{ $marketing['hero_title'] }}</h1>
<p>{{ $marketing['hero_subtitle'] }}</p>

<footer>
    <p>Contact us: {{ $settings->get('contact.email') }}</p>
    <p>Phone: {{ $settings->get('contact.phone') }}</p>
</footer>
```

### Updating Settings

**Single Value:**
```php
$settings = app(SettingsManager::class);

// Update single setting
$settings->set('booking.advance_booking_hours', 48);

// Setting is immediately saved to database
```

**Multiple Values in a Group:**
```php
$settings = app(SettingsManager::class);

// Update multiple settings at once (more efficient - fewer queries)
$settings->updateGroups([
    'booking' => [
        'advance_booking_hours' => 48,
        'cancellation_hours' => 12,
        'slot_interval_minutes' => 15,
    ],
    'contact' => [
        'email' => 'new-email@example.com',
        'phone' => '+48987654321',
    ],
]);

// All settings updated in single transaction
```

**In Filament SystemSettings Page:**
```php
public function submit(): void
{
    $data = $this->form->getState(); // Get form data

    $settings = app(SettingsManager::class);
    $settings->updateGroups($data); // Update all groups from form

    Notification::make()
        ->title('Settings updated successfully')
        ->success()
        ->send();
}
```

## Helper Methods

**Available Methods on SettingsManager:**

```php
$settings = app(SettingsManager::class);

// Get all settings (all groups)
$all = $settings->all();
// Returns: ['booking' => [...], 'map' => [...], 'contact' => [...], 'marketing' => [...]]

// Get entire group
$bookingSettings = $settings->getGroup('booking');
// Returns: ['business_hours_start' => '09:00', ...]

// Get single value with default fallback
$value = $settings->get('booking.advance_booking_hours', 24);
// Returns: 48 (or 24 if not set)

// Set single value
$settings->set('booking.advance_booking_hours', 36);

// Update multiple groups
$settings->updateGroups([
    'booking' => ['advance_booking_hours' => 48],
    'contact' => ['email' => 'new@example.com'],
]);

// Specialized helper methods
$settings->bookingConfiguration()    // Returns all booking settings
$settings->bookingBusinessHours()    // Returns ['start' => '09:00', 'end' => '18:00']
$settings->advanceBookingHours()     // Returns integer (hours)
$settings->cancellationHours()       // Returns integer (hours)
$settings->slotIntervalMinutes()     // Returns integer (minutes)
$settings->mapConfiguration()        // Returns all map settings
$settings->contactInformation()      // Returns all contact settings
$settings->marketingContent()        // Returns all marketing settings
```

## Important: Livewire Component Constructor Injection

### ⚠️ CRITICAL GOTCHA

Filament Pages are Livewire components and **CANNOT use constructor dependency injection**. This is a common mistake that causes `ArgumentCountError`.

**❌ WRONG (will cause ArgumentCountError):**
```php
namespace App\Filament\Pages;

use App\Support\Settings\SettingsManager;
use Filament\Pages\Page;

class SystemSettings extends Page
{
    // ❌ THIS WILL FAIL!
    public function __construct(protected SettingsManager $settings)
    {
        parent::__construct();
    }

    public function mount(): void
    {
        // $this->settings is not available here - constructor never called with arguments
        $this->form->fill($this->settings->all()); // ❌ Error!
    }
}
```

**Error Message:**
```
ArgumentCountError: Too few arguments to function App\Filament\Pages\SystemSettings::__construct(),
0 passed and exactly 1 expected
```

**✅ CORRECT (use app() helper):**
```php
namespace App\Filament\Pages;

use App\Support\Settings\SettingsManager;
use Filament\Pages\Page;

class SystemSettings extends Page
{
    // ✅ No constructor dependency injection

    public function mount(): void
    {
        // Use app() helper to resolve service
        $settings = app(SettingsManager::class);
        $this->form->fill($settings->all());
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        // Use app() helper again (services shouldn't be stored in properties)
        $settings = app(SettingsManager::class);
        $settings->updateGroups($data);

        Notification::make()
            ->title('Settings updated successfully')
            ->success()
            ->send();
    }
}
```

**Why Does This Happen?**

1. **Livewire Instantiation:** Livewire instantiates components via `new ComponentClass()` without any constructor arguments.

2. **Serialization Issues:** Livewire components are serialized across HTTP requests. Services like `SettingsManager` (singletons with database connections) cannot be serialized.

3. **Hydration Cycle:** Even if you inject into `mount()` parameters, storing services in properties causes hydration failures:
   ```php
   // ❌ Don't do this either
   protected SettingsManager $settings;

   public function mount(SettingsManager $settings): void
   {
       $this->settings = $settings; // ❌ Won't survive HTTP request cycle
   }
   ```

**Recommended Pattern:**
- Never use constructor injection in Livewire/Filament components
- Use `app(ServiceClass::class)` or `resolve(ServiceClass::class)` in lifecycle methods
- Don't store service instances in component properties
- Resolve services fresh in each method that needs them

**Exception - Method Injection:**
You CAN inject into Livewire action methods (but still don't store in properties):
```php
public function submit(SettingsManager $settings): void
{
    // ✅ This works - method injection
    $data = $this->form->getState();
    $settings->updateGroups($data);
}
```

However, `app()` helper is more explicit and consistent across all methods.

## Database Schema

**Table:** `settings`

```sql
CREATE TABLE settings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `group` VARCHAR(255) NOT NULL,
    `key` VARCHAR(255) NOT NULL,
    value JSON NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX settings_group_index (`group`),
    INDEX settings_key_index (`key`),
    UNIQUE settings_group_key_unique (`group`, `key`)
);
```

**Fields:**
- `id` - Primary key
- `group` - Setting category (booking, map, contact, marketing)
- `key` - Setting name within group (e.g., business_hours_start)
- `value` - JSON-encoded value (supports strings, integers, booleans, arrays)
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- Indexed on `group` for efficient group retrieval
- Indexed on `key` for lookup
- **Unique constraint** on (`group`, `key`) prevents duplicates

**Example Rows:**
```sql
INSERT INTO settings (`group`, `key`, value) VALUES
('booking', 'business_hours_start', '"09:00"'),
('booking', 'advance_booking_hours', '24'),
('map', 'default_latitude', '52.2297'),
('contact', 'email', '"contact@example.com"'),
('marketing', 'hero_title', '"Professional Car Detailing Services"');
```

**Note:** JSON column allows storing complex data types:
- Strings: `"value"` (quoted)
- Numbers: `42` (unquoted)
- Booleans: `true` / `false`
- Arrays: `["item1", "item2"]`
- Objects: `{"key": "value"}`

## Seeding

### Development

```bash
# Seed settings manually
docker compose exec app php artisan db:seed --class=SettingSeeder
```

**Important:** Run this seeder **after every `migrate:fresh --seed`** because it's not included in DatabaseSeeder.

### Migration Includes Seeder Call

The settings migration automatically runs the seeder:

```php
// database/migrations/*_create_settings_table.php

use Illuminate\Support\Facades\Artisan;

public function up(): void
{
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('group');
        $table->string('key');
        $table->json('value');
        $table->timestamps();

        $table->index('group');
        $table->index('key');
        $table->unique(['group', 'key']);
    });

    // Automatically seed default settings after table creation
    Artisan::call('db:seed', ['--class' => 'SettingSeeder']);
}
```

**Why?** Ensures default settings always exist after migrations run.

**Caveat:** If seeder fails, migration rollback may not clean up seeded data. Use `migrate:fresh` for clean slate.

### Seeder Structure

**File:** `database/seeders/SettingSeeder.php`

```php
namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Booking Configuration
            ['group' => 'booking', 'key' => 'business_hours_start', 'value' => '09:00'],
            ['group' => 'booking', 'key' => 'business_hours_end', 'value' => '18:00'],
            ['group' => 'booking', 'key' => 'slot_interval_minutes', 'value' => 30],
            ['group' => 'booking', 'key' => 'advance_booking_hours', 'value' => 24],
            ['group' => 'booking', 'key' => 'cancellation_hours', 'value' => 24],
            ['group' => 'booking', 'key' => 'max_service_duration_minutes', 'value' => 480],

            // Map Configuration
            ['group' => 'map', 'key' => 'default_latitude', 'value' => 52.2297],
            ['group' => 'map', 'key' => 'default_longitude', 'value' => 21.0122],
            ['group' => 'map', 'key' => 'default_zoom', 'value' => 13],
            ['group' => 'map', 'key' => 'country_code', 'value' => 'pl'],
            ['group' => 'map', 'key' => 'map_id', 'value' => null],
            ['group' => 'map', 'key' => 'debug_panel_enabled', 'value' => false],

            // Contact Information
            ['group' => 'contact', 'key' => 'email', 'value' => 'contact@example.com'],
            ['group' => 'contact', 'key' => 'phone', 'value' => '+48123456789'],
            ['group' => 'contact', 'key' => 'address_line', 'value' => 'ul. Marszałkowska 1'],
            ['group' => 'contact', 'key' => 'city', 'value' => 'Warszawa'],
            ['group' => 'contact', 'key' => 'postal_code', 'value' => '00-001'],

            // Marketing Content
            ['group' => 'marketing', 'key' => 'hero_title', 'value' => 'Professional Car Detailing'],
            ['group' => 'marketing', 'key' => 'hero_subtitle', 'value' => 'We come to you'],
            // ... more marketing settings
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }
}
```

**updateOrCreate Logic:**
- If setting exists (group + key match) → Update value
- If setting doesn't exist → Create new row
- Prevents duplicate entries

## Files

**Core Service:**
- `app/Support/Settings/SettingsManager.php` - Singleton service providing settings API

**Model:**
- `app/Models/Setting.php` - Eloquent model for database persistence
  - Casts: `value` to array (JSON decoding)
  - Fillable: `group`, `key`, `value`

**Filament Page:**
- `app/Filament/Pages/SystemSettings.php` - Custom Filament page with forms
  - URL: `/admin/system-settings`
  - 4 form tabs: Booking, Map, Contact, Marketing
  - Permission: `manage settings`

**Blade View:**
- `resources/views/filament/pages/system-settings.blade.php` - Filament page view

**Migration:**
- `database/migrations/2025_11_01_000000_create_settings_table.php` - Creates table + runs seeder

**Seeder:**
- `database/seeders/SettingSeeder.php` - Seeds default settings

**Service Provider:**
- `app/Providers/AppServiceProvider.php` - Registers SettingsManager as singleton:
  ```php
  $this->app->singleton(SettingsManager::class, function ($app) {
      return new SettingsManager();
  });
  ```

## Integration Points

Settings are used throughout the application:

### BookingController
Passes configuration to booking wizard:
```php
public function create()
{
    $bookingConfig = $this->settings->bookingConfiguration();
    $mapConfig = $this->settings->mapConfiguration();
    $marketingContent = $this->settings->marketingContent();

    return view('booking.create', compact('bookingConfig', 'mapConfig', 'marketingContent'));
}
```

### HomeController
Passes marketing content to homepage:
```php
public function index()
{
    $marketing = $this->settings->marketingContent();
    return view('home', compact('marketing'));
}
```

### AppointmentService
Uses booking configuration for slot generation:
```php
public function getAvailableTimeSlots()
{
    $slotInterval = $this->settings->slotIntervalMinutes(); // e.g., 30
    $businessHours = $this->settings->bookingBusinessHours(); // ['start' => '09:00', 'end' => '18:00']

    // Generate slots based on settings
}
```

### Blade Templates
Access settings directly in views:
- `resources/views/home.blade.php` - Uses marketing content
- `resources/views/booking/create.blade.php` - Uses booking config, map config, marketing content
- `resources/views/layouts/app.blade.php` - Uses contact information in footer

## Admin Panel Access

**URL:** `/admin/system-settings`

**Navigation:** Settings → System Settings

**Permission:** `manage settings` (requires Spatie permission)

**Icon:** `heroicon-o-cog-8-tooth`

**Form Structure:**

**Tab 1: Booking Configuration**
- Business Hours Start (time)
- Business Hours End (time)
- Slot Interval Minutes (number)
- Advance Booking Hours (number)
- Cancellation Hours (number)
- Max Service Duration Minutes (number)

**Tab 2: Map Configuration**
- Default Latitude (number)
- Default Longitude (number)
- Default Zoom (number)
- Country Code (text)
- Map ID (text, nullable)
- Debug Panel Enabled (toggle)

**Tab 3: Contact Information**
- Email (email)
- Phone (text)
- Address Line (text)
- City (text)
- Postal Code (text)

**Tab 4: Marketing Content**
- Hero Title (text)
- Hero Subtitle (text)
- Services Heading (text)
- Services Subheading (text)
- Features Heading (text)
- Features Subheading (text)
- Features (repeater: 3 items with title + description)
- CTA Heading (text)
- CTA Subheading (text)
- Important Info Heading (text)
- Important Info Points (repeater: multiple text items)

**Actions:**
- Save (updates all settings via `updateGroups()`)

## Troubleshooting

### Problem: ArgumentCountError in SystemSettings

**Error:**
```
ArgumentCountError: Too few arguments to function App\Filament\Pages\SystemSettings::__construct(),
0 passed and exactly 1 expected
```

**Cause:** Constructor dependency injection in Livewire component

**Solution:**
```php
// ❌ Remove this
public function __construct(protected SettingsManager $settings) {}

// ✅ Use this instead
public function mount(): void
{
    $settings = app(SettingsManager::class);
    $this->form->fill($settings->all());
}
```

See **"Important: Livewire Component Constructor Injection"** section above.

---

### Problem: Settings Not Updating

**Symptoms:**
- Form saves without error
- Changes don't reflect in application
- Old values still returned by `$settings->get()`

**Cause:** Cached configuration

**Solution:**
```bash
# Clear all caches
docker compose exec app php artisan optimize:clear

# Or clear specific caches
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan view:clear
```

**Why?** Laravel may cache config values. Always clear cache after changing settings.

**Prevention:** In development, disable config caching:
```bash
# Don't run this in development:
php artisan config:cache
```

---

### Problem: Form Validation Fails

**Symptoms:**
- Save button doesn't work
- No validation errors shown
- Browser console shows validation error

**Cause:** Required fields missing in form schema or data doesn't match validation rules

**Diagnosis:**
```php
// Check form schema in SystemSettings.php
public function form(Form $form): Form
{
    return $form->schema([
        Tabs::make('Settings')->tabs([
            Tab::make('Booking')->schema([
                TextInput::make('booking.business_hours_start')
                    ->required() // ← This field MUST be filled
                    ->label('Business Hours Start'),
                // ...
            ]),
        ]),
    ]);
}
```

**Solution:**
1. Ensure all `required()` fields have values in seeder
2. Check field names match setting keys exactly (e.g., `booking.business_hours_start`)
3. Verify SettingsManager default values match form field names

**Check Default Values:**
```php
// In SettingsManager.php
private function getDefaults(): array
{
    return [
        'booking' => [
            'business_hours_start' => '09:00', // Must match form field
            // ...
        ],
    ];
}
```

---

### Problem: Settings Lost After migrate:fresh

**Symptoms:**
- After `php artisan migrate:fresh`, settings table is empty
- Application shows default values or errors

**Cause:** `migrate:fresh` drops all tables, including seeded data

**Solution:**
```bash
# Always run seeder after migrate:fresh
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class=SettingSeeder
```

**Why?** SettingSeeder is NOT included in DatabaseSeeder, so `--seed` flag doesn't run it. Migration includes seeder call, so running migration alone should work.

**Better Approach:**
Add SettingSeeder to DatabaseSeeder:
```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    $this->call([
        RolePermissionSeeder::class,
        VehicleTypeSeeder::class,
        ServiceAvailabilitySeeder::class,
        EmailTemplateSeeder::class,
        SettingSeeder::class, // ← Add this
    ]);
}
```

## See Also

- [Booking System](../booking-system/README.md) - Uses booking configuration
- [Google Maps Integration](../google-maps/README.md) - Uses map configuration
- [Email System](../email-system/README.md) - Uses contact information
- [Architecture Decision Records](../../decisions/) - ADR for singleton pattern
