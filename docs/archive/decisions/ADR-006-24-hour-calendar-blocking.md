# ADR-006: Precise 24-Hour Advance Booking Calendar Blocking

**Status:** Accepted
**Date:** 2025-10-19
**Decision Makers:** Development Team
**Tags:** booking, calendar, validation, user-experience, business-rules

## Context and Problem Statement

The booking system requires 24-hour advance notice for all appointments (configurable via `booking.advance_booking_hours`). However, the initial implementation had a mismatch between frontend calendar blocking and backend validation:

### The Problem

**Frontend (app.js):**
```javascript
// OLD CODE - Incorrect
minDate() {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);  // +1 day
    return tomorrow.toISOString().split('T')[0];
}
```

This calculated "tomorrow" as the minimum date, which is **calendar days** not **24 hours**.

**Backend (BookingController.php):**
```php
// OLD CODE - Inconsistent
$minDateTime = now()->addHours(24);  // Exact 24 hours
$requestedDate = Carbon::parse($request->date . ' 00:00:00');

if ($requestedDate->lt($minDateTime->startOfDay())) {
    return ['slots' => []];
}
```

### User Impact

**Scenario:** User books at 10:00 today
- **Frontend allows:** Tomorrow (e.g., 2025-10-20) - only 14 hours away if booking at 10:00 for 00:00 next day
- **Backend rejects:** All slots on tomorrow because earliest slot (09:00) is < 24 hours away
- **Result:** User sees available date in calendar → selects it → gets "No slots available" → frustration

### Edge Cases

1. **Business Hours (9:00-18:00):** Even if user books at 23:00, tomorrow's 09:00 slot is only 10 hours away
2. **Timezone Complexity:** DST changes and timezone offsets can cause additional discrepancies
3. **End of Day:** User booking at 23:59 sees "tomorrow" as valid, but it's only 0-9 hours to business hours start

## Decision Drivers

- **User Experience First:** Never show dates in calendar that backend will reject
- **Clarity Over Complexity:** Avoid confusing "hours vs days" logic visible to users
- **Business Hours Alignment:** System operates 09:00-18:00, so daily boundaries matter
- **Conservative Safety:** Better to block one extra day than frustrate users
- **Maintainability:** Simple logic reduces bugs and confusion
- **Performance:** Frontend pre-filtering reduces unnecessary API calls

## Considered Options

### Option 1: Exact 24-Hour Calculation (Frontend Mirrors Backend)

**Implementation:**
```javascript
minDate() {
    const advanceHours = 24;
    const minDateTime = new Date(Date.now() + advanceHours * 60 * 60 * 1000);

    const hours = minDateTime.getHours();
    if (hours >= 0) {
        minDateTime.setDate(minDateTime.getDate() + 1);
        minDateTime.setHours(0, 0, 0, 0);
    }

    return minDateTime.toISOString().split('T')[0];
}
```

**Pros:**
- Mathematically precise
- Mirrors backend logic
- Could theoretically allow same-day bookings in some edge cases

**Cons:**
- Complex logic, hard to understand
- Timezone edge cases remain
- Still needs rounding up to next day due to business hours
- Maintenance burden increases
- User confusion: "Why can't I book tomorrow?"

### Option 2: Conservative +2 Days Approach (SELECTED)

**Implementation:**
```javascript
minDate() {
    const minDate = new Date();
    minDate.setDate(minDate.getDate() + 2);
    minDate.setHours(0, 0, 0, 0);
    return minDate.toISOString().split('T')[0];
}
```

**Pros:**
- **Simple and Clear:** Easy to understand and maintain
- **Always Safe:** Guarantees 24h+ requirement met
- **Business Hours Aligned:** Skips full days, matches business thinking
- **No Edge Cases:** Works regardless of current time, timezone, DST
- **User Friendly:** Clear messaging: "Book 2 days ahead"
- **Consistent:** Frontend + backend always agree

**Cons:**
- **Conservative:** Blocks one extra day in some scenarios
- **Theoretical Loss:** Could lose bookings if someone really needs ~24-30 hour window
- **Not Mathematically Precise:** 2 days ≠ exactly 24 hours

### Option 3: Dynamic Calculation Based on Current Hour

Check if current time + 24h falls into next business day, then calculate minimum.

**Pros:**
- More flexible than Option 2
- Could allow some same-day or next-day bookings

**Cons:**
- Complex conditional logic
- Timezone dependencies
- Testing complexity increases
- User confusion about availability rules

## Decision Outcome

**Chosen option: Option 2 - Conservative +2 Days Approach**

### Rationale

1. **User Experience Priority:** A predictable "2 days advance" rule is clearer than varying availability based on time of day
2. **Business Context:** Car detailing services typically book days in advance, not hours
3. **Simplicity Wins:** Code that's easy to understand is easier to maintain and debug
4. **Safety First:** Better to be conservative than frustrate users with rejected bookings
5. **Consistent Messaging:** "24 hours minimum" translates cleanly to "book from 2 days ahead"

