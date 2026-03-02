# ADR-001: Extended User Profile Fields

**Status:** Accepted
**Date:** 2025-10-18
**Decision Makers:** Development Team
**Tags:** users, profile, data-model, booking-flow

## Context and Problem Statement

The application needs to collect comprehensive contact and address information from users during the booking flow. The original User model only stored a single `name` field, which was insufficient for:

1. Proper personalization (separate first/last names)
2. Contact communication (phone numbers)
3. Service delivery (address information for mobile services)
4. Professional CRM capabilities

The booking system required structured user data to improve service quality and customer management.

## Decision Drivers

- **User Experience**: Booking flow should collect complete contact information
- **Data Quality**: Structured fields prevent inconsistent data entry
- **International Standards**: Phone numbers should follow E.164 format
- **Polish Market**: Support for Polish postal code format (XX-XXX)
- **Privacy**: Address fields should be optional
- **Backward Compatibility**: Existing users must be migrated without data loss
- **CRM Integration**: Filament admin panel needs to manage this data

## Considered Options

### Option 1: Keep single name field, add JSON profile column
- **Pros**: Simple migration, flexible schema
- **Cons**: Hard to query, no validation, poor database indexing

### Option 2: Separate User Profile table (1:1 relationship)
- **Pros**: Clean separation of concerns, easier to extend
- **Cons**: Additional JOIN queries, more complex relationships

### Option 3: Add fields directly to users table (SELECTED)
- **Pros**: Simple queries, direct access, better performance
- **Cons**: Users table grows, but acceptable for profile data

## Decision Outcome

**Chosen option: Option 3 - Add fields directly to users table**

### New Fields Added

#### Personal Data
- `first_name` (string, nullable) - User's first name
- `last_name` (string, nullable) - User's last name

#### Contact Information
- `phone_e164` (string, nullable, max: 20) - Phone in E.164 international format
  - Validation: `/^\+\d{1,3}\d{6,14}$/`
  - Example: `+48501234567`

#### Address Information (all optional)
- `street_name` (string, nullable) - Street name
- `street_number` (string, nullable, max: 20) - Building/apartment number
- `city` (string, nullable) - City name
- `postal_code` (string, nullable, max: 10) - Postal code
  - Validation: `/^\d{2}-\d{3}$/` (Polish format)
  - Example: `00-000`
- `access_notes` (text, nullable) - Additional access information

### Data Migration Strategy

The migration automatically splits existing `name` field data:
```php
$nameParts = explode(' ', $user->name, 2);
'first_name' => $nameParts[0] ?? null,
'last_name' => $nameParts[1] ?? null,
```

The original `name` field is **retained** for backward compatibility, with a Model accessor that returns `first_name + last_name`.

### Validation Rules

**Backend (Laravel):**
- `first_name`: required|string|max:255
- `last_name`: required|string|max:255
- `phone_e164`: required|string|max:20|regex:/^\+\d{1,3}\d{6,14}$/
- `postal_code`: nullable|string|max:10|regex:/^\d{2}-\d{3}$/

**Frontend (Alpine.js):**
- Real-time validation on Step 3 of booking wizard
- E.164 phone format check
- Polish postal code mask (99-999)

## Implementation Details

### Affected Components

1. **Database Layer**
   - Migration: `2025_10_18_122658_extend_users_profile_fields.php`
   - Model: `app/Models/User.php` (added $fillable fields + name accessor)
   - Factory: `database/factories/UserFactory.php` (faker data)
   - Seeder: `database/seeders/DatabaseSeeder.php`

2. **Admin Panel (Filament)**
   - `app/Filament/Resources/UserResource.php`
   - `app/Filament/Resources/CustomerResource.php`
   - `app/Filament/Resources/EmployeeResource.php`
   - Forms reorganized into sections: "Dane osobowe", "Kontakt", "Adres"
   - Tables now display `first_name` and `last_name` columns
   - Polish postal code mask: `99-999`

3. **Booking Flow**
   - View: `resources/views/booking/create.blade.php`
   - Step 3 expanded with structured contact/address form
   - Alpine.js: `resources/js/app.js` (validation logic)
   - Controller: `app/Http/Controllers/AppointmentController.php`
   - User profile automatically updated during booking submission

4. **Frontend Assets**
   - Compiled with Vite
   - Alpine.js x-mask directive for postal code
   - Responsive grid layout (Tailwind CSS)

### Security Considerations

- All fields validated server-side
- SQL injection prevented via Eloquent ORM
- XSS protection via Blade escaping
- Phone/postal code regex prevents malicious input

## Consequences

### Positive

