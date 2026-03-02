# ADR-007: Staff Role Enforcement in Appointment System

**Status:** Accepted
**Date:** 2025-10-19
**Deciders:** Development Team

## Context

The appointment system allowed users with 'admin' and 'super-admin' roles to be assigned as staff to appointments, violating business logic that only users with 'staff' role should handle appointments.

### Problem Discovered
- Appointment #9 had `staff_id = 1` (Admin User with 'super-admin' role)
- `AppointmentService` queried `['staff', 'admin', 'super-admin']` roles in 3 methods
- `ServiceAvailabilitySeeder` created records for admin users
- No validation layer prevented invalid assignments
- User model 'name' column caused SQL errors during user creation (NOT NULL without default)

## Decision

Implement a **defense-in-depth validation strategy** with 5 layers:

1. **Service Layer** - Only query 'staff' role in all service methods
2. **Model Observer** - Validate on every appointment create/update
3. **Controller Validation** - Custom `StaffRoleRule` validation rule
4. **Filament Resource** - Validate in Create/Edit pages
5. **Testing** - Comprehensive feature and unit tests

## Consequences

### Positive
- Prevents admin users from being assigned to appointments
- Multiple validation layers ensure data integrity
- Clear error messages in Polish for users
- Data cleanup commands for existing bad data
- Comprehensive test coverage

### Negative
- Requires data migration for existing invalid appointments
- Additional validation overhead on every save
- Breaking change if any code relied on admin assignments

## Implementation

### Files Modified

**Staff Role Enforcement:**
- `app/Services/AppointmentService.php` - 3 role queries fixed (lines 136, 172, 204)
- `database/seeders/ServiceAvailabilitySeeder.php` - Role query fixed
- `app/Http/Controllers/AppointmentController.php` - Added StaffRoleRule validation
- `app/Filament/Resources/AppointmentResource.php` - Correct relationship() implementation
- `app/Filament/Resources/AppointmentResource/Pages/CreateAppointment.php` - Added validation
- `app/Filament/Resources/AppointmentResource/Pages/EditAppointment.php` - Added validation
- `app/Providers/AppServiceProvider.php` - Registered AppointmentObserver
- `database/factories/ServiceFactory.php` - Complete factory definition for tests

**User Model Refactoring:**
- `app/Models/User.php` - Removed 'name' from $fillable, added getFullNameAttribute(), implemented HasName
- `app/Http/Controllers/Auth/RegisterController.php` - Updated for first_name/last_name
- `database/factories/UserFactory.php` - Removed 'name' field
- `database/seeders/DatabaseSeeder.php` - Removed 'name' field
- `resources/views/layouts/app.blade.php` - Use full_name accessor

### Files Created
- `app/Observers/AppointmentObserver.php` - Model-level validation
- `app/Rules/StaffRoleRule.php` - Custom validation rule
- `app/Console/Commands/AuditInvalidStaffAssignments.php` - Audit command
- `app/Console/Commands/FixInvalidStaffAssignments.php` - Fix command with --dry-run
- `tests/Feature/AppointmentStaffValidationTest.php` - Feature tests (4 tests)
- `tests/Unit/Services/AppointmentServiceTest.php` - Unit tests
- `database/migrations/2025_10_19_162113_remove_name_column_from_users_table.php` - Remove 'name' column

### Data Cleanup

**Executed Actions:**
1. Audited appointments - found 1 invalid assignment (appointment #9)
2. Manually reassigned appointment #9: Admin User → Mistrz polerki
3. Removed 15 ServiceAvailability records for non-staff users
4. Reseeded ServiceAvailability with correct role query
5. Final audit confirmed: "No invalid staff assignments found!"

**Available Commands:**
```bash
# Audit existing appointments
php artisan appointments:audit-staff

# Preview fixes (dry-run)
php artisan appointments:fix-staff --dry-run

# Apply fixes automatically
php artisan appointments:fix-staff
```

## Alternatives Considered

1. **Single validation layer** - Rejected: not robust enough
2. **Soft validation with warnings** - Rejected: business rule violation must be blocked
3. **Allow admin override** - Rejected: violates business requirements

## Notes

- All error messages in Polish per application standards
- Observer uses `saveQuietly()` bypass during data cleanup
- Tests use `RefreshDatabase` for isolation
- Future: Consider role hierarchy system if business rules evolve

## Related Changes

### User Model 'name' Column Removal

As part of this implementation, the User model was refactored to complete the migration from single 'name' field to first_name/last_name structure:

**Problem:** The 'name' column was NOT NULL without default value, causing SQL errors when creating users in admin panel.

**Solution:**
- Created migration `2025_10_19_162113_remove_name_column_from_users_table.php`
- Physically removed 'name' column from database
- Updated all code references to use first_name/last_name
- Added `getFullNameAttribute()` accessor for backward compatibility
- Implemented Filament `HasName` interface with `getFilamentName()` method

**Files Modified:**
- User.php, RegisterController.php, UserFactory.php, DatabaseSeeder.php, app.blade.php

**Test Coverage:**
- All existing tests updated
- 4 new feature tests for staff validation
- 1 new unit test for AppointmentService
