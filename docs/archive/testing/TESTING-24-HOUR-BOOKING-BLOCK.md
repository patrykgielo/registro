# Testing Guide: 24-Hour Advance Booking Calendar Block

**Feature:** Precise calendar blocking for 24-hour advance booking requirement
**Implementation Date:** 2025-10-19
**Related ADR:** ADR-005-24-hour-calendar-blocking.md

## Quick Start

### Prerequisites
```bash
cd app
npm run build              # Build updated frontend assets
php artisan optimize:clear # Clear all caches
```

### Access Points
- **Booking Flow:** https://paradocks.local:8444/booking/{service_id}
- **API Endpoint:** POST /booking/available-slots (auth required)
- **Admin Panel:** https://paradocks.local:8444/admin (can create appointments without restrictions)

## Test Scenarios

### Test 1: Calendar Date Blocking (Browser UI)

**Objective:** Verify frontend blocks correct dates

**Steps:**
1. Navigate to any booking page (e.g., `/booking/1`)
2. Complete Step 1 (service selection)
3. On Step 2, observe the date picker

**Expected Results:**
- ✅ **Today** is blocked (grayed out, not selectable)
- ✅ **Tomorrow** is blocked (grayed out, not selectable)
- ✅ **Day after tomorrow** (and beyond) is selectable
- ✅ Warning box displays: "Wymaganie 24 godzin"
- ✅ Dynamic date shows earliest bookable date in Polish (e.g., "środa, 23 października 2025")
- ✅ Info box about automatic staff assignment is visible

**How to Verify:**
```
Example: Testing on Monday 2025-10-21 at 14:00

Blocked dates in calendar:
- 2025-10-21 (Monday - today)
- 2025-10-22 (Tuesday - tomorrow)

Available dates:
- 2025-10-23 (Wednesday) and onwards
```

**Browser DevTools Check:**
```html
<!-- Inspect the date input element -->
<input type="date"
       id="date-input"
       min="2025-10-23"  <!-- Always today + 2 days -->
       x-model="date">
```

---

### Test 2: Backend Validation (Valid Date)

**Objective:** Verify backend accepts valid dates and returns slots

**Setup:**
```bash
# Ensure you have authenticated user and test service
php artisan tinker
>>> $user = App\Models\User::first();
>>> $service = App\Models\Service::first();
```

**API Request (cURL):**
```bash
# Replace {date} with day after tomorrow (YYYY-MM-DD format)
DATE=$(date -d "+2 days" +%Y-%m-%d)

curl -X POST https://paradocks.local:8444/booking/available-slots \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=...; laravel_session=..." \
  -H "X-CSRF-TOKEN: ..." \
  -d "{
    \"service_id\": 1,
    \"date\": \"$DATE\"
  }"
```

**Expected Response (200 OK):**
```json
{
  "slots": [
    {
      "start": "09:00",
      "end": "10:30",
      "datetime_start": "2025-10-23 09:00",
      "datetime_end": "2025-10-23 10:30"
    },
    {
      "start": "09:15",
      "end": "10:45",
      "datetime_start": "2025-10-23 09:15",
      "datetime_end": "2025-10-23 10:45"
    }
    // ... more slots
  ],
  "date": "2025-10-23"
}
```

**Verification:**
- ✅ Status code is `200`
- ✅ `slots` array contains available time slots
- ✅ No `reason` field present
- ✅ No error message

---

### Test 3: Backend Validation (Invalid Date - Tomorrow)

**Objective:** Verify backend rejects dates that don't meet 24h requirement

**API Request (cURL):**
```bash
# Use tomorrow's date (should be rejected)
DATE=$(date -d "+1 day" +%Y-%m-%d)

curl -X POST https://paradocks.local:8444/booking/available-slots \
  -H "Content-Type: application/json" \
  -H "Cookie: XSRF-TOKEN=...; laravel_session=..." \
  -H "X-CSRF-TOKEN: ..." \
  -d "{
    \"service_id\": 1,
    \"date\": \"$DATE\"
  }"
```