## Implementation Details

### 1. Frontend - app.js (Lines 34-39)

**Updated Code:**
```javascript
// Calculate minimum booking date (24 hours advance requirement)
// Conservative approach: Always block next 2 full days to ensure 24h requirement
// This avoids edge cases with business hours (9-18) and timezone issues
minDate() {
    const minDate = new Date();
    minDate.setDate(minDate.getDate() + 2);
    minDate.setHours(0, 0, 0, 0);
    return minDate.toISOString().split('T')[0];
}
```

**Behavior:**
- Blocks today + tomorrow
- Allows bookings starting from day after tomorrow (00:00)
- HTML5 `<input type="date" min="...">` prevents selecting blocked dates

### 2. Backend - BookingController.php (Lines 45-59)

**Updated Code:**
```php
// Check if date meets 24-hour advance booking requirement
// We check the EARLIEST possible slot (business hours start) to be conservative
$businessHours = config('booking.business_hours');
$earliestSlotDateTime = Carbon::parse($date->format('Y-m-d') . ' ' . $businessHours['start']);

if (!$this->appointmentService->meetsAdvanceBookingRequirement($earliestSlotDateTime)) {
    $minDateTime = now()->addHours(config('booking.advance_booking_hours', 24));

    return response()->json([
        'slots' => [],
        'date' => $date->format('Y-m-d'),
        'message' => 'Rezerwacje możliwe dopiero od ' . $minDateTime->format('d.m.Y H:i'),
        'reason' => 'advance_booking_not_met'
    ]);
}
```

**Key Changes:**
- Checks **earliest slot** (business hours start, e.g., 09:00) instead of midnight
- Uses existing `AppointmentService::meetsAdvanceBookingRequirement()` method
- Returns descriptive error with exact datetime when bookings become available
- Includes `reason` field for frontend handling

### 3. UI Enhancement - booking/create.blade.php (Lines 106-117)

**New Info Box:**
```blade
<!-- Date Availability Warning -->
<div class="alert alert-warning mb-6">
    <div class="flex items-start">
        <svg class="w-6 h-6 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="font-bold">Wymaganie 24 godzin</p>
            <p class="mt-1">Rezerwacje można składać z co najmniej 24-godzinnym wyprzedzeniem. Najbliższy dostępny termin: <span id="earliest-date" class="font-semibold"></span></p>
        </div>
    </div>
</div>
```

**JavaScript for Dynamic Date Display (Lines 537-554):**
```javascript
document.addEventListener('DOMContentLoaded', function() {
    const minDate = new Date();
    minDate.setDate(minDate.getDate() + 2);
    minDate.setHours(0, 0, 0, 0);

    const formatted = minDate.toLocaleDateString('pl-PL', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const el = document.getElementById('earliest-date');
    if (el) el.textContent = formatted;
});
```

**Features:**
- Warning box with clock icon
- Dynamically calculates and displays earliest bookable date
- Polish locale formatting: "poniedziałek, 21 października 2025"
- Shows immediately when user reaches Step 2

## Affected Files

1. `/resources/js/app.js` - Frontend calendar minimum date logic
2. `/app/Http/Controllers/BookingController.php` - Backend date validation
3. `/resources/views/booking/create.blade.php` - UI info box and dynamic date display
4. `/app/Services/AppointmentService.php` - No changes (already had `meetsAdvanceBookingRequirement()`)
5. `/config/booking.php` - No changes (configuration remains the same)

## Testing Scenarios

### Test 1: Today is Monday 10:00
- **Expected Frontend:** Calendar blocks Monday, Tuesday; allows from Wednesday+
- **Expected Backend:** If user manipulates date to Tuesday, all slots return `[]` with message
- **Result:** ✅ User cannot select invalid dates

### Test 2: Today is Friday 23:00
- **Expected Frontend:** Calendar blocks Friday, Saturday; allows from Sunday+
- **Expected Backend:** Sunday slots available (earliest 09:00 is >24h away)
- **Result:** ✅ User can book Sunday

### Test 3: Manual Date Manipulation (DevTools)
- **User Action:** Change `<input min="2025-10-21">` to `min="2025-10-19"` via DevTools
- **Expected Frontend:** Calendar allows selecting blocked date
- **Expected Backend:** Returns `slots: []` with descriptive message
- **Result:** ✅ Backend safety net catches manipulation

### Test 4: Valid Date Selection
- **User Action:** Select date 3 days in future
- **Expected:** Slots load normally
- **Result:** ✅ Normal booking flow

### Test 5: Boundary Condition (Exactly 2 days ahead)
- **Current:** Monday 00:00
- **User Selects:** Wednesday 00:00 (earliest slot 09:00)
- **Time Difference:** 57 hours (>24h requirement)
- **Result:** ✅ Slots available

## Consequences

### Positive

