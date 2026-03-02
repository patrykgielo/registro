# ADR-005: Centralized Business Hours and Booking Rules Configuration

**Date**: 2025-10-18
**Status**: Accepted
**Deciders**: Project Team
**Related**: Phase 1 - Configuration Foundation

## Context and Problem Statement

The booking system requires multiple time-based business rules:
- Operating hours (9 AM - 6 PM)
- Minimum advance booking time (24 hours)
- Cancellation policy (24 hours before appointment)
- Timezone handling (Europe/Warsaw)
- Slot interval granularity (15 minutes)
- Maximum service duration (9 hours / single work day)

**Previous State**:
- Business hours were hardcoded in multiple files
- Validation logic scattered across controllers and services
- No single source of truth for time-based rules
- Difficult to change policies without code changes

**Problem**: How do we make these rules configurable, maintainable, and consistent across the application?

## Decision Drivers

- **Single Source of Truth**: One place to update all time-based rules
- **Environment Flexibility**: Different rules for dev/staging/production
- **Business Agility**: Non-developers can adjust rules via .env
- **Consistency**: Same rules enforced in frontend, backend, and validation
- **Documentation**: Clear understanding of all booking constraints

## Considered Options

### Option 1: Keep Rules Hardcoded (Status Quo)
**Pros**:
- No refactoring needed
- Fast execution (no config lookups)

**Cons**:
- Code changes required for policy updates
- Inconsistency risk (rules in multiple places)
- Cannot vary by environment
- Poor maintainability

### Option 2: Database Configuration Table
**Pros**:
- GUI-based editing via admin panel
- Audit trail of changes
- Per-user role-based rules possible

**Cons**:
- Overkill for simple time-based rules
- Slower (database queries)
- More complex caching strategy needed
- Harder to version control

### Option 3: Centralized Config File + .env (CHOSEN)
**Pros**:
- Laravel-native approach (config/booking.php)
- Fast (cached in production)
- Environment-specific via .env
- Version controlled with code
- Clear documentation in config file

**Cons**:
- Requires application rebuild for changes (acceptable)
- No GUI editing (acceptable for admin-level rules)

## Decision Outcome

**Chosen Option**: Option 3 - Centralized Config File + .env Variables

### Implementation

**Created Files**:
1. `config/booking.php` - All booking-related configuration
2. Updated `.env.example` - Default values with documentation

### Configuration Structure

```php
// config/booking.php
return [
    'business_hours' => [
        'start' => env('BOOKING_BUSINESS_HOURS_START', '09:00'),
        'end' => env('BOOKING_BUSINESS_HOURS_END', '18:00'),
    ],
    'advance_booking_hours' => env('BOOKING_ADVANCE_HOURS', 24),
    'cancellation_hours' => env('BOOKING_CANCELLATION_HOURS', 24),
    'timezone' => env('BOOKING_TIMEZONE', 'Europe/Warsaw'),
    'slot_interval_minutes' => env('BOOKING_SLOT_INTERVAL', 15),
    'max_service_duration_minutes' => env('BOOKING_MAX_DURATION', 540),
    'blocking_statuses' => ['pending', 'confirmed'],
];
```

### Environment Variables (.env)

```bash
# Application timezone (must match booking timezone)
APP_TIMEZONE=Europe/Warsaw

# Booking system configuration
BOOKING_BUSINESS_HOURS_START=09:00
BOOKING_BUSINESS_HOURS_END=18:00
BOOKING_ADVANCE_HOURS=24
BOOKING_CANCELLATION_HOURS=24
BOOKING_TIMEZONE=Europe/Warsaw
BOOKING_SLOT_INTERVAL=15
BOOKING_MAX_DURATION=540
```

### Usage Patterns

**Backend Services**:
```php
// AppointmentService.php
$businessHours = config('booking.business_hours');
$advanceHours = config('booking.advance_booking_hours', 24);

if (!$this->meetsAdvanceBookingRequirement($appointmentDateTime)) {
    $errors[] = 'Rezerwacja musi być dokonana co najmniej 24 godziny przed terminem wizyty.';
}
```

**Models**:
```php
// Appointment.php - getCanBeCancelledAttribute()
$cancellationHours = config('booking.cancellation_hours', 24);
$cancellationDeadline = $appointmentDateTime->subHours($cancellationHours);
```

**Frontend**:
- Datepicker min date calculated based on `BOOKING_ADVANCE_HOURS`
- Error messages reference config values

### Positive Consequences

1. **Consistency**: All components use same config values
2. **Flexibility**: Easy to change rules per environment:
   - Development: 1-hour advance booking for testing
   - Staging: 12-hour advance booking
   - Production: 24-hour advance booking

3. **Documentation**: Config file serves as living documentation
4. **Performance**: Config cached in production (`php artisan config:cache`)
5. **Version Control**: Changes tracked in git
6. **Type Safety**: PHP type hints ensure valid values

### Negative Consequences

1. **Deployment Required**: Changing rules requires cache clear
   - **Mitigation**: Document cache clear process: `php artisan config:cache`

2. **No Runtime Changes**: Cannot change rules without deployment
   - **Mitigation**: Acceptable for business rules that rarely change
   - **Future**: Move to database if frequent changes needed

3. **Validation Needed**: Invalid .env values can break system
   - **Mitigation**: Add validation in AppServiceProvider boot method
   - **Future**: Create `php artisan booking:validate-config` command

## Configuration Validation Rules

**Recommended Validations** (future enhancement):
```php
// In AppServiceProvider or custom validator
- business_hours.start must be < business_hours.end
- business_hours must be valid HH:MM format
- advance_booking_hours must be > 0
- cancellation_hours must be > 0
- slot_interval_minutes must be divisor of 60 (15, 20, 30)
- max_service_duration_minutes <= business day duration
- timezone must be valid PHP timezone identifier
```

## Business Rules Enforced

| Rule | Config Key | Default | Enforced In |
|------|------------|---------|-------------|
| Operating Hours | `business_hours` | 09:00-18:00 | AppointmentService, Frontend |
| Advance Booking | `advance_booking_hours` | 24h | AppointmentService, BookingController, Frontend |
| Cancellation Deadline | `cancellation_hours` | 24h | Appointment Model |
| Timezone | `timezone` | Europe/Warsaw | app.php, Carbon instances |
| Slot Granularity | `slot_interval_minutes` | 15 min | AppointmentService |
| Max Service Length | `max_service_duration_minutes` | 540 min (9h) | Filament Form, Validation |

## Related Configuration

**Also updated**:
- `config/app.php`: Set `timezone => 'Europe/Warsaw'` to match booking timezone
- `.env.example`: Added all BOOKING_* variables with documentation

## References

- Configuration File: `config/booking.php`
- Environment Template: `.env.example`
- Usage: `app/Services/AppointmentService.php`, `app/Models/Appointment.php`
- Laravel Docs: https://laravel.com/docs/12.x/configuration

## Future Enhancements

1. **GUI Editor** (if rules change frequently):
   - Create Filament settings page
   - Store in database with caching
   - Add audit trail of changes

2. **Validation Command**:
   ```bash
   php artisan booking:validate-config
   ```
   - Check all config values are valid
   - Warn about mismatches (e.g., timezone mismatch)

3. **Multi-Location Support**:
   - Different business hours per location
   - Location-specific rules in database

4. **Holiday Calendar**:
   - Closed dates configuration
   - Holiday-specific hours

## Review and Updates

This decision should be reviewed if:
- Rules need to change more than once per month
- Different locations require different hours
- Real-time rule changes become business requirement
- Performance degradation observed (config cache issues)
