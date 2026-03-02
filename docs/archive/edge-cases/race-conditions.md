# Edge Case: Race Conditions in Concurrent Bookings

**Status**: Known Risk - Documented
**Impact**: Low (rare occurrence)
**Priority**: P3 (Monitor and address if becomes frequent)

## Problem Description

When multiple customers attempt to book the same time slot simultaneously, race conditions can occur where both bookings appear successful but create double-booking conflicts.

## Race Condition Scenarios

### Scenario 1: Double Booking (Same Slot, Same Staff)

**Timeline**:
```
T0: Customer A loads available slots → sees 10:00 AM available
T1: Customer B loads available slots → sees 10:00 AM available
T2: Customer A submits booking for 10:00 AM → begins processing
T3: Customer B submits booking for 10:00 AM → begins processing
T4: Customer A's booking checks availability → NO CONFLICTS (not committed yet)
T5: Customer B's booking checks availability → NO CONFLICTS (not committed yet)
T6: Customer A's booking COMMITS to database
T7: Customer B's booking COMMITS to database
T8: Both bookings confirmed → CONFLICT!
```

**Root Cause**:
- No transaction isolation between availability check and appointment creation
- Time window between read (check availability) and write (create appointment)
- Multiple staff members means conflict check happens per staff, not globally

**Current Behavior**:
- Both appointments created successfully
- Admin discovers conflict when reviewing schedule
- Manual intervention required to resolve

**Frequency**:
- **Low probability** in practice:
  - Requires exact same slot selection
  - Within narrow time window (< 2 seconds typically)
  - Most car detailing bookings have advance planning (not rush bookings)
- **Estimated occurrence**: < 1% of bookings in typical volume

---

### Scenario 2: Cross-Staff Booking Race

**Setup**:
- 3 staff members available for service
- Customer A and Customer B book same slot
- Different staff assigned, no conflict

**Timeline**:
```
T0: Slot shows available (Staff A, B, C all free)
T1: Customer A books → Staff A assigned
T2: Customer B books → Staff B assigned
T3: Both succeed (no conflict)
```

**Result**: **No issue** - This is correct behavior. Multiple customers can book the same time if different staff available.

---

### Scenario 3: Last Available Slot Race

**Setup**:
- Only Staff A available at 2:00 PM
- Staff B and C busy
- Customer A and B both attempt to book

**Timeline**:
```
T0: Both see 2:00 PM available
T1: Customer A books → Staff A assigned → COMMITS
T2: Customer B books → Checks availability → Staff A now busy → NO AVAILABLE STAFF
T3: Customer B sees error: "Wybrany termin nie jest dostępny"
```

**Result**: **Correct handling** - Second booking fails gracefully with user-friendly error.

**However, race window still exists**:
- If T1 and T2 happen within milliseconds, both might pass availability check before either commits
- Results in double-booking of Staff A

---

## Current Safeguards (Incomplete)

### 1. Database Query Timing
**Code Location**: `AppointmentService::checkStaffAvailability()` (lines 43-63)

**Current Logic**:
```php
$hasConflict = Appointment::query()
    ->where('staff_id', $staffId)
    ->where('appointment_date', $date->format('Y-m-d'))
    ->whereIn('status', ['pending', 'confirmed'])
    ->where(function ($query) use ($startTime, $endTime) {
        // Check for overlaps
    })
    ->exists();
```

**Protection Level**: Partial
- Checks conflicts at query time
- Does NOT lock the row or prevent concurrent writes
- Gap between check and insert allows race condition

### 2. Status-Based Blocking
Both `pending` and `confirmed` statuses block slots (as per ADR-005).

**Protection**: ✅ Prevents showing slot if appointment exists in either status
**Gap**: ⚠️ Does not prevent concurrent creation before status set

### 3. Frontend Delay
Users must navigate through wizard steps (service → date → time → details → confirm).

**Protection**: Minimal - Reduces likelihood but doesn't eliminate race window
**Gap**: Advanced users or scripts could bypass UI and submit rapidly

---

## Proposed Solutions (Not Implemented Yet)

### Solution 1: Database Pessimistic Locking ⭐ Recommended

**Implementation**:
```php
DB::transaction(function () use ($staffId, $serviceId, $date, $start, $end) {
    // Lock staff's appointments for this date
    $conflicts = Appointment::query()
        ->where('staff_id', $staffId)
        ->where('appointment_date', $date->format('Y-m-d'))
        ->whereIn('status', ['pending', 'confirmed'])
        ->lockForUpdate() // Pessimistic lock
        ->where(function ($query) use ($start, $end) {
            // Conflict detection
        })
        ->exists();

    if ($conflicts) {
        throw new BookingConflictException();
    }

    // Create appointment
    Appointment::create([...]);
});
```

**Pros**:
- Guarantees no double-booking within transaction
- Locks only affected rows (not entire table)
- Standard Laravel/database pattern

**Cons**:
- Slight performance impact (locking overhead)
- Potential for deadlocks if not careful with lock order
- Increased database load during high traffic

**Effort**: Low (1-2 hours)
**Priority**: High (should be implemented before production launch)

---

### Solution 2: Unique Database Constraint

**Implementation**:
Add unique constraint to prevent double-booking at database level:

```php
// Migration
Schema::table('appointments', function (Blueprint $table) {
    $table->unique(['staff_id', 'appointment_date', 'start_time', 'end_time'], 'no_double_booking');
});
```

**Pros**:
- Enforced at database level (cannot be bypassed)
- Very fast (index-based check)
- No application code changes needed