- **Better Data Quality**: Structured fields enforce consistent data entry
- **Improved UX**: Forms guide users with proper placeholders and masks
- **CRM Ready**: Admin panel can now properly manage customer information
- **International Support**: E.164 phone format allows global expansion
- **SEO/Analytics**: Separate first/last names enable better personalization
- **Mobile Services**: Address collection enables future delivery features

### Negative

- **Migration Complexity**: Name splitting may not handle all edge cases (e.g., multiple middle names)
- **Database Size**: Users table grew by 8 columns
- **Legacy Code**: Old code referencing `name` field still works via accessor, but should be updated gradually

### Neutral

- **Breaking Change**: None - backward compatible via name accessor
- **Testing Required**: Existing tests may need updates if they validate user creation

## Compliance and Standards

- **GDPR**: Address fields are optional, users consent during booking
- **E.164**: International phone number standard (ITU-T recommendation)
- **Polish Standards**: Postal code format complies with Poczta Polska

## Implementation Updates

### Version 1.1 - Bidirectional Profile Synchronization (2025-10-18)

**Problem**: Initial implementation always overwrote user profile data with booking form data, even when users had existing complete profiles. Users had to re-enter their data on every booking.

**Solution**: Implemented bidirectional synchronization:

1. **Profile → Form (Auto-fill)**
   - `BookingController::create()` now passes `$user` object to the view
   - Blade template pre-populates Alpine.js `customer` object with existing profile data
   - Fields remain editable (no `disabled` or `readonly` attributes)
   - Users can modify pre-filled data if needed

2. **Form → Profile (Smart Update)**
   - `AppointmentController::store()` only updates **empty** profile fields
   - Existing profile data is preserved and never overwritten
   - Uses `empty()` check for null, empty string, and zero values
   - Optional fields (address) only update if both profile field is empty AND form value is provided

**Implementation Details:**

```php
// BookingController.php - Pass user data
public function create(Service $service) {
    $user = Auth::user();
    return view('booking.create', ['service' => $service, 'user' => $user]);
}

// AppointmentController.php - Smart profile updates
$user = Auth::user();
$profileUpdates = [];
if (empty($user->first_name)) $profileUpdates['first_name'] = $validated['first_name'];
// ... repeat for all fields
if (!empty($profileUpdates)) $user->update($profileUpdates);
```

```blade
{{-- booking/create.blade.php - Pre-fill form --}}
<div x-data="bookingWizard()" x-init="
    service = {{ json_encode([...]) }};
    @auth
    customer.first_name = '{{ $user->first_name ?? '' }}';
    customer.last_name = '{{ $user->last_name ?? '' }}';
    {{-- ... repeat for all fields --}}
    @endauth
">
```

**Benefits:**
- Improved UX: Users don't re-enter data on subsequent bookings
- Data integrity: Existing profile data never accidentally overwritten
- Backward compatible: Works for both empty and filled profiles
- Privacy-conscious: Users can provide different data per booking if needed

**Testing Scenarios:**
1. New user with empty profile → form empty, data saved after first booking
2. Returning user with complete profile → form pre-filled, profile unchanged after booking
3. User with partial profile → empty fields filled, existing fields preserved
4. User modifies pre-filled data → booking uses modified data, profile unchanged

## Future Considerations

1. **Email Verification**: Send confirmation SMS to `phone_e164`
2. **Geolocation**: Integrate address with mapping APIs
3. **Multiple Addresses**: Allow users to save multiple delivery addresses
4. **Profile Completion**: Track profile completeness score
5. **Internationalization**: Support for other postal code formats
6. **Profile Edit Page**: Allow users to explicitly update their saved profile data

## Validation Testing

### Manual Testing Checklist

- [x] Migration runs successfully
- [x] Existing users migrated with split names
- [x] Filament admin panel displays new fields
- [x] Booking flow step 3 collects all data
- [x] Frontend validation works (phone, postal code)
- [x] Backend validation rejects invalid input
- [x] User profile updates on booking submission
- [x] Factory generates valid test data

## References

- E.164 Standard: https://en.wikipedia.org/wiki/E.164
- Laravel Validation: https://laravel.com/docs/validation
- Filament Forms: https://filamentphp.com/docs/forms/fields
- Alpine.js Mask: https://alpinejs.dev/plugins/mask

## Rollback Plan

If issues arise, rollback via:
```bash
php artisan migrate:rollback --step=1
```

This will:
1. Drop all new columns
2. Restore `name` field as the only name storage
3. Previous data remains intact in `name` field

---

**Document Version:** 1.1
**Last Updated:** 2025-10-18
**Next Review:** 2025-11-18
