# Testing Guide - Profile Synchronization Feature

## Manual Testing Instructions

### Prerequisites
1. Start the development server:
   ```bash
   cd /var/www/projects/registro/app
   composer run dev
   ```

2. Or use Docker:
   ```bash
   docker compose up -d
   ```

### Test Scenario 1: New User with Empty Profile

**Steps:**
1. Register a new user account at `/register`
2. Verify email if required
3. Navigate to Services page
4. Click "Rezerwuj" on any service
5. **Expected**: Step 3 form should be empty (no pre-filled data)
6. Fill in all fields:
   - First Name: Jan
   - Last Name: Kowalski
   - Phone: +48501234567
   - Street: Marszałkowska
   - Number: 12/34
   - City: Warszawa
   - Postal Code: 00-000
   - Access Notes: Kod do bramy: 1234
7. Complete the booking
8. Check database:
   ```sql
   SELECT first_name, last_name, phone_e164, city FROM users WHERE email = 'your-email@example.com';
   ```
9. **Expected**: Profile data is saved in the database

### Test Scenario 2: Returning User - Form Auto-fill

**Steps:**
1. Log in with the user from Test Scenario 1
2. Navigate to Services page
3. Click "Rezerwuj" on any service
4. **Expected**: Step 3 form is pre-filled with saved profile data
   - First Name: Jan
   - Last Name: Kowalski
   - Phone: +48501234567
   - Street: Marszałkowska
   - Number: 12/34
   - City: Warszawa
   - Postal Code: 00-000
   - Access Notes: Kod do bramy: 1234
5. Fields should be editable (not disabled)

### Test Scenario 3: Profile Data Preservation

**Steps:**
1. Continue from Test Scenario 2
2. Modify the pre-filled data in the form:
   - First Name: Adam
   - Last Name: Nowak
   - Phone: +48600999888
   - City: Kraków
3. Complete the booking
4. Check database:
   ```sql
   SELECT first_name, last_name, phone_e164, city FROM users WHERE email = 'your-email@example.com';
   ```
5. **Expected**: Original data is preserved (NOT overwritten)
   - First Name: Jan (not Adam)
   - Last Name: Kowalski (not Nowak)
   - Phone: +48501234567 (not +48600999888)
   - City: Warszawa (not Kraków)
6. Start another booking
7. **Expected**: Form shows original data (Jan Kowalski, Warszawa)

### Test Scenario 4: Partial Profile - Fill Empty Fields Only

**Steps:**
1. Create a new user via database with partial profile:
   ```sql
   UPDATE users
   SET first_name = 'Piotr',
       last_name = 'Wiśniewski',
       phone_e164 = '+48509876543',
       street_name = NULL,
       city = NULL,
       postal_code = NULL
   WHERE email = 'partial-user@example.com';
   ```
2. Log in as this user
3. Start a new booking
4. **Expected**: Form shows partial pre-fill:
   - First Name: Piotr ✓
   - Last Name: Wiśniewski ✓
   - Phone: +48509876543 ✓
   - Street: (empty)
   - City: (empty)
   - Postal Code: (empty)
5. Fill in the empty fields:
   - Street: Nowa
   - Number: 99
   - City: Gdańsk
   - Postal Code: 80-000
6. Modify existing field:
   - First Name: Stefan (different from Piotr)
7. Complete the booking
8. Check database:
   ```sql
   SELECT first_name, last_name, phone_e164, street_name, city FROM users WHERE email = 'partial-user@example.com';
   ```
9. **Expected**:
   - First Name: Piotr (NOT Stefan - existing field preserved)
   - Last Name: Wiśniewski (preserved)
   - Phone: +48509876543 (preserved)
   - Street: Nowa (filled)
   - City: Gdańsk (filled)
   - Postal Code: 80-000 (filled)

### Test Scenario 5: Optional Address Fields

**Steps:**
1. Create a new user
2. Start a booking
3. Fill only required fields:
   - First Name: Maria
   - Last Name: Nowacka
   - Phone: +48512345678
4. Leave address fields empty
5. Complete the booking
6. Check database:
   ```sql
   SELECT first_name, last_name, phone_e164, street_name, city, postal_code FROM users WHERE email = 'maria@example.com';
   ```
7. **Expected**:
   - First Name: Maria ✓
   - Last Name: Nowacka ✓
   - Phone: +48512345678 ✓
   - Street: NULL
   - City: NULL
   - Postal Code: NULL

## Browser DevTools Inspection

### Check Alpine.js Data Initialization

1. Open browser DevTools (F12)
2. Navigate to booking page
3. In Console, type:
   ```javascript
   Alpine.raw($el).customer
   ```
4. **Expected** (returning user):
   ```json
   {
     "first_name": "Jan",
     "last_name": "Kowalski",
     "phone_e164": "+48501234567",
     "street_name": "Marszałkowska",
     "street_number": "12/34",
     "city": "Warszawa",
     "postal_code": "00-000",
     "access_notes": "Kod do bramy: 1234",
     "notes": ""
   }
   ```

### Check Form Field Values

1. Inspect form input elements in Step 3
2. **Expected**: `value` attributes contain profile data
3. **Expected**: No `disabled` or `readonly` attributes
4. **Expected**: Fields can be edited by clicking and typing

## Database Queries for Verification

### Check User Profile Data
```sql
SELECT
    id,
    email,
    first_name,
    last_name,
    phone_e164,
    street_name,
    street_number,
    city,
    postal_code,
    access_notes
FROM users
WHERE email = 'test@example.com';
```

### Check Appointment Data vs User Profile
```sql
SELECT
    u.first_name AS user_first_name,
    u.last_name AS user_last_name,
    u.phone_e164 AS user_phone,
    u.city AS user_city,
    a.notes AS appointment_notes,
    a.created_at
FROM appointments a
JOIN users u ON a.customer_id = u.id
WHERE u.email = 'test@example.com'
ORDER BY a.created_at DESC;
```

## Expected Behavior Summary

| Scenario | User Profile Before | Form Submission Data | User Profile After |
|----------|-------------------|---------------------|-------------------|
| New user | All NULL | Jan, Kowalski, +48501234567 | Saved to profile |
| Returning user (same data) | Jan, Kowalski | Jan, Kowalski (pre-filled) | Unchanged |
| Returning user (different data) | Jan, Kowalski | Adam, Nowak (modified) | Jan, Kowalski (preserved) |
| Partial profile | Jan, Kowalski, NULL city | Jan, Kowalski, Warszawa | Jan, Kowalski, Warszawa (city filled) |

## Common Issues and Solutions

### Issue 1: Form Not Pre-filling
- Check browser console for JavaScript errors
- Verify user is authenticated: `@auth` directive
- Inspect `$user` variable in Blade template
- Check Alpine.js initialization in x-init

### Issue 2: Profile Gets Overwritten
- Check AppointmentController::store() logic
- Verify `empty()` checks are present
- Check if `$profileUpdates` array is properly filtered

### Issue 3: Address Fields Not Saving
- Verify optional fields have both checks:
  - Profile field is empty: `empty($user->city)`
  - Form value is provided: `!empty($validated['city'])`

## Automated Testing (When SQLite Extension Available)

Run the test suite:
```bash
cd /var/www/projects/registro/app
php artisan test --filter=ProfileSynchronizationTest
```

Expected: 6 passing tests covering all scenarios above.

## Documentation Reference

- ADR-001: `/var/www/projects/registro/app/docs/decisions/ADR-001-extended-user-profile-fields.md`
- Implementation Version: 1.1
- Date: 2025-10-18
