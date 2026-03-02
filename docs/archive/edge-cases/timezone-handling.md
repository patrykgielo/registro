# Edge Case: Timezone Handling and DST Transitions

**Status**: Handled (with documented considerations)
**Impact**: Medium
**Priority**: P2 (Monitor during DST transitions)

## Problem Description

Poland (Europe/Warsaw) observes Daylight Saving Time (DST), transitioning between UTC+1 (winter) and UTC+2 (summer). This creates potential edge cases around appointment scheduling, especially during the transition dates.

## Configuration

**Application Timezone**: `Europe/Warsaw` (UTC+1/UTC+2)
**Set In**:
- `config/app.php`: `'timezone' => 'Europe/Warsaw'`
- `.env`: `APP_TIMEZONE=Europe/Warsaw`

**Database Storage**:
- `appointment_date`: DATE field (no timezone component)
- `start_time`, `end_time`: TIME fields (no timezone component)
- **Implication**: All times interpreted in app timezone (Europe/Warsaw)

**Server Timezone**: Recommended UTC (best practice)

---

## DST Transition Dates

### Spring Forward (March)
**Date**: Last Sunday of March at 2:00 AM
**Change**: 2:00 AM → 3:00 AM (clock jumps forward)
**Effect**: Hour from 2:00-3:00 AM does not exist

**Example**: March 30, 2025 at 2:00 AM becomes 3:00 AM

### Fall Back (October)
**Date**: Last Sunday of October at 3:00 AM
**Change**: 3:00 AM → 2:00 AM (clock repeats)
**Effect**: Hour from 2:00-3:00 AM occurs twice

**Example**: October 26, 2025 at 3:00 AM becomes 2:00 AM again

---

## Edge Case Scenarios

### Scenario 1: Booking During Spring Forward (2:00-3:00 AM Missing)

**Setup**:
- Business hours: 9:00 AM - 6:00 PM (standard)
- DST transition: March 30, 2025 at 2:00 AM

**Question**: Can customer book appointment for 2:30 AM on March 30?

**Answer**: NO
- Business hours start at 9:00 AM
- 2:30 AM is outside business hours
- Even if business hours were 24/7, 2:30 AM does not exist on this date

**Current Handling**:
- `isWithinBusinessHours()` check prevents 2:30 AM booking
- Carbon automatically handles non-existent time:
  ```php
  Carbon::parse('2025-03-30 02:30:00', 'Europe/Warsaw');
  // Returns: 2025-03-30 03:30:00 (automatically adjusted)
  ```

**Risk Level**: Low (business closed during transition hour)

---

### Scenario 2: Booking During Fall Back (2:00-3:00 AM Repeated)

**Setup**:
- DST transition: October 26, 2025 at 3:00 AM
- Hour 2:00-3:00 AM occurs twice

**Question**: If customer books 2:30 AM, which 2:30 AM?

**Answer**: First occurrence (before DST change)
- Carbon defaults to first occurrence
- Since business closed at night, not an issue

**If Business Hours Were Extended to Night**:
- Would need to specify DST flag explicitly
- Recommended: Avoid booking during transition hour

**Current Handling**: Not an issue (business hours 9 AM - 6 PM)

---

### Scenario 3: 24-Hour Advance Booking Near DST Transition

**Setup**:
- Current time: March 29, 2025 at 1:00 PM
- Customer wants to book: March 30, 2025 at 10:00 AM (next day)
- DST spring forward happens at 2:00 AM on March 30

**Question**: Is this exactly 24 hours in advance?

**Actual Elapsed Time**:
- March 29, 1:00 PM → March 30, 1:00 PM = 23 hours (due to clock jump)
- March 29, 1:00 PM → March 30, 10:00 AM = 20 hours

**Current Behavior**:
```php
$advanceHours = config('booking.advance_booking_hours', 24);
$minimumDateTime = now()->addHours($advanceHours);

return $appointmentDateTime->gte($minimumDateTime);
```

**Carbon's Handling**:
- `addHours(24)` adds 24 real hours (86400 seconds)
- DST transition automatically handled
- **Result**: Booking allowed if >= 24 actual hours

**Validation**: ✅ Correct - Uses actual elapsed time, not clock time

---

### Scenario 4: Appointment Spans DST Transition (Night Services)