**Expected Response (200 OK with empty slots):**
```json
{
  "slots": [],
  "date": "2025-10-22",
  "message": "Rezerwacje możliwe dopiero od 22.10.2025 14:00",
  "reason": "advance_booking_not_met"
}
```

**Verification:**
- ✅ Status code is `200` (not error, but informational)
- ✅ `slots` array is **empty**
- ✅ `message` contains descriptive Polish text with exact datetime
- ✅ `reason` field is `"advance_booking_not_met"`
- ✅ `date` reflects the requested date

---

### Test 4: Frontend-Backend Integration

**Objective:** Verify complete user flow without errors

**Steps:**
1. Open booking page in browser
2. Complete Step 1 (select service)
3. On Step 2, select **valid date** (2+ days ahead)
4. **Open browser DevTools** → Network tab
5. Select the date
6. Observe AJAX request to `/booking/available-slots`

**Expected Results:**
- ✅ POST request sent to `/booking/available-slots`
- ✅ Request payload contains `service_id` and `date`
- ✅ Response status `200 OK`
- ✅ Response contains `slots` array with time options
- ✅ Time slot buttons appear in UI (grid layout)
- ✅ No error message shown
- ✅ Loading spinner disappears

**Console Check:**
```javascript
// Should see this log when wizard initializes
"Booking wizard initialized - automatic staff assignment enabled"
```

---

### Test 5: DevTools Manipulation Attack

**Objective:** Verify backend safety net catches client-side bypasses

**Steps:**
1. Open booking page
2. Navigate to Step 2
3. **Open DevTools** → Elements tab
4. Locate date input: `<input type="date" id="date-input">`
5. Modify `min` attribute to allow blocked dates:
   ```html
   <!-- Change from: -->
   <input min="2025-10-23">

   <!-- To: -->
   <input min="2025-10-21">
   ```
6. Select **today** or **tomorrow** in the calendar
7. Observe the slots area

**Expected Results:**
- ✅ Frontend allows date selection (client-side validation bypassed)
- ✅ AJAX request sent to backend
- ✅ Backend returns `slots: []` with error message
- ✅ UI displays: "Brak dostępnych terminów w tym dniu"
- ✅ No time slot buttons appear
- ✅ User **cannot proceed** to next step

**Security Note:** This demonstrates defense-in-depth. Never trust client-side validation alone.

---

### Test 6: Boundary Condition (Exactly 2 Days Ahead)

**Objective:** Test edge case at exact minimum boundary

**Scenario:**
- **Current Time:** Monday 00:00:00
- **Selected Date:** Wednesday 00:00:00
- **Earliest Slot:** Wednesday 09:00:00
- **Time Difference:** 57 hours (exceeds 24h requirement)

**Expected:**
- ✅ Date is selectable in frontend
- ✅ Backend returns available slots
- ✅ No validation errors

**Testing:**
```bash
# Test exactly 2 days ahead
DATE=$(date -d "+2 days" +%Y-%m-%d)

curl -X POST https://paradocks.local:8444/booking/available-slots \
  -H "Content-Type: application/json" \
  -H "Cookie: ..." \
  -H "X-CSRF-TOKEN: ..." \
  -d "{\"service_id\": 1, \"date\": \"$DATE\"}"
```

**Verify:**
- Response contains `slots` array
- Earliest slot is business hours start (09:00)

---

### Test 7: Info Box Dynamic Date Display

**Objective:** Verify JavaScript correctly calculates and displays earliest date

**Steps:**
1. Open booking page
2. Navigate to Step 2
3. Locate warning box: "Wymaganie 24 godzin"
4. Read the text in `<span id="earliest-date">`

**Expected Format:**
```
środa, 23 października 2025
```
(Polish locale: weekday, day, month, year)

**Verification:**
- ✅ Date shown is exactly **today + 2 days**
- ✅ Format uses Polish day/month names
- ✅ Matches the `min` attribute on date input

**DevTools Console Check:**
```javascript
// Run in console to verify calculation
const minDate = new Date();
minDate.setDate(minDate.getDate() + 2);
minDate.setHours(0, 0, 0, 0);
console.log(minDate.toISOString().split('T')[0]);
// Should match date input's min attribute
```

