# Booking Wizard Step 4 Auto-fill Testing Guide

**Date:** 2025-12-12
**Feature:** Logged-in user profile auto-fill in booking wizard contact step
**Files Changed:**
- `app/Http/Controllers/BookingController.php` (lines 148-177)
- `resources/views/booking-wizard/steps/contact.blade.php` (lines 299-322)

---

## What Was Fixed

### Root Cause

Three critical bugs prevented auto-fill:

1. **Alpine.js overwrote Blade values** - Component initialized with empty strings instead of reading rendered input values
2. **Session not updated** - Controller pre-filled `$bookingData` but didn't persist to session
3. **Empty strings treated as "filled"** - `empty('')` check failed, allowing blank session values to override user data

### Solution

**Backend Fix (BookingController.php):**
- Changed condition from `empty()` to explicit `null` and `''` checks
- Added `session(['booking' => $booking])` to persist pre-filled data
- Ensures session always has user data after first visit to step 4

**Frontend Fix (contact.blade.php):**
- Changed Alpine.js init from `firstName: ''` to `firstName: document.getElementById('first-name')?.value || ''`
- Now reads actual DOM value rendered by Blade before Alpine.js takes control
- Preserves both Blade-rendered values AND session-restored values

---

## Testing Prerequisites

### 1. Create Test User (Already Done)

```bash
# User already created with these credentials:
Email: test@registro.local
Password: password
First Name: Jan
Last Name: Testowy
Phone: +48123456789
```

### 2. Start a Booking Flow

1. Log in as test user
2. Navigate to home page
3. Click "Umów Termin" or select any service
4. Complete steps 1-3:
   - **Step 1:** Select any service (e.g., "Premium Detailing")
   - **Step 2:** Select future date and time slot
   - **Step 3:** Select vehicle type, enter location (use Google Maps autocomplete)

---

## Test Scenarios

### ✅ Test A: First Visit to Step 4 (Fresh Session)

**Steps:**
1. Clear session: Open browser incognito/private window
2. Log in as `test@registro.local` / `password`
3. Start new booking flow (steps 1-3)
4. Navigate to step 4 (Contact Information)

**Expected Result:**
- ✅ First Name field shows: `Jan`
- ✅ Last Name field shows: `Testowy`
- ✅ Email field shows: `test@registro.local` + readonly indicator
- ✅ Phone field shows: `+48123456789`
- ✅ All 4 fields have green checkmarks (validation passes)

**Debug Check:**
```javascript
// Open browser console, check Alpine.js state:
Alpine.store('contactInfoForm') // Should show user data
```

---

### ✅ Test B: Back/Forward Navigation (Session Persistence)

**Steps:**
1. Complete Test A (step 4 shows pre-filled data)
2. Click "Back" button → goes to step 3
3. Click "Next" button → returns to step 4

**Expected Result:**
- ✅ First Name: Still `Jan` (preserved in session)
- ✅ Last Name: Still `Testowy`
- ✅ Email: Still `test@registro.local` + readonly
- ✅ Phone: Still `+48123456789`

**Why It Works:**
- Controller checks session first
- Empty strings are now treated as "not filled"
- User data repopulates session on every visit

---

### ✅ Test C: Manual Edits Persist

**Steps:**
1. Visit step 4 (shows `Jan Testowy`)
2. Edit First Name to `Janek`
3. Edit Phone to `+48987654321`
4. Click "Back" → step 3
5. Click "Next" → step 4

**Expected Result:**
- ✅ First Name: `Janek` (edited value preserved)
- ✅ Last Name: `Testowy` (unchanged)
- ✅ Email: `test@registro.local` (readonly)
- ✅ Phone: `+48987654321` (edited value preserved)

**Why It Works:**
- Controller condition: `if (!isset($booking['first_name']) || $booking['first_name'] === '' ...)`
- Since `Janek` is NOT empty, controller skips pre-fill
- Session retains user edits

---

### ✅ Test D: Guest User (No Auto-fill)

**Steps:**
1. Log out
2. Try to access `/booking/step/1`