**Cons**:
- Only prevents EXACT same slot (not overlaps)
- Example: 10:00-11:00 and 10:30-11:30 would both be allowed (overlap!)
- Does not handle complex overlap scenarios

**Effort**: Very Low (10 minutes)
**Priority**: Low (insufficient protection alone)

**Verdict**: Not sufficient as sole solution, but could be combined with Solution 1.

---

### Solution 3: Redis-Based Slot Reservation

**Implementation**:
Use Redis to "reserve" slot temporarily during booking flow:

```php
// When customer selects slot
$slotKey = "slot:{$staffId}:{$date}:{$startTime}";
$reserved = Redis::set($slotKey, $customerId, 'EX', 300, 'NX'); // 5-minute TTL, only if not exists

if (!$reserved) {
    return response()->json(['error' => 'Slot just taken by another customer']);
}

// Continue with booking...

// After booking confirmed, delete reservation
Redis::del($slotKey);
```

**Pros**:
- Very fast (in-memory check)
- Prevents race condition completely
- Clear expiration (slot released if booking abandoned)

**Cons**:
- Requires Redis (additional infrastructure)
- Adds complexity (reservation cleanup, expiration handling)
- Potential for "stuck" reservations if booking fails

**Effort**: Medium (4-6 hours including Redis setup)
**Priority**: Medium (overkill for current scale)

---

### Solution 4: Optimistic Locking with Version Field

**Implementation**:
Add version field to ServiceAvailability or Appointment:

```php
// Appointments table
Schema::table('appointments', function (Blueprint $table) {
    $table->integer('version')->default(1);
});

// When creating appointment
$appointment = Appointment::create([...]);

// Later update with version check
$updated = Appointment::where('id', $appointment->id)
    ->where('version', $appointment->version)
    ->update(['version' => $appointment->version + 1, ...]);

if ($updated === 0) {
    throw new ConcurrencyException('Appointment was modified by another process');
}
```

**Pros**:
- No locks (better performance than pessimistic)
- Detects conflicts after the fact

**Cons**:
- Does NOT prevent double-booking (detects after creation)
- More useful for updates than inserts
- User sees error AFTER thinking booking succeeded

**Effort**: Low (2-3 hours)
**Priority**: Low (not ideal for insert conflicts)

---

## Recommended Approach

### Immediate (Before Production)
**Implement Solution 1**: Pessimistic Locking in Transaction

**Code Changes**:
1. Wrap `AppointmentService::validateAppointment()` in DB transaction
2. Add `lockForUpdate()` to conflict check query
3. Handle `Illuminate\Database\QueryException` for lock timeout
4. Return user-friendly error: "Ten termin został właśnie zarezerwowany. Proszę wybrać inny."

**Estimated Effort**: 2 hours
**Testing**: Simulate concurrent requests with artillery.io or similar

---

### Future Enhancements (If Needed)

**If booking volume > 100/day and race conditions observed**:
- Add Solution 3 (Redis reservation) for extra protection
- Implement booking queue system (process bookings serially per staff)

**If multiple locations with independent schedules**:
- Lock scope can be per-location (more granular, better performance)

---

## Monitoring and Detection

### Metrics to Track

1. **Double-Booking Count**:
   ```sql
   SELECT staff_id, appointment_date, start_time, COUNT(*) as bookings
   FROM appointments
   WHERE status IN ('pending', 'confirmed')
   GROUP BY staff_id, appointment_date, start_time
   HAVING COUNT(*) > 1;
   ```

2. **Booking Conflicts Created**:
   - Log when `validateAppointment()` returns errors
   - Track frequency of "slot just taken" errors

3. **Concurrent Booking Attempts**:
   - Log timestamps of booking requests
   - Identify clusters (multiple requests within 2 seconds)

### Alerts

**Set up alert if**:
- Double-bookings > 5 per week
- Concurrent booking attempts > 20 per hour
- Database lock timeouts > 10 per day

---

## Testing Strategy

### Manual Test
1. Open two browser sessions (different customers)
2. Both select same slot
3. Click "Confirm" at exact same moment
4. Verify only ONE booking succeeds

### Automated Test
```php
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentBookingTest extends TestCase
{
    /** @test */
    public function prevents_double_booking_with_pessimistic_lock()
    {
        $staff = User::factory()->staff()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        // Simulate two concurrent requests
        $result1 = $this->postJson('/api/appointments', [
            'service_id' => $service->id,
            'date' => '2025-10-20',
            'start_time' => '10:00',
        ]);

        $result2 = $this->postJson('/api/appointments', [
            'service_id' => $service->id,
            'date' => '2025-10-20',
            'start_time' => '10:00',
        ]);

        // One should succeed, one should fail
        $this->assertTrue(
            ($result1->status() === 201 && $result2->status() === 422) ||
            ($result1->status() === 422 && $result2->status() === 201)
        );

        // Only one appointment should exist
        $this->assertEquals(1, Appointment::count());
    }
}
```

---

## Risk Assessment

**Likelihood**: Low (< 1% of bookings)
**Impact**: Medium (requires manual resolution, customer frustration)
**Overall Risk**: Low-Medium

**Mitigation Priority**: Implement Solution 1 before production launch

---

## Related Documentation

- **Code**: `app/Services/AppointmentService.php` (checkStaffAvailability)
- **ADR-004**: Automatic Staff Assignment (affects which staff assigned)
- **Laravel Docs**: [Database Locking](https://laravel.com/docs/12.x/queries#pessimistic-locking)

---

## Last Updated

**Date**: 2025-10-18
**Reviewer**: Project Coordinator
**Implementation Status**: PENDING (Solution 1 recommended before production)
**Next Review**: After implementing pessimistic locking + 1 month production data
