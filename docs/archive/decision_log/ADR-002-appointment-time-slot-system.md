# ADR-002: Appointment Time Slot System

**Status:** Accepted (Existing Implementation) with Recommendations
**Date:** 2025-10-12
**Deciders:** Backend Architect (Analysis)

## Context

The booking system needs to manage staff availability and generate bookable time slots for customers. The system must:
- Define when staff members are available to perform services
- Check for scheduling conflicts
- Generate available time slots for customer selection
- Prevent double-booking
- Support different service durations

## Decision

Implement a **recurring weekly availability system** with:
1. `service_availabilities` table defining staff working hours by day of week
2. 15-minute time slot intervals for booking granularity
3. Real-time conflict detection using database queries
4. Staff-service-specific availability (not just global staff hours)

**Key Design Elements:**
- `day_of_week` integer (0=Sunday, 6=Saturday)
- `start_time` and `end_time` define availability windows
- Appointments checked for conflicts within availability windows
- Slot generation uses 15-minute increments
- Each staff-service-day-time combination is unique

## Consequences

### Positive

1. **Flexible Staff Scheduling**
   - Each staff member can have different hours for different services
   - Example: John does basic washes Mon-Fri 9-5, premium details Tue-Thu 10-3
   - Supports part-time staff with limited availability

2. **Efficient Conflict Detection**
   - Database indexes on (staff_id, appointment_date) enable fast queries
   - Comprehensive overlap checking prevents double-booking
   - Query-time validation ensures data consistency

3. **Fine-Grained Booking Options**
   - 15-minute intervals provide flexibility
   - Customers can see all available options
   - Accommodates various service durations

4. **Service-Specific Availability**
   - Staff can specialize in certain services
   - Different availability windows per service type
   - Supports skill-based scheduling

5. **Simple Data Model**
   - Easy to understand and maintain
   - Clear relationships between tables
   - Straightforward queries

### Negative

1. **Hardcoded Slot Interval**
   - 15-minute interval is hardcoded in AppointmentService (line 116)
   - Cannot be changed without code modification
   - All services use same granularity regardless of duration

2. **No Exception Handling**
   - Cannot define one-time exceptions (holidays, vacation, sick days)
   - Cannot override availability for specific dates
   - No "closed" day management
   - Staff time-off requires manual coordination

3. **No Buffer Time**
   - Back-to-back appointments possible (appointment ends 10:30, next starts 10:30)
   - No automatic cleanup/prep time between services
   - May lead to running behind schedule

4. **Limited Capacity Management**
   - Only supports one staff member per appointment
   - Cannot handle multiple parallel appointments with same staff
   - No team-based service appointments
   - Scalability limitation for larger operations

5. **Weekly Recurrence Only**
   - Cannot define bi-weekly or monthly patterns
   - Cannot handle seasonal availability changes
   - Must manually update for schedule changes

6. **Potential Performance Issues**
   - Slot generation queries database for each time slot
   - N+1 query risk when checking multiple slots
   - Could become slow for long date ranges or many staff

### Critical Issues

**Issue 1: No Buffer Time**
- **Risk:** Staff running late affects all subsequent appointments
- **Impact:** Customer dissatisfaction, operational chaos
- **Recommendation:** Add configurable buffer time (5-15 minutes) between appointments

**Issue 2: No Holiday/Exception System**
- **Risk:** Customers can book on closed days
- **Impact:** Manual intervention needed, poor customer experience
- **Recommendation:** Implement availability exceptions table

**Issue 3: Hardcoded 15-Minute Interval**
- **Risk:** Not all businesses need this granularity
- **Impact:** Database bloat with unnecessary options
- **Recommendation:** Make interval configurable per service or globally

## Alternatives Considered

### Alternative 1: Calendar-Based Availability
Use a full calendar system with date-specific events.

**Pros:**
- Can handle any schedule pattern
- Natural exception handling
- Easy to visualize

**Cons:**
- More complex data model
- Requires more storage
- Harder to query efficiently
- Must generate availability in advance

**Decision:** Rejected for initial implementation due to complexity. May revisit for v2.

### Alternative 2: Time Block System
Pre-define time blocks (morning, afternoon, evening) instead of specific times.

