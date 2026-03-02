# Customer Profile & Settings

Complete user profile management system with sidebar navigation and 5 dedicated subpages.

## Overview

The Customer Profile system allows authenticated users to manage their:
- Personal information (name, phone)
- Vehicles (type, brand, model, year)
- Addresses (with Google Maps autocomplete)
- Notification preferences (SMS, email, marketing)
- Account security (password, email, account deletion)

## Architecture

### Design Decision: Subpages vs Tabs

**Problem:** Initial tab-based implementation lost state after form submission - users were redirected to the first tab instead of staying on the current section.

**Solution:** Separate subpages with shared sidebar layout.

**Benefits:**
- Direct URL access to each section
- Proper form redirect handling (stays on current page)
- Browser history support (back/forward navigation)
- Bookmarkable sections
- Better SEO potential

### URL Structure

| Route Name | URL (Polish) | Description |
|------------|--------------|-------------|
| `profile.index` | `/moje-konto` | Redirects to `profile.personal` |
| `profile.personal` | `/moje-konto/dane-osobowe` | Personal information |
| `profile.vehicle` | `/moje-konto/pojazd` | Vehicle management |
| `profile.address` | `/moje-konto/adres` | Address with Google Maps |
| `profile.notifications` | `/moje-konto/powiadomienia` | Notification preferences |
| `profile.security` | `/moje-konto/bezpieczenstwo` | Security settings |

### Key Files

#### Controllers
- `app/Http/Controllers/ProfileController.php` - Main controller with 5 view methods and update handlers
- `app/Http/Controllers/UserVehicleController.php` - CRUD operations for vehicles
- `app/Http/Controllers/UserAddressController.php` - CRUD operations for addresses

#### Services
- `app/Services/ProfileService.php` - Business logic for profile operations
- `app/Services/UserVehicleService.php` - Vehicle management service
- `app/Services/UserAddressService.php` - Address management service

#### Form Requests
- `app/Http/Requests/Profile/UpdatePersonalInfoRequest.php`
- `app/Http/Requests/Profile/StoreVehicleRequest.php`
- `app/Http/Requests/Profile/UpdateVehicleRequest.php`
- `app/Http/Requests/Profile/StoreAddressRequest.php`
- `app/Http/Requests/Profile/UpdateAddressRequest.php`
- `app/Http/Requests/Profile/UpdateNotificationsRequest.php`
- `app/Http/Requests/Profile/ChangePasswordRequest.php`
- `app/Http/Requests/Profile/ChangeEmailRequest.php`
- `app/Http/Requests/Profile/RequestDeletionRequest.php`

#### Views
- `resources/views/profile/layout.blade.php` - Responsive sidebar layout
- `resources/views/profile/pages/personal.blade.php`
- `resources/views/profile/pages/vehicle.blade.php`
- `resources/views/profile/pages/address.blade.php`
- `resources/views/profile/pages/notifications.blade.php`
- `resources/views/profile/pages/security.blade.php`

#### Partials
- `resources/views/profile/partials/personal-form.blade.php`
- `resources/views/profile/partials/vehicle-form.blade.php`
- `resources/views/profile/partials/address-form.blade.php`
- `resources/views/profile/partials/notifications-form.blade.php`
- `resources/views/profile/partials/security-form.blade.php`

#### Icons (SVG Components)
- `resources/views/profile/partials/icons/user.blade.php`
- `resources/views/profile/partials/icons/car.blade.php`
- `resources/views/profile/partials/icons/map-pin.blade.php`
- `resources/views/profile/partials/icons/bell.blade.php`
- `resources/views/profile/partials/icons/shield.blade.php`

## Responsive Design

### Desktop (lg+)
- Vertical sidebar on the left (fixed width)
- Content area on the right
- Active item highlighted with primary color

### Mobile (<lg)
- Horizontal scrollable navigation bar at top
- Full-width content below
- Touch-friendly scroll with hidden scrollbar

## Google Maps Integration

The address section uses Google Maps Places Autocomplete, same implementation as the booking wizard.

### Configuration
```php
// ProfileController::address()
return view('profile.pages.address', [
    'user' => $user,
    'googleMapsApiKey' => config('services.google_maps.api_key'),
    'googleMapsMapId' => $this->settings->get('map.map_id') ?? config('services.google_maps.map_id'),
]);
```