---

### Test 8: Multiple Service Durations

**Objective:** Verify blocking works for different service durations

**Test Services:**
- Short service (30 min)
- Medium service (90 min)
- Long service (240 min)

**Expected:**
- ✅ All services block same dates (today + tomorrow)
- ✅ Longer services may have fewer slots, but dates blocked consistently
- ✅ Backend validation applies to all services equally

---

### Test 9: Weekend Booking

**Objective:** Test booking over weekend

**Scenario:** User books on Friday evening

**Steps:**
1. Set system time to Friday 18:00 (last business hour)
2. Open booking page
3. Check calendar availability

**Expected:**
- ✅ Friday (today) - blocked
- ✅ Saturday (tomorrow) - blocked
- ✅ Sunday (day after tomorrow) - **available**
- ✅ Backend accepts Sunday slots if configured

**Note:** Check `business_hours` config - if business operates on weekends

---

### Test 10: Admin Override (Optional)

**Objective:** Verify admin panel can create short-notice appointments

**Steps:**
1. Login as admin/super-admin
2. Navigate to Admin Panel → Appointments
3. Create new appointment with tomorrow's date

**Expected:**
- ✅ Admin form allows selecting any future date
- ✅ No 24-hour restriction in admin panel
- ✅ Appointment saves successfully
- ✅ Customer booking flow still blocked

**Rationale:** Admins may need to accommodate emergency bookings or VIP customers

---

## Automated Testing

### Unit Test Example

**File:** `tests/Unit/AppointmentServiceTest.php`

```php
public function test_meets_advance_booking_requirement()
{
    $service = new AppointmentService();

    // Test: 48 hours ahead (should pass)
    $validDate = now()->addHours(48);
    $this->assertTrue($service->meetsAdvanceBookingRequirement($validDate));

    // Test: 23 hours ahead (should fail)
    $invalidDate = now()->addHours(23);
    $this->assertFalse($service->meetsAdvanceBookingRequirement($invalidDate));

    // Test: Exactly 24 hours ahead (should pass)
    $boundaryDate = now()->addHours(24);
    $this->assertTrue($service->meetsAdvanceBookingRequirement($boundaryDate));
}
```

**Run Test:**
```bash
php artisan test --filter=test_meets_advance_booking_requirement
```

---

### Feature Test Example

**File:** `tests/Feature/BookingControllerTest.php`

```php
public function test_get_available_slots_rejects_invalid_date()
{
    $user = User::factory()->create();
    $service = Service::factory()->create();

    // Try to book tomorrow (invalid)
    $invalidDate = now()->addDay()->format('Y-m-d');

    $response = $this->actingAs($user)
        ->postJson('/booking/available-slots', [
            'service_id' => $service->id,
            'date' => $invalidDate,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'slots' => [],
            'reason' => 'advance_booking_not_met',
        ])
        ->assertJsonStructure(['message']);
}

public function test_get_available_slots_accepts_valid_date()
{
    $user = User::factory()->create();
    $service = Service::factory()->create();

    // Book 3 days ahead (valid)
    $validDate = now()->addDays(3)->format('Y-m-d');

    $response = $this->actingAs($user)
        ->postJson('/booking/available-slots', [
            'service_id' => $service->id,
            'date' => $validDate,
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'slots',
            'date',
        ])
        ->assertJsonMissing(['reason']);
}
```

**Run Tests:**
```bash
php artisan test --filter=BookingControllerTest
```

---

## Browser Testing Matrix

| Browser | Version | OS | Status |
|---------|---------|----|----|
| Chrome | 120+ | Windows | ✅ |
| Firefox | 120+ | Windows | ✅ |
| Safari | 17+ | macOS | ✅ |
| Edge | 120+ | Windows | ✅ |
| Chrome Mobile | Latest | Android | ⚠️ Date picker UI varies |
| Safari Mobile | Latest | iOS | ⚠️ Date picker UI varies |

**Mobile Considerations:**
- HTML5 date picker renders natively on mobile
- `min` attribute still enforced
- Some browsers show calendar starting from `min` date