**Setup**:
- Appointment: March 30, 2025 from 1:00 AM to 4:00 AM
- DST spring forward at 2:00 AM (clock skips to 3:00 AM)

**Question**: How long is this appointment?
- **Clock Time**: 3 hours
- **Actual Time**: 2 hours (1:00-2:00 is 1 hour, 3:00-4:00 is 1 hour)

**Current Handling**: N/A (business closed at night)

**If This Were Allowed**:
- Stored as: `start_time = '01:00:00'`, `end_time = '04:00:00'`
- Database calculates: 3 hours
- Actual duration: 2 hours
- **Issue**: Mismatch between stored duration and actual duration

**Recommendation**: Never allow appointments spanning DST transition
- Block bookings from 1:00 AM - 4:00 AM on transition dates
- Current business hours (9 AM - 6 PM) already prevent this

---

### Scenario 5: Server Timezone ≠ Application Timezone

**Setup**:
- Server timezone: UTC
- App timezone: Europe/Warsaw (UTC+1/UTC+2)

**Risk**: PHP functions using server timezone instead of app timezone

**Dangerous Functions** (use server timezone):
- `date()` - Use `Carbon::now()->format()` instead
- `strtotime()` - Use `Carbon::parse()` instead
- `time()` - Use `Carbon::now()->timestamp` instead
- `mktime()` - Use Carbon methods instead

**Safe Functions** (use app timezone):
- All Carbon methods (`Carbon::now()`, `Carbon::parse()`, etc.)
- Laravel `now()` helper
- Eloquent timestamp fields (automatically converted)

**Current Code Review**: ✅ All date operations use Carbon or Laravel helpers

---

### Scenario 6: Displaying Times to User

**User Location**: Customer browsing from different timezone (e.g., UK)
**App Timezone**: Europe/Warsaw

**Question**: Should we show times in user's local timezone or business timezone?

**Decision**: Show times in business timezone (Europe/Warsaw)
- **Rationale**: Appointment happens at physical location in Poland
- Customer needs to arrive at that local time
- Showing UK time would be confusing

**Implementation**:
- All times displayed in Europe/Warsaw
- No timezone conversion in UI
- Optionally show timezone label: "10:00 (czas lokalny)"

**Future Enhancement**: Detect user timezone and show both:
```
10:00 (czas lokalny w Warszawie)
9:00 (Twój lokalny czas w Londynie)
```

---

## Database Storage Strategy

### Current Approach: Date + Time Fields (No Timezone)

**Schema**:
```php
$table->date('appointment_date'); // '2025-10-20'
$table->time('start_time');       // '10:00:00'
$table->time('end_time');         // '11:00:00'
```

**Pros**:
- Simple and readable
- No timezone ambiguity (always interpreted as Europe/Warsaw)
- Easy to query by date or time

**Cons**:
- No timestamp for exact moment in UTC
- Cannot easily convert to other timezones
- DST transitions not explicitly stored

**Recommendation**: Keep current approach for MVP
- Business operates in single timezone
- No multi-timezone requirements
- Sufficient for local car detailing business

---

### Alternative: DATETIME or TIMESTAMP with Timezone

**Schema**:
```php
$table->timestamp('appointment_start'); // Stores UTC, converts to app timezone
```

**Pros**:
- Exact point in time (no DST ambiguity)
- Easy conversion to any timezone
- Proper handling of DST

**Cons**:
- More complex queries (must convert to date/time for display)
- Harder to query "all appointments on October 20"
- Overkill for single-timezone business

**When to Switch**: If expanding to multiple countries/timezones

---

## Carbon Usage Best Practices

### ✅ Correct Usage

```php
// Creating datetime in app timezone
$appointmentStart = Carbon::parse($date . ' ' . $time);

// Adding hours (DST-aware)
$deadline = now()->addHours(24);

// Comparing times (DST-aware)
if ($appointmentStart->gte($minimumDateTime)) { }

// Formatting for display
$formatted = $appointmentStart->format('Y-m-d H:i');
```

### ❌ Incorrect Usage

```php
// Using PHP native functions
$timestamp = strtotime($date . ' ' . $time); // Uses server timezone!

// Using date() instead of Carbon
$formatted = date('Y-m-d H:i', $timestamp); // Uses server timezone!

// Manually calculating hours
$hours = ($endTimestamp - $startTimestamp) / 3600; // Breaks during DST
```