**Expected Result:**
- ❌ Redirected to login page (booking requires authentication)

**Note:** Booking wizard has `middleware('auth')` in controller constructor (line 26).

---

### ✅ Test E: User Without Phone Number

**Steps:**
1. Create user without phone:
```bash
docker compose exec app php artisan tinker --execute="
\$user = App\Models\User::create([
    'first_name' => 'Anna',
    'last_name' => 'Nowak',
    'email' => 'anna@registro.local',
    'password' => bcrypt('password'),
    'phone_e164' => null, // No phone
    'email_verified_at' => now(),
]);
\$user->assignRole('customer');
echo 'Created user: anna@registro.local / password';
"
```
2. Log in as `anna@registro.local`
3. Navigate to step 4

**Expected Result:**
- ✅ First Name: `Anna` (pre-filled)
- ✅ Last Name: `Nowak` (pre-filled)
- ✅ Email: `anna@registro.local` (pre-filled + readonly)
- ❌ Phone: Empty (no phone in profile, field required → validation error on submit)

---

## Debugging Tips

### Check Session Data

**Browser Console:**
```javascript
// Inspect session data sent by controller
console.log(window.bookingData); // Should exist if passed correctly

// Check Alpine.js component state
Alpine.store('contactInfoForm').firstName // Should match input value
```

**Laravel Logs:**
```bash
docker compose exec app tail -f storage/logs/laravel.log
# Look for BookingController debug logs (if added)
```

### Check Controller Execution

Add temporary debug log in `BookingController.php` line 173:

```php
// CRITICAL FIX: Update session with pre-filled data
session(['booking' => $booking]);
\Log::debug('Step 4 pre-fill', ['booking' => $booking, 'user' => $user->email]);
```

Then check logs:
```bash
docker compose exec app tail -f storage/logs/laravel.log | grep "Step 4 pre-fill"
```

### Verify User Model Accessor

```bash
docker compose exec app php artisan tinker --execute="
\$user = App\Models\User::where('email', 'test@registro.local')->first();
echo 'Phone accessor: ' . (\$user->phone ?? 'NULL') . PHP_EOL;
echo 'Phone raw: ' . (\$user->phone_e164 ?? 'NULL') . PHP_EOL;
"
```

**Expected Output:**
```
Phone accessor: +48123456789
Phone raw: +48123456789
```

---

## Rollback Instructions

If the fix causes issues:

### Revert Backend Changes

```bash
git diff app/Http/Controllers/BookingController.php
git checkout HEAD -- app/Http/Controllers/BookingController.php
```

### Revert Frontend Changes

```bash
git diff resources/views/booking-wizard/steps/contact.blade.php
git checkout HEAD -- resources/views/booking-wizard/steps/contact.blade.php
```

### Clear Caches

```bash
docker compose exec app php artisan optimize:clear
```

---

## Performance Impact

**Negligible:**
- Controller: +5 condition checks (microseconds)
- Frontend: +4 DOM queries on init (one-time, <1ms)
- Session: Same number of writes (just different data)

**No new database queries added.**

---

## Known Limitations

1. **Email is readonly for logged-in users** (by design, security measure)
2. **Phone format must match validation:** `^(\+48)?[\s-]?\d{9}$`
3. **Empty session values now trigger repopulation** (if user manually clears fields, they'll repopulate on navigation)

---

## Success Criteria

All 5 tests pass:
- ✅ Test A: First visit auto-fills
- ✅ Test B: Navigation preserves data
- ✅ Test C: Edits persist
- ✅ Test D: Guest redirected to login
- ✅ Test E: Users without phone handled gracefully

---

## Next Steps After Verification

1. Test on staging server with real user accounts
2. Add automated Pest/PHPUnit test for this feature
3. Update booking wizard documentation
4. Consider adding "Use profile data" button for users who manually cleared fields

---

## Related Documentation

- [Booking System](../features/booking-system/README.md)
- [User Model Pattern](../architecture/user-model.md)
- [Session Management](../architecture/session-management.md)