---

## Configuration Testing

### Adjust Advance Hours

**File:** `.env`

```env
# Test with different advance hours
BOOKING_ADVANCE_HOURS=48  # Require 2 full days (48 hours)
BOOKING_ADVANCE_HOURS=12  # Relaxed requirement (12 hours)
```

**After Change:**
```bash
php artisan config:clear
php artisan optimize:clear
```

**Re-test:**
- Frontend still uses +2 days (conservative)
- Backend validation adjusts to new requirement
- If setting <48h, frontend may be too conservative (expected)

---

## Known Issues and Limitations

### Issue 1: Configuration Mismatch
**Problem:** If `BOOKING_ADVANCE_HOURS` set to >48, frontend (+2 days) may be too permissive

**Solution:** Update `app.js` minDate logic if increasing requirement:
```javascript
// For 72-hour requirement (3 days)
minDate.setDate(minDate.getDate() + 3);
```

### Issue 2: Timezone Edge Cases
**Problem:** User browser timezone != server timezone can cause confusion

**Current Behavior:** Frontend uses client timezone, backend uses `Europe/Warsaw`

**Mitigation:** Conservative +2 days approach provides buffer

### Issue 3: No Same-Day Booking
**Problem:** Business may want to accept bookings day-of if called directly

**Workaround:** Admin panel allows creating appointments manually

---

## Rollback Instructions

If critical issues arise:

### 1. Rollback Frontend (app.js)
```javascript
// Change from +2 to +1
minDate() {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    return tomorrow.toISOString().split('T')[0];
}
```

### 2. Rebuild Assets
```bash
npm run build
```

### 3. Rollback Backend (BookingController.php)
```php
// Restore old simple check
$requestedDate = Carbon::parse($request->date . ' 00:00:00');
$minDateTime = now()->addHours(config('booking.advance_booking_hours', 24));

if ($requestedDate->lt($minDateTime->startOfDay())) {
    return response()->json([
        'slots' => [],
        'message' => 'Rezerwacja musi być dokonana co najmniej 24 godziny przed terminem wizyty.',
    ]);
}
```

### 4. Clear Caches
```bash
php artisan optimize:clear
```

---

## Success Metrics

Track these after deployment:

| Metric | Target | How to Measure |
|--------|--------|----------------|
| Booking Errors | <0.5% | Monitor `/booking/available-slots` responses with `reason: 'advance_booking_not_met'` |
| User Confusion | <1 support ticket/week | Track support requests about "no available dates" |
| Conversion Rate | Maintain or improve | Compare bookings/visits ratio before/after |
| API Load | Reduce by 10%+ | Frontend pre-filtering reduces invalid requests |

---

## Checklist for QA

- [ ] Frontend calendar blocks today + tomorrow
- [ ] Warning box shows earliest bookable date in Polish
- [ ] Valid dates load slots successfully
- [ ] Invalid dates return empty slots with message
- [ ] DevTools manipulation still blocked by backend
- [ ] Mobile browsers respect `min` attribute
- [ ] Admin panel can override restrictions
- [ ] Console shows no JavaScript errors
- [ ] Network tab shows clean API responses
- [ ] Booking flow completes successfully for valid dates

---

## Support and Debugging

### Enable Debug Mode

**File:** `.env`
```env
APP_DEBUG=true
LOG_LEVEL=debug
```

### Check Logs
```bash
tail -f storage/logs/laravel.log
# OR use Pail
php artisan pail --timeout=0
```

### Common Debug Points

1. **Date Format Issues:**
```bash
# Check server date
php artisan tinker
>>> now()->format('Y-m-d H:i:s')
>>> now()->addDays(2)->format('Y-m-d')
```

2. **Configuration Verification:**
```bash
php artisan tinker
>>> config('booking.advance_booking_hours')
>>> config('booking.business_hours')
```

3. **JavaScript Console:**
```javascript
// Check Alpine.js data
Alpine.raw(document.querySelector('[x-data]')._x_dataStack[0])
```

---

**Last Updated:** 2025-10-19
**Tested By:** Development Team
**Status:** All tests passing ✅
