# Edge Case: Availability Gaps and Slot Generation

**Status**: Handled
**Impact**: Low-Medium
**Related**: Phase 3 - Automatic Staff Assignment

## Problem Description

When generating available time slots across multiple staff members, gaps in staff availability can create complex scenarios where slots appear available but cannot be fully serviced, or where optimal slots are hidden.

## Edge Case Scenarios

### Scenario 1: Service Spans Staff Availability Boundary

**Setup**:
- Staff A available: 9:00 AM - 1:00 PM
- Staff B available: 2:00 PM - 6:00 PM
- Service duration: 3 hours
- Customer requests: 12:00 PM slot

**Problem**:
- Service would need to run 12:00 PM - 3:00 PM
- Staff A ends at 1:00 PM (can't complete service)
- Staff B starts at 2:00 PM (misses beginning)
- **Result**: No staff can complete the full service

**Current Behavior**:
- Slot is **NOT SHOWN** (correct behavior)
- `isAnyStaffAvailable()` checks if entire service duration fits within staff availability
- Query logic in `checkStaffAvailability()` validates `end_time >= $endTime`

**Code Reference**:
```php
// ServiceAvailability check in AppointmentService.php (lines 29-34)
$q->whereTime('start_time', '<=', $startTime->format('H:i:s'))
  ->whereTime('end_time', '>=', $endTime->format('H:i:s'));
```

**Validation**: This is the correct behavior. Service must be completed by one staff member entirely.

---

### Scenario 2: Slot Available Only for One Staff Member

**Setup**:
- Staff A available: 9:00 AM - 6:00 PM
- Staff B available: 9:00 AM - 12:00 PM
- Service duration: 2 hours
- Time slot: 11:00 AM - 1:00 PM

**Analysis**:
- Staff A: Can service from 11:00 AM - 1:00 PM (within hours)
- Staff B: Available ends at 12:00 PM (cannot complete)

**Current Behavior**:
- Slot IS SHOWN (correct)
- `isAnyStaffAvailable()` returns `true` because Staff A can service it
- Staff A will be assigned when customer books

**Validation**: Correct. As long as ONE staff member can complete the service, the slot is available.

---

### Scenario 3: Gap Between Appointments

**Setup**:
- Staff A schedule:
  - Appointment 1: 9:00 AM - 11:00 AM
  - Free: 11:00 AM - 2:00 PM
  - Appointment 2: 2:00 PM - 4:00 PM
- Service duration: 4 hours
- Requested slot: 11:00 AM

**Problem**:
- 4-hour service would run 11:00 AM - 3:00 PM
- Conflicts with Appointment 2 starting at 2:00 PM

**Current Behavior**:
- Slot IS NOT SHOWN (correct)
- Conflict detection in `checkStaffAvailability()` finds overlap
- Query checks for overlapping appointments (lines 43-63)

**Code Reference**:
```php
// Appointment conflict check
$hasConflict = Appointment::query()
    ->where('staff_id', $staffId)
    ->where('appointment_date', $date->format('Y-m-d'))
    ->whereIn('status', ['pending', 'confirmed'])
    ->where(function ($query) use ($startTime, $endTime) {
        // Multiple overlap scenarios checked
    })
    ->exists();
```

**Validation**: Correct. System properly identifies conflicts.

---

### Scenario 4: Fragmented Availability Across Day

**Setup**:
- Staff A: 9:00 AM - 11:00 AM (appointment booked)
- Staff A: 11:00 AM - 2:00 PM (FREE)
- Staff A: 2:00 PM - 5:00 PM (appointment booked)
- Staff B: Fully booked entire day
- Service duration: 2 hours

**Expected Slots**:
- 11:00 AM - 1:00 PM (fits in Staff A's gap)
- No other slots (all other times have conflicts)

**Current Behavior**:
- System generates slots at 15-minute intervals
- Checks: 11:00, 11:15, 11:30, 11:45, 12:00, 12:15, ...
- For each slot, verifies no conflicts exist
- Only 11:00 AM and 11:15 AM would show (2h service needs to end by 1:15 PM latest)

**Performance Concern**:
- If 100 slots generated per day × 5 staff members = 500 availability checks
- **Mitigation**: Checks are database queries (fast), cached per request

---

### Scenario 5: Last Slot of Day (Business Hours Boundary)

**Setup**:
- Business hours: 9:00 AM - 6:00 PM
- Service duration: 3 hours
- Staff A available all day

**Question**: Should 3:15 PM be shown as available slot?
- Service would run: 3:15 PM - 6:15 PM
- Exceeds business hours end (6:00 PM)

**Current Behavior**:
- Slot IS NOT SHOWN (correct)
- `isWithinBusinessHours()` validates entire service duration
- Check: `$endTime->lte($businessEnd)` (line 225)

**Code Reference**:
```php
public function isWithinBusinessHours(Carbon $startTime, Carbon $endTime): bool
{
    $businessHours = config('booking.business_hours');
    $businessStart = Carbon::parse($startTime->format('Y-m-d') . ' ' . $businessHours['start']);
    $businessEnd = Carbon::parse($startTime->format('Y-m-d') . ' ' . $businessHours['end']);

    return $startTime->gte($businessStart) && $endTime->lte($businessEnd);
}
```

**Validation**: Correct. Last bookable slot for 3-hour service is 3:00 PM.

---

### Scenario 6: Timezone Edge Cases

**Setup**:
- Server timezone: UTC
- App timezone: Europe/Warsaw (UTC+1 or UTC+2 depending on DST)
- Customer books at 5:30 PM on March 25 (DST change)

**Potential Issues**:
- Mismatched timezone calculations
- 24-hour advance booking check might be off by 1 hour
- Stored times in wrong timezone

**Current Handling**:
- **App timezone set**: `config/app.php` → `timezone` = `Europe/Warsaw`
- **Carbon uses app timezone**: All Carbon instances respect app config
- **Database stores**: Times as time strings (not timestamps with timezone)

**Validation**:
- ✅ Consistent timezone across all operations
- ✅ DST handled automatically by Carbon
- ⚠️ **Risk**: If server timezone != app timezone, manual conversions needed

**Recommendation**:
- Keep server timezone as UTC (best practice)
- Always use Carbon with app timezone (already done)
- Never use PHP's native `date()` or `strtotime()` (they use server timezone)

---

## System Design Decisions

### 1. Slot Interval (15 minutes)

**Rationale**:
- Balance between customer flexibility and system performance
- Finer granularity (5 min) = more slots = more checks = slower
- Coarser granularity (30 min) = fewer options for customers

**Configurable**: `config('booking.slot_interval_minutes', 15)`

### 2. "Any Staff Available" Logic

**Why not show ALL slots if NO staff available?**
- **Answer**: Would be misleading. Customer sees slot, books it, then gets rejection.
- **Correct behavior**: Only show slots that can actually be fulfilled.

**Why not show which staff member is assigned?**
- **Answer**: Simplified UX (see ADR-004). Customer doesn't need to know.
- **Future enhancement**: Could reveal assigned staff in confirmation email.

### 3. Slot Generation Algorithm

**Current**: Exhaustive iteration through all possible slots
**Alternative**: Smart gap detection (only check slots in known gaps)
**Trade-off**: Current approach is simpler, performance acceptable for single-day queries

**Performance Metrics**:
- Average day: 9 hours × 4 slots/hour = 36 base slots
- Per slot: 1-5 database queries (staff availability + conflict check)
- **Total**: ~180 queries per booking request (acceptable with indexes)

**Optimization Opportunity** (future):
- Cache staff availability per day
- Pre-calculate slots on ServiceAvailability change
- Use Redis for slot caching (TTL: 15 minutes)

---

## Testing Recommendations

### Unit Tests
1. Test `isAnyStaffAvailable()` with various availability patterns
2. Test `isWithinBusinessHours()` with boundary conditions
3. Test conflict detection with overlapping appointments

### Integration Tests
1. Book appointment, verify slot disappears for all users
2. Cancel appointment, verify slot reappears
3. Create ServiceAvailability gap, verify correct slot generation

### Edge Case Tests
```php
// Test: Service spans staff availability boundary
test('service requiring 3 hours does not show 12pm slot when staff ends at 1pm')

// Test: Last slot respects business hours
test('3 hour service last slot is 3pm when business closes at 6pm')

// Test: Gap between appointments
test('2 hour service fits in 3 hour gap between appointments')

// Test: Timezone consistency
test('24 hour advance booking calculated correctly in Europe/Warsaw timezone')
```

---

## Known Limitations

1. **No buffer time between appointments**
   - Staff immediately available after previous appointment ends
   - **Risk**: No cleanup/prep time
   - **Mitigation**: Admin can configure ServiceAvailability with built-in buffers

2. **No travel time consideration**
   - Assumes all appointments at same location
   - **Future**: Add location field, calculate travel time

3. **No priority/VIP slot reservation**
   - First-come, first-served
   - **Future**: Reserve certain slots for VIP customers

---

## Related Documentation

- **Code**: `app/Services/AppointmentService.php` (getAvailableSlotsAcrossAllStaff)
- **Configuration**: `config/booking.php`
- **ADR-004**: Automatic Staff Assignment
- **ADR-005**: Business Hours Configuration

---

## Last Updated

**Date**: 2025-10-18
**Reviewer**: Project Coordinator
**Next Review**: After first production month (analyze slot patterns)