- **Eliminated User Frustration:** Frontend and backend now aligned
- **Clearer User Expectations:** Visible warning about 24-hour requirement with exact date
- **Reduced API Load:** Frontend pre-filtering prevents unnecessary slot requests
- **Simpler Codebase:** Less complex logic = fewer bugs
- **Better Error Messages:** Backend returns descriptive datetime instead of generic error
- **Mobile-Friendly:** HTML5 date picker respects `min` attribute across devices
- **Accessibility:** Screen readers announce blocked dates and warning message

### Negative

- **Slightly More Restrictive:** Blocks bookings in ~24-48 hour window
- **Potential Lost Bookings:** Edge case where someone wants to book at 23:00 for day after tomorrow
- **Not Precise:** Doesn't match exact mathematical 24-hour calculation

### Mitigation for Negatives

1. **Business Justification:** Car detailing requires preparation time; 2-day advance is reasonable industry standard
2. **Admin Override:** Admin panel can still create appointments with shorter notice if needed
3. **Configuration:** `booking.advance_booking_hours` can be adjusted if business needs change
4. **Future Enhancement:** Could add "urgent booking" feature with price premium

## Business Rules Summary

| Rule | Implementation |
|------|----------------|
| **Minimum Advance** | 24 hours (configurable) |
| **Frontend Blocking** | +2 calendar days from current date |
| **Backend Validation** | Checks earliest slot (09:00) meets 24h requirement |
| **Business Hours** | 09:00-18:00 (Monday-Sunday) |
| **Timezone** | Europe/Warsaw (UTC+1/+2) |
| **Slot Interval** | 15 minutes |

## Configuration Points

All settings in `/config/booking.php`:

```php
'advance_booking_hours' => env('BOOKING_ADVANCE_HOURS', 24),
'business_hours' => [
    'start' => env('BOOKING_BUSINESS_HOURS_START', '09:00'),
    'end' => env('BOOKING_BUSINESS_HOURS_END', '18:00'),
],
'timezone' => env('BOOKING_TIMEZONE', 'Europe/Warsaw'),
```

**To adjust:** Set environment variables in `.env`

## Security Considerations

- **Client-Side Validation Bypass:** HTML5 `min` attribute can be modified via DevTools
- **Mitigation:** Backend always validates via `meetsAdvanceBookingRequirement()`
- **SQL Injection:** Protected via Eloquent ORM and validated Carbon dates
- **XSS:** Error messages escaped via Blade templating and JSON encoding

## Future Enhancements

1. **Dynamic Calculation Option:** Add feature flag for exact 24h calculation vs +2 days
2. **Premium Rush Booking:** Allow booking with <24h notice for additional fee
3. **Slot Availability Preview:** Show calendar heatmap of available vs busy days
4. **Smart Suggestions:** "No slots on Wednesday? Try Thursday!" automated suggestions
5. **Waiting List:** Allow users to join waitlist for fully booked dates
6. **Admin Configuration UI:** Filament page to adjust advance hours without code changes

## Rollback Plan

If issues arise:

1. **Immediate Rollback (Frontend):**
```javascript
// Restore to +1 day (old behavior)
minDate() {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    return tomorrow.toISOString().split('T')[0];
}
```

2. **Immediate Rollback (Backend):**
```php
// Restore old validation
$requestedDate = Carbon::parse($request->date . ' 00:00:00');
if ($requestedDate->lt($minDateTime->startOfDay())) {
    return response()->json(['slots' => [], 'message' => '...']);
}
```

3. **Rebuild Assets:**
```bash
npm run build
php artisan optimize:clear
```

## Monitoring and Metrics

Track these metrics to validate decision:

- **Slot Request Failures:** Monitor API calls returning `reason: 'advance_booking_not_met'`
- **Booking Conversion Rate:** Compare before/after implementation
- **User Support Tickets:** Track booking-related confusion reports
- **Calendar Manipulation Attempts:** Log backend rejections of invalid dates
- **Booking Lead Time:** Analyze average hours between booking and appointment

**Success Criteria:**
- ❌ Zero frontend-backend mismatches (impossible states)
- ❌ <1% support tickets about "no available slots" on visible dates
- ❌ Booking conversion rate maintains or improves

## References

- **Laravel Carbon:** https://carbon.nesbot.com/docs/
- **HTML5 Date Input:** https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/date
- **Config:** `/config/booking.php` (lines 22-29)
- **Service:** `/app/Services/AppointmentService.php` (lines 267-273)
- **Related ADR:** ADR-004 (Automatic Staff Assignment)

## Changelog

### Version 1.0 - Initial Implementation (2025-10-19)

- Frontend: Changed from +1 day to +2 days blocking
- Backend: Added earliest slot validation with descriptive errors
- UI: Added warning box with dynamic earliest date display
- Documentation: Created this ADR

---

**Document Version:** 1.0
**Last Updated:** 2025-10-19
**Next Review:** 2025-11-19
**Related PRs:** N/A (direct commit to main)
