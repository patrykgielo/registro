# Edge Case: Multi-Day Services

**Status**: Documented Limitation
**Impact**: Medium
**Related**: Phase 2 - Duration UI, Service Model

## Problem Description

Car detailing services occasionally require multiple days (e.g., full vehicle restoration, ceramic coating with curing time). The current system limits services to a maximum of 9 hours (540 minutes), which represents a single working day.

### Current Limitation

**Maximum Service Duration**: 540 minutes (9 hours)
**Configured In**: `config/booking.php` → `max_service_duration_minutes`
**Enforced By**:
- Filament form validation (ServiceResource.php)
- Duration input component (max hours = 9)

## Why This Limitation Exists

1. **Complexity of Multi-Day Scheduling**:
   - Requires checking availability across multiple consecutive days
   - Staff availability patterns may differ per day (Monday vs. Tuesday)
   - Business hours validation becomes multi-day calculation

2. **Appointment Table Schema**:
   - `appointment_date` is a single date field
   - `start_time` and `end_time` are time-only fields (not datetime)
   - No concept of "appointment spans multiple days"

3. **UI Complexity**:
   - Calendar view would need multi-day blocking
   - Customer booking flow would need "end date" selector
   - Conflict detection algorithm significantly more complex

## Current Behavior

**Admin Panel (Filament)**:
- Duration input shows: Days (disabled, max 0), Hours (max 9), Minutes (max 59)
- Helper text: "Usługi wielodniowe nie są obsługiwane"
- Attempting to enter days > 0 is blocked

**Customer Booking Flow**:
- Services are displayed with duration in hours + minutes
- Single date picker (no end date)
- Time slot calculation assumes service completes same day

**Validation**:
```php
// If total minutes > 540, capped at 540
$maxDuration = config('booking.max_service_duration_minutes', 540);
if ($totalMinutes > $maxDuration) {
    $totalMinutes = $maxDuration;
}
```

## Example Scenarios

### Scenario 1: Full Vehicle Restoration (5 days)
**Service Requirement**: 3 days of paint correction, 1 day ceramic coating, 1 day interior detailing

**Current Handling**:
- NOT SUPPORTED
- Admin must create 5 separate services (Day 1, Day 2, Day 3, etc.)
- Customer must book 5 separate appointments
- Manual coordination required to ensure consecutive dates

**Workaround**:
1. Create service "Vehicle Restoration - Initial Consultation" (1 hour)
2. Admin manually schedules follow-up days via phone/email
3. Admin creates appointments directly in Filament panel
4. Mark as "blocked" in calendar to prevent conflicts

### Scenario 2: Ceramic Coating with Curing (2 days)
**Service Requirement**: Day 1 = Application (4 hours), Day 2 = Inspection (1 hour)

**Current Handling**:
- Create two services: "Ceramic Coating Application" and "Ceramic Coating Inspection"
- Customer books both appointments
- Risk: Customer might book only one, or forget second appointment

**Recommended Workaround**:
- Use appointment notes: "Customer will return tomorrow for inspection"
- Create internal-only follow-up appointment
- Send reminder notification day before inspection

## Impact on Business

**Low-Frequency Issue**:
- Most car detailing services complete in 1-4 hours
- Multi-day services represent < 5% of bookings
- Workarounds are acceptable for this volume

**Acceptable for MVP**:
- Paradocks is for daily operations (quick services)
- Complex projects can be handled offline
- Future enhancement if demand increases

## Future Solutions (Not Implemented)

### Option 1: Multi-Day Appointment Type
**Changes Required**:
- Add `end_date` field to appointments table
- Update availability algorithm to check date range
- Modify UI to show blocked multi-day periods
- Add "multi-day" checkbox in service creation

**Effort**: High (2-3 weeks)
**Priority**: Low

### Option 2: Service Packages/Bundles
**Changes Required**:
- Create ServicePackage model (has many Services)
- Book entire package as single transaction
- Generate N appointments (one per day)
- Link appointments as "package group"

**Effort**: Medium (1-2 weeks)
**Priority**: Medium

### Option 3: Increase Max Duration to 16 Hours
**Changes Required**:
- Change `max_service_duration_minutes` to 960 (16 hours)
- Allow "overnight" services (6 PM → 10 AM next day)
- Add special validation for cross-midnight services

**Effort**: Low (2 days)
**Priority**: Low
**Risk**: Complexity in time calculations, edge cases with midnight crossing

## Recommended Approach

**For Now**:
- Keep 540-minute (9-hour) limit
- Document workaround in admin training
- Monitor frequency of multi-day requests

**If Demand Increases**:
1. Implement Option 2 (Service Packages) first
2. Provides best UX for multi-step services
3. Maintains single-day appointment model
4. Links related appointments logically

## Testing Considerations

**What to Test**:
1. Creating service with duration > 540 minutes
   - **Expected**: Capped at 540 minutes
2. Attempting to set days > 0 in Filament form
   - **Expected**: Field disabled, cannot enter value
3. Booking 9-hour service near end of business day
   - **Expected**: Slot not available (would exceed 6 PM)

## Related Documentation

- **Configuration**: `config/booking.php` (max_service_duration_minutes)
- **Service Model**: `app/Models/Service.php` (formatted_duration accessor)
- **Filament Form**: `app/Filament/Resources/ServiceResource.php`
- **ADR-005**: Business Hours Configuration

## References

- Similar systems that handle multi-day:
  - AirBnB: Check-in / check-out dates
  - Hotel booking: Date range selection
  - Project management: Start date + duration

- Car detailing industry:
  - Most services: 1-4 hours
  - Premium services: 6-8 hours
  - Restoration projects: 3-10 days (rare)

## Last Updated

**Date**: 2025-10-18
**Reviewer**: Project Coordinator
**Next Review**: When multi-day service requests exceed 5% of total bookings