### Address Components Stored
- `formatted_address` - Full address string
- `latitude` / `longitude` - GPS coordinates
- `place_id` - Google Place ID
- `components` - JSON array with parsed address parts

## Form Validation Pattern

### JSON Components Handling

The Google Maps autocomplete sends `components` as a JSON string via JavaScript. Form Requests decode this before validation:

```php
// StoreAddressRequest.php and UpdateAddressRequest.php
protected function prepareForValidation(): void
{
    if ($this->has('components') && is_string($this->components)) {
        $decoded = json_decode($this->components, true);
        $this->merge([
            'components' => is_array($decoded) ? $decoded : null,
        ]);
    }
}
```

## Database Tables

### user_vehicles
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| vehicle_type_id | bigint | Foreign key to vehicle_types |
| car_brand_id | bigint | Foreign key to car_brands |
| car_model_id | bigint | Foreign key to car_models |
| year | smallint | Vehicle year (1990-current) |
| created_at | timestamp | |
| updated_at | timestamp | |

### user_addresses
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| label | varchar | Address label (e.g., "Dom", "Praca") |
| formatted_address | varchar | Full address string |
| latitude | decimal(10,8) | GPS latitude |
| longitude | decimal(11,8) | GPS longitude |
| place_id | varchar | Google Place ID |
| components | json | Parsed address components |
| created_at | timestamp | |
| updated_at | timestamp | |

## Security Features

### Password Change
- Requires current password verification
- Password confirmation required
- Minimum 8 characters

### Email Change
- Two-step verification process
- Sends confirmation link to NEW email
- Token expires after 24 hours
- Must be logged in to confirm

### Account Deletion
- Two-step verification process
- Sends confirmation link to email
- Token expires after 24 hours
- Logs out user after deletion
- Can cancel deletion request before confirmation

## Routes Definition

```php
// routes/web.php
Route::prefix('moje-konto')->name('profile.')->group(function () {
    // Redirect from base to personal page
    Route::get('/', fn () => redirect()->route('profile.personal'))->name('index');

    // Profile pages
    Route::get('/dane-osobowe', [ProfileController::class, 'personal'])->name('personal');
    Route::get('/pojazd', [ProfileController::class, 'vehicle'])->name('vehicle');
    Route::get('/adres', [ProfileController::class, 'address'])->name('address');
    Route::get('/powiadomienia', [ProfileController::class, 'notifications'])->name('notifications');
    Route::get('/bezpieczenstwo', [ProfileController::class, 'security'])->name('security');

    // Update actions...
});
```

## Troubleshooting

### Google Maps Autocomplete Not Working
1. Check that `GOOGLE_MAPS_API_KEY` is set in `.env`
2. Verify API key has Places API enabled
3. Check browser console for JavaScript errors
4. Ensure domain is authorized in Google Cloud Console

### "The components field must be an array" Error
This occurs when `prepareForValidation()` is missing from the Form Request. Ensure both `StoreAddressRequest` and `UpdateAddressRequest` have this method.

### Redirect Goes to Wrong Page After Save
Verify controller redirects use specific route names:
```php
// Correct
return redirect()->route('profile.vehicle');

// Incorrect (old tab-based approach)
return redirect()->route('profile.index', ['tab' => 'vehicle']);
```

## Migration Notes

### From Tab-Based to Subpage Architecture

**Deleted Files:**
- `resources/views/profile/index.blade.php` (old tab-based view)

**Created Files:**
- `resources/views/profile/layout.blade.php`
- `resources/views/profile/pages/*.blade.php` (5 files)
- `resources/views/profile/partials/icons/*.blade.php` (5 files)

**Modified Files:**
- `app/Http/Controllers/ProfileController.php` - Added 5 view methods
- `app/Http/Controllers/UserVehicleController.php` - Changed redirects
- `app/Http/Controllers/UserAddressController.php` - Changed redirects
- `routes/web.php` - Added 5 new GET routes

**Rollback Instructions:**
1. Restore `resources/views/profile/index.blade.php` from git history
2. Remove new page/layout files
3. Revert controller changes (restore tab parameter in redirects)
4. Remove new GET routes from `web.php`
