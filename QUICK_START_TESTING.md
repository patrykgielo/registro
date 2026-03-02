# Quick Start - Testing Profile Synchronization

## 5-Minute Test (Recommended First)

### Prerequisites
```bash
cd /var/www/projects/registro/app
composer run dev
```

### Test Flow

#### 1. Create New User (2 minutes)
```
1. Visit: http://localhost:8000/register
2. Register with:
   - Email: testuser@example.com
   - Password: password123
3. Verify email if required
```

#### 2. First Booking (2 minutes)
```
1. Visit: http://localhost:8000/services
2. Click "Rezerwuj" on any service
3. Navigate through wizard:
   - Step 1: Confirm service
   - Step 2: Select tomorrow's date + any time slot
   - Step 3: Fill form (should be EMPTY):
     ✓ First Name: Jan
     ✓ Last Name: Kowalski
     ✓ Phone: +48501234567
     ✓ City: Warszawa
   - Step 4: Confirm booking
4. Success message should appear
```

#### 3. Second Booking - Verify Auto-fill (1 minute)
```
1. Visit: http://localhost:8000/services again
2. Click "Rezerwuj" on any service
3. Navigate to Step 3
4. ✅ EXPECTED: Form PRE-FILLED with:
   - First Name: Jan
   - Last Name: Kowalski
   - Phone: +48501234567
   - City: Warszawa
5. CHANGE data to:
   - First Name: Adam (different!)
   - City: Kraków (different!)
6. Complete booking
```

#### 4. Verify Data Preservation (30 seconds)
```
1. Start THIRD booking
2. Navigate to Step 3
3. ✅ EXPECTED: Form still shows ORIGINAL data:
   - First Name: Jan (NOT Adam)
   - City: Warszawa (NOT Kraków)
4. SUCCESS! Profile data was preserved ✓
```

## Database Verification (Optional)

### Check User Profile
```bash
# Using Laravel Tinker
php artisan tinker

# Run in Tinker:
$user = User::where('email', 'testuser@example.com')->first();
echo "First Name: " . $user->first_name . "\n";
echo "Last Name: " . $user->last_name . "\n";
echo "Phone: " . $user->phone_e164 . "\n";
echo "City: " . $user->city . "\n";

# Expected output:
# First Name: Jan
# Last Name: Kowalski
# Phone: +48501234567
# City: Warszawa
```

### Check Multiple Bookings
```bash
# In Tinker:
$user = User::where('email', 'testuser@example.com')->first();
$appointments = $user->customerAppointments()->count();
echo "Total bookings: " . $appointments . "\n";

# Expected: 3 (or however many you created)
```

## Browser DevTools Check (Advanced)

### Verify Alpine.js Pre-fill

1. Open booking page (Step 3)
2. Open DevTools (F12)
3. In Console, run:
   ```javascript
   Alpine.raw($el).customer
   ```
4. Expected output:
   ```json
   {
     "first_name": "Jan",
     "last_name": "Kowalski",
     "phone_e164": "+48501234567",
     "city": "Warszawa",
     ...
   }
   ```

## Common Issues

### Issue: Form Not Pre-filling
**Solution:**
1. Check if user is logged in: Look for navbar with user name
2. Clear browser cache: Ctrl+F5
3. Check browser console for JS errors
4. Verify routes work: `php artisan route:list | grep booking`

### Issue: Profile Gets Overwritten
**Solution:**
1. Check AppointmentController code: Should use `empty()` checks
2. Clear config cache: `php artisan config:clear`
3. Check database directly (Tinker query above)

### Issue: Cannot Complete Booking
**Solution:**
1. Ensure date is tomorrow (24h advance booking rule)
2. Check staff availability: User with 'staff' role must exist
3. Check logs: `tail -f storage/logs/laravel.log`

## Success Indicators

You'll know it's working when:

✅ **New user**: Empty form, data saved after first booking
✅ **Returning user**: Form pre-filled with saved data
✅ **Data preservation**: Modified form data doesn't overwrite profile
✅ **Partial profile**: Only empty fields get filled

## Full Testing Guide

For comprehensive testing scenarios, see:
- **Manual Testing**: `/var/www/projects/registro/app/TESTING_GUIDE.md`
- **Architecture**: `/var/www/projects/registro/app/docs/PROFILE_SYNC_IMPLEMENTATION.md`
- **Summary**: `/var/www/projects/registro/app/IMPLEMENTATION_SUMMARY.txt`

## Questions?

Check the documentation:
1. ADR-001: `docs/decisions/ADR-001-extended-user-profile-fields.md`
2. Implementation details: `docs/PROFILE_SYNC_IMPLEMENTATION.md`
3. Test scenarios: `TESTING_GUIDE.md`

---

**Quick Test Time:** 5 minutes
**Full Test Time:** 20 minutes
**Last Updated:** 2025-10-18
