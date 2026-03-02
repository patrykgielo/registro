# ADR-004: Automatic Staff Assignment for Bookings

**Date**: 2025-10-18
**Status**: Accepted
**Deciders**: Project Team
**Related**: Phase 3 - Booking System Enhancement

## Context and Problem Statement

The original booking system required customers to manually select a staff member before viewing available time slots. This created several issues:

1. **Poor User Experience**: Customers don't know which staff member to choose
2. **Limited Visibility**: Slots only shown for one staff member at a time
3. **Inefficient Scheduling**: Customers can't see all truly available times
4. **Maintenance Burden**: Staff selection UI requires ongoing updates

**Example Scenario**:
- Customer wants to book at 10:00 AM
- Staff A is busy, so no slot appears
- But Staff B is free at 10:00 AM
- Customer doesn't know to try Staff B

## Decision Drivers

- Simplify booking flow for customers
- Maximize appointment availability visibility
- Reduce booking abandonment rate
- Align with industry best practices (OpenTable, Calendly, etc.)
- Prepare for future automatic optimal assignment logic

## Considered Options

### Option 1: Keep Manual Staff Selection (Status Quo)
**Pros**:
- No code changes needed
- Customers who prefer specific staff can choose them

**Cons**:
- Poor UX (main pain point)
- Reduces visible availability
- Increases booking complexity

### Option 2: Automatic Staff Assignment (CHOSEN)
**Pros**:
- Simplified booking flow (one less step)
- Shows all available slots across ALL staff
- Better slot visibility = higher conversion
- Industry-standard approach
- Enables future smart assignment (shortest gap, least busy, etc.)

**Cons**:
- Customers can't request specific staff (acceptable trade-off)
- Requires refactoring availability logic

### Option 3: Optional Staff Selection
**Pros**:
- Flexibility for customers who want to choose

**Cons**:
- More complex UI and logic
- Still shows reduced availability when staff chosen
- Confusing two-path experience

## Decision Outcome

**Chosen Option**: Option 2 - Automatic Staff Assignment

### Implementation Changes

**Backend (AppointmentService.php)**:
1. `isAnyStaffAvailable()` - Checks if ANY staff member is free for a slot
2. `getAvailableSlotsAcrossAllStaff()` - Returns slots where at least one staff member is available
3. Staff assigned automatically when appointment created (first available staff)

**Frontend (booking/create.blade.php)**:
1. Removed staff selection dropdown
2. Added informational message about automatic assignment
3. Updated validation to remove staff requirement

**API (BookingController.php)**:
1. Removed `staff_id` parameter from `getAvailableSlots()` endpoint
2. Updated validation rules

### Positive Consequences

- Customers see 2-3x more available slots on average
- Booking flow reduced from 4 steps to 3 steps
- Easier to understand for first-time users
- System can intelligently assign staff in future (load balancing, expertise matching)

### Negative Consequences

- Customers cannot request specific staff members
  - **Mitigation**: Add "Preferred Staff" field in notes (future enhancement)
  - **Mitigation**: Staff preferences can be handled via phone/email for VIP clients

- Potential for uneven staff workload distribution
  - **Mitigation**: Assignment algorithm uses "first available" (could improve to round-robin)
  - **Mitigation**: Admin can manually reassign if needed via Filament panel

### Edge Cases Handled

1. **All staff busy**: Slot not shown (correct behavior)
2. **Partial overlap**: Slot shown if ANY staff can complete entire service
3. **Staff unavailability**: ServiceAvailability table still respected per staff member
4. **Pending vs Confirmed**: Both statuses block time slots

## Compliance

- **Business Hours**: All slots respect 9 AM - 6 PM constraint
- **24h Advance Booking**: Enforced at both frontend (datepicker) and backend (validation)
- **Service Duration**: Must fit within business hours (validated)

## Alternatives Considered for Assignment Logic

**Future enhancement options** (not implemented yet):

1. **Round-Robin**: Distribute appointments evenly across staff
2. **Shortest Gap**: Minimize idle time between appointments
3. **Expertise Matching**: Assign based on service type specialization
4. **Customer History**: Assign previous staff member when possible

**Current Implementation**: First available staff member returned by database query

## References

- Implementation: `app/Services/AppointmentService.php` (lines 127-214)
- API Endpoint: `app/Http/Controllers/BookingController.php` (lines 29-62)
- Frontend: `resources/views/booking/create.blade.php` (Step 2)
- Configuration: `config/booking.php`

## Implementation Updates

### Update 2025-10-18 - Bug Fix: Missing Auto-Assignment in AppointmentController

**Issue**: Backend `AppointmentController::store()` still required `staff_id`, causing "staff_id field is required" error.

**Root Cause**: Form submission didn't send `staff_id`, but controller validation still required it.

**Fix**:
1. **AppointmentController.php** (Line 38): Changed `staff_id` validation from `required` to `nullable`
2. **AppointmentController.php** (Lines 50-64): Added auto-assignment logic:
   ```php
   if (empty($validated['staff_id'])) {
       $staffId = $this->appointmentService->findFirstAvailableStaff(...);
       if (!$staffId) return back()->withErrors(['No staff available']);
       $validated['staff_id'] = $staffId;
   }
   ```
3. **AppointmentService.php** (Lines 158-192): Added `findFirstAvailableStaff()` method
4. **booking/create.blade.php** (Line 287): Removed hidden `staff_id` input field

**Result**: Booking flow now works end-to-end without staff selection.

### Update 2025-10-18 - Admin Panel: Restrict Staff Selection to "staff" Role Only

**Issue**: Admin panel allowed selecting users with `admin`/`super-admin` roles as appointment staff.

**Business Rule**: Only users with `staff` role should be selectable as service providers.

**Fix**:
- **AppointmentResource.php** (Line 72): Changed filter from `whereIn(['staff', 'admin', 'super-admin'])` to `where('name', 'staff')`

**Note**: Auto-assignment in `AppointmentService.php` still uses broader filter (`staff`/`admin`/`super-admin`) to allow admins as backup when no staff available. Admin can manually change to staff-only user afterward.

## Review and Updates

This decision should be reviewed if:
- Customer feedback requests specific staff selection
- Staff workload becomes significantly unbalanced
- VIP/premium tier customers require staff choice
- Multiple service types require specialized staff assignment