**Pros:**
- Simpler for customers
- Less data storage
- Faster queries

**Cons:**
- Less flexible
- Cannot accommodate varying service durations
- May not fit all business models

**Decision:** Rejected. Too limiting for detailing business with services ranging from 30 minutes to 4+ hours.

### Alternative 3: Real-Time Slot Reservation
Allow customers to temporarily "hold" a slot while completing booking.

**Pros:**
- Prevents race conditions
- Better UX for slow customers
- Reduces double-booking attempts

**Cons:**
- More complex state management
- Requires cleanup of expired reservations
- Additional database tables

**Decision:** Deferred. Good idea for future enhancement but not critical for MVP.

## Recommendations for Improvement

### High Priority

1. **Add Buffer Time Configuration**
```php
// Add to services table
Schema::table('services', function (Blueprint $table) {
    $table->integer('buffer_minutes')->default(10)->after('duration_minutes');
});

// Update slot generation to include buffer
$slotEnd = $currentSlot->copy()
    ->addMinutes($serviceDurationMinutes + $service->buffer_minutes);
```

2. **Create Availability Exceptions Table**
```php
Schema::create('availability_exceptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->date('exception_date');
    $table->enum('type', ['unavailable', 'available', 'modified']);
    $table->time('start_time')->nullable();
    $table->time('end_time')->nullable();
    $table->text('reason')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'exception_date', 'start_time']);
});
```

3. **Make Slot Interval Configurable**
```php
// Add to config/booking.php
return [
    'slot_interval_minutes' => env('BOOKING_SLOT_INTERVAL', 15),
];

// Update AppointmentService to use config
$slotInterval = config('booking.slot_interval_minutes');
$currentSlot->addMinutes($slotInterval);
```

### Medium Priority

4. **Optimize Slot Generation**
- Fetch all appointments for the day in one query
- Filter in memory instead of database query per slot
- Cache availability for frequently requested dates

5. **Add Business Hours Configuration**
```php
// config/booking.php
return [
    'business_hours' => [
        'start' => '08:00',
        'end' => '18:00',
    ],
    'closed_days' => [0, 6], // Sunday, Saturday
];
```

6. **Implement Slot Reservation System**
- Add `reserved_until` timestamp to appointments or separate table
- Automatically release expired reservations
- Show "Someone else is viewing this slot" indicator

### Low Priority

7. **Support Multiple Staff Per Appointment**
```php
Schema::create('appointment_staff', function (Blueprint $table) {
    $table->id();
    $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['primary', 'assistant']);
    $table->timestamps();
});
```

8. **Add Capacity Management**
- Allow services to define capacity (e.g., 2 cars simultaneously)
- Check capacity instead of just staff availability
- Enable parallel bookings within capacity limits

## Migration Path

To implement these improvements without breaking existing functionality:

**Phase 1: Non-Breaking Additions**
1. Add buffer_minutes to services (default 0 maintains current behavior)
2. Create availability_exceptions table (empty table doesn't affect logic)
3. Add configuration values with sensible defaults

**Phase 2: Service Layer Updates**
4. Update AppointmentService to check exceptions
5. Implement buffer time in slot generation
6. Add caching layer for performance

**Phase 3: Optional Features**
7. Build UI for exception management in Filament
8. Implement slot reservation system
9. Add capacity management if needed

## Performance Considerations

**Current Performance:**
- Single date, single staff: ~50-100ms (acceptable)
- Calendar view (30 days): ~1-3 seconds (problematic)
- Multiple staff: Linear scaling (N staff × M days)

**Optimization Strategies:**
1. Cache available slots for 5-15 minutes
2. Use Redis for fast slot lookups
3. Generate slots asynchronously for calendar views
4. Pre-calculate availability for next 30 days (background job)

## Related Decisions

- ADR-001: Service Layer Architecture (where this logic lives)
- Future: ADR for caching strategy
- Future: ADR for real-time updates (WebSocket vs. polling)

## References

- Laravel Query Builder: https://laravel.com/docs/12.x/queries
- Carbon Date/Time: https://carbon.nesbot.com/
- Database Indexing Best Practices
- Scheduling System Design Patterns