---

## Testing Recommendations

### Unit Tests for DST Transitions

```php
use Carbon\Carbon;

test('24 hour advance booking works during spring DST transition', function () {
    // Set current time to March 29, 2025 at 1:00 PM
    Carbon::setTestNow(Carbon::parse('2025-03-29 13:00:00', 'Europe/Warsaw'));

    $advanceHours = 24;
    $minimumDateTime = now()->addHours($advanceHours);

    // Try to book for March 30 at 10:00 AM (next day, after DST)
    $appointmentTime = Carbon::parse('2025-03-30 10:00:00', 'Europe/Warsaw');

    // Should NOT be allowed (only 20 actual hours)
    expect($appointmentTime->gte($minimumDateTime))->toBeFalse();
});

test('appointment times stored correctly during DST', function () {
    $appointment = Appointment::create([
        'appointment_date' => '2025-03-30', // DST transition day
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
    ]);

    // Verify stored correctly
    expect($appointment->appointment_date->format('Y-m-d'))->toBe('2025-03-30');
    expect($appointment->start_time->format('H:i'))->toBe('10:00');
});

test('business hours validation works during DST', function () {
    $service = new AppointmentService();

    $start = Carbon::parse('2025-03-30 09:00:00', 'Europe/Warsaw');
    $end = Carbon::parse('2025-03-30 12:00:00', 'Europe/Warsaw');

    // Should be within business hours (9 AM - 6 PM)
    expect($service->isWithinBusinessHours($start, $end))->toBeTrue();
});
```

---

## Monitoring and Alerts

### Key Metrics to Track

1. **Bookings Around DST Transitions**:
   - Count bookings made 24 hours before DST
   - Count bookings made 24 hours after DST
   - Compare error rates

2. **Timezone-Related Errors**:
   - Log when `Carbon::parse()` adjusts non-existent times
   - Alert if server timezone != UTC

3. **Customer Support Tickets**:
   - Track complaints about "appointment 1 hour off"
   - Monitor confusion around DST dates

---

## Risk Assessment

**Spring Forward (Clock Skips Hour)**:
- **Risk**: Low (business closed during transition)
- **Impact**: None (no bookings affected)

**Fall Back (Clock Repeats Hour)**:
- **Risk**: Low (business closed during transition)
- **Impact**: None (no bookings affected)

**24-Hour Advance Booking**:
- **Risk**: Low (Carbon handles correctly)
- **Impact**: Minimal (rare edge case near DST)

**Server/App Timezone Mismatch**:
- **Risk**: Medium (if using PHP native functions)
- **Impact**: High (all times would be wrong)
- **Mitigation**: ✅ All code uses Carbon

**Overall Risk**: Low (with current business hours and Carbon usage)

---

## Recommendations

### Immediate Actions
1. ✅ Keep server timezone as UTC
2. ✅ Keep app timezone as Europe/Warsaw
3. ✅ Continue using Carbon for all datetime operations
4. ⚠️ Add validation: Server must be UTC

### Future Enhancements
1. Add `php artisan timezone:validate` command
   - Checks server timezone = UTC
   - Checks app timezone is valid
   - Warns if mismatched

2. Add DST transition warnings in admin panel
   - Show alert 1 week before DST
   - Reminder to verify appointments around transition

3. Add timezone display in booking UI
   - Show "Czas lokalny: Warszawa (GMT+2)" in summer
   - Helps international customers understand

---

## Related Documentation

- **Configuration**: `config/app.php` (timezone setting)
- **Code**: `app/Services/AppointmentService.php` (Carbon usage)
- **ADR-005**: Business Hours Configuration
- **Laravel Docs**: [Date & Time](https://laravel.com/docs/12.x/helpers#dates-and-time)
- **Carbon Docs**: [Timezone Handling](https://carbon.nesbot.com/docs/#api-timezone)

---

## Last Updated

**Date**: 2025-10-18
**Reviewer**: Project Coordinator
**Next Review**: Before each DST transition (March and October)
**DST Dates 2025**:
- Spring: March 30, 2025 at 2:00 AM (→ 3:00 AM)
- Fall: October 26, 2025 at 3:00 AM (→ 2:00 AM)
