# VULN-002: No Audit Logging for Booking Events

**Status**: OPEN
**Severity**: MEDIUM (Non-blocking)
**Priority**: P2 (Post-deployment)
**Detected**: 2025-12-10
**Affected Version**: v0.6.2

---

## Summary

The booking system lacks audit logging for critical events (booking creation, cancellation, modification). Without logging, there is no investigation trail for disputes, fraud detection, or compliance auditing (e.g., GDPR Article 30 - Records of Processing Activities).

---

## Missing Audit Logs

### Booking Creation
**Location**: BookingController::confirm() (line 377)

**Current State**:
```php
// app/Http/Controllers/BookingController.php:377
$appointment = Appointment::create([
    'user_id' => auth()->id(),
    'service_id' => $booking['service_id'],
    // ... other fields
]);

// Send confirmation email
EmailService::sendAppointmentConfirmation($appointment);

// Increment stats
BookingStatsService::incrementBookingCount($service);

// NO audit logging ❌
```

**Missing Information**:
- Who created the booking (user_id logged, but no audit entry)
- When the booking was created (timestamps in DB, but no log)
- From where (IP address, user agent)
- What data was submitted (service, date/time, location)

---

### Booking Cancellation
**Location**: AppointmentController::cancel() (assumed)

**Current State**: No audit logging for cancellation events

**Missing Information**:
- Who cancelled the booking
- When it was cancelled
- Reason for cancellation (if provided)
- IP address of cancellation request

---

### Booking Modification
**Location**: Not yet implemented (future feature)

**Future Risk**: No audit trail for rescheduling or modification

---

## Risk Assessment

**Likelihood**: HIGH (every booking/cancellation lacks audit log)

**Impact**: MEDIUM
- No investigation trail for customer disputes
- No fraud detection (pattern analysis impossible)
- GDPR compliance gap (Article 30 requires processing records)
- Security incident response limited (no attacker trace)

**Overall Risk**: MEDIUM-HIGH (Likelihood: HIGH × Impact: MEDIUM)

---

## Attack Scenarios

### Scenario 1: Booking Fraud Dispute

**Situation**: Customer claims they did not create a booking

**Current State**:
1. Customer: "I didn't make this booking, my account was hacked!"
2. Support: Checks `appointments` table (shows user_id, created_at)
3. Support: NO IP address, NO user agent, NO audit trail
4. Outcome: Cannot prove booking origin, customer may demand refund

**With Audit Logging**:
1. Support checks audit log: IP address, user agent, timestamp
2. Compare with customer's known IPs, devices
3. Detect anomaly (login from different country)
4. Outcome: Evidence of account compromise, proper resolution

---

### Scenario 2: Staff Fraud (Future Risk)

**Situation**: Staff member creates fake bookings for personal gain

**Current State**:
1. Fake bookings created (shows customer_id in database)
2. NO audit trail linking staff member to creation
3. Investigation difficult (who created it? when? from where?)

**With Audit Logging**:
1. Audit log shows staff_id, IP address, timestamp
2. Pattern detection: 10 bookings from same IP in 5 minutes
3. Investigation: Staff member identified, account suspended

---

### Scenario 3: GDPR Compliance Audit

**Situation**: Regulatory audit requests "Records of Processing Activities"

**Current State**:
1. Auditor: "Show me all personal data processing activities for user_id=123"
2. Response: Database has bookings, but no audit trail
3. Auditor: "How do you prove data was processed lawfully?"
4. Outcome: GDPR Article 30 non-compliance

**With Audit Logging**:
1. Auditor requests processing records
2. Response: Complete audit log with consent timestamps, IP addresses
3. Auditor verifies lawful processing (user consent logged)
4. Outcome: Compliance verified

---

## Recommended Fix

### Solution: Add Audit Logging

**Effort**: 30 minutes
**Deployment**: Immediate (no database migration needed, uses Laravel's log system)

**Implementation**:

#### Step 1: Add Logging to BookingController

```php
// app/Http/Controllers/BookingController.php:377 (after appointment creation)
$appointment = Appointment::create([
    'user_id' => auth()->id(),
    'service_id' => $booking['service_id'],
    // ... other fields
]);

// ADD: Audit logging
Log::info('Booking confirmed', [
    'event' => 'booking.confirmed',
    'appointment_id' => $appointment->id,
    'user_id' => auth()->id(),
    'user_email' => auth()->user()->email,
    'service_id' => $booking['service_id'],
    'service_name' => $service->name,
    'appointment_date' => $booking['date'],
    'appointment_time' => $booking['time_slot'],
    'location' => $booking['location_address'],
    'ip_address' => request()->ip(),
    'user_agent' => request()->userAgent(),
    'session_id' => session()->getId(),
    'timestamp' => now()->toIso8601String(),
]);
```

#### Step 2: Add Logging to AppointmentController (Cancellation)

```php
// app/Http/Controllers/AppointmentController.php:cancel()
public function cancel(Appointment $appointment)
{
    $this->authorize('cancel', $appointment);

    $appointment->update([
        'status' => 'cancelled',
        'cancellation_reason' => request()->input('reason'),
    ]);

    // ADD: Audit logging
    Log::info('Booking cancelled', [
        'event' => 'booking.cancelled',
        'appointment_id' => $appointment->id,
        'user_id' => auth()->id(),
        'user_email' => auth()->user()->email,
        'service_name' => $appointment->service->name,
        'appointment_date' => $appointment->appointment_date,
        'cancellation_reason' => request()->input('reason'),
        'ip_address' => request()->ip(),
        'user_agent' => request()->userAgent(),
        'timestamp' => now()->toIso8601String(),
    ]);

    return redirect()->back()->with('success', 'Booking cancelled');
}
```

#### Step 3: Add Logging for Failed Bookings

```php
// app/Http/Controllers/BookingController.php:361 (slot no longer available)
if (!$requestedSlot || !$requestedSlot['available']) {
    // ADD: Audit logging (failed booking attempt)
    Log::warning('Booking failed - slot unavailable', [
        'event' => 'booking.failed',
        'reason' => 'slot_unavailable',
        'user_id' => auth()->id(),
        'user_email' => auth()->user()->email,
        'service_id' => $booking['service_id'],
        'requested_date' => $booking['date'],
        'requested_time' => $booking['time_slot'],
        'ip_address' => request()->ip(),
        'timestamp' => now()->toIso8601String(),
    ]);

    return redirect()->route('booking.step', 2)
        ->with('error', 'Wybrany termin jest już niedostępny. Wybierz inny.');
}
```

---

### Log Storage

**Laravel Log Driver**: Daily rotation (storage/logs/laravel-YYYY-MM-DD.log)

**Retention**: Configure in config/logging.php
```php
'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 90, // Keep logs for 90 days
    'permission' => 0664,
    'locking' => false,
],
```

**Production Recommendation**: Ship logs to external service (Papertrail, Loggly, CloudWatch)

---

### Database Audit Trail (Future Enhancement)

**For High-Security Requirements**:

**Migration**: Create `audit_logs` table
```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->string('event'); // 'booking.confirmed', 'booking.cancelled'
    $table->string('auditable_type'); // 'App\Models\Appointment'
    $table->unsignedBigInteger('auditable_id'); // Appointment ID
    $table->unsignedBigInteger('user_id')->nullable();
    $table->string('ip_address', 45);
    $table->text('user_agent')->nullable();
    $table->json('metadata'); // Full event data
    $table->timestamp('created_at');

    $table->index(['auditable_type', 'auditable_id']);
    $table->index('event');
    $table->index('user_id');
    $table->index('created_at');
});
```

**Querying Audit Trail**:
```php
// Find all bookings by user
$audits = DB::table('audit_logs')
    ->where('user_id', 123)
    ->where('event', 'booking.confirmed')
    ->get();

// Find all cancellations in last 30 days
$cancellations = DB::table('audit_logs')
    ->where('event', 'booking.cancelled')
    ->where('created_at', '>=', now()->subDays(30))
    ->get();
```

---

## Testing

### Test 1: Verify Logging on Booking Creation

**Procedure**:
1. Create a booking via wizard
2. Check storage/logs/laravel-YYYY-MM-DD.log
3. Verify log entry contains:
   - event: booking.confirmed
   - appointment_id
   - user_id, user_email
   - service_id, service_name
   - appointment_date, appointment_time
   - ip_address, user_agent

**Expected Output**:
```
[2025-12-10 18:45:00] local.INFO: Booking confirmed {
    "event": "booking.confirmed",
    "appointment_id": 42,
    "user_id": 5,
    "user_email": "jan.kowalski@example.com",
    "service_id": 3,
    "service_name": "Mycie Podstawowe",
    "appointment_date": "2025-12-15",
    "appointment_time": "10:00",
    "location": "ul. Poznańska 123, Poznań",
    "ip_address": "192.168.1.100",
    "user_agent": "Mozilla/5.0...",
    "session_id": "abc123...",
    "timestamp": "2025-12-10T18:45:00+00:00"
}
```

---

### Test 2: Verify Logging on Cancellation

**Procedure**:
1. Cancel an existing booking
2. Check storage/logs/laravel-YYYY-MM-DD.log
3. Verify log entry contains:
   - event: booking.cancelled
   - appointment_id
   - cancellation_reason

**Expected Output**:
```
[2025-12-10 19:00:00] local.INFO: Booking cancelled {
    "event": "booking.cancelled",
    "appointment_id": 42,
    "user_id": 5,
    "user_email": "jan.kowalski@example.com",
    "service_name": "Mycie Podstawowe",
    "appointment_date": "2025-12-15",
    "cancellation_reason": "Zmiana planów",
    "ip_address": "192.168.1.100",
    "user_agent": "Mozilla/5.0...",
    "timestamp": "2025-12-10T19:00:00+00:00"
}
```

---

### Test 3: Verify Logging on Failed Booking

**Procedure**:
1. Attempt to book a slot that becomes unavailable
2. Check storage/logs/laravel-YYYY-MM-DD.log
3. Verify warning log entry

**Expected Output**:
```
[2025-12-10 19:15:00] local.WARNING: Booking failed - slot unavailable {
    "event": "booking.failed",
    "reason": "slot_unavailable",
    "user_id": 5,
    "user_email": "jan.kowalski@example.com",
    "service_id": 3,
    "requested_date": "2025-12-15",
    "requested_time": "10:00",
    "ip_address": "192.168.1.100",
    "timestamp": "2025-12-10T19:15:00+00:00"
}
```

---

## Acceptance Criteria

- [ ] Audit logging added to BookingController::confirm()
- [ ] Audit logging added to AppointmentController::cancel()
- [ ] Audit logging added to failed booking attempts
- [ ] Log entries include: event, user_id, ip_address, user_agent, timestamp
- [ ] Test: Log entry created on booking confirmation
- [ ] Test: Log entry created on cancellation
- [ ] Test: Log entry created on failed booking
- [ ] Log retention configured (90 days)
- [ ] Documentation updated (CLAUDE.md, security baseline)

---

## Deployment Impact

**Breaking Changes**: None

**Backwards Compatibility**: ✅ Fully compatible
- Existing bookings unaffected
- No database schema changes
- Log files automatically rotated

**Performance Impact**: Negligible (write to log file is fast)

**Rollback**: Remove Log::info() calls

---

## Related Issues

- GDPR Compliance: Article 30 (Records of Processing Activities)
- Future: Export audit logs for compliance reporting

---

## References

- [Laravel Logging Documentation](https://laravel.com/docs/12.x/logging)
- [GDPR Article 30](https://gdpr-info.eu/art-30-gdpr/)
- [OWASP API9: Insufficient Logging & Monitoring](https://owasp.org/API-Security/editions/2023/en/0xa9-insufficient-logging-monitoring/)

---

**Created**: 2025-12-10
**Last Updated**: 2025-12-10
**Assigned To**: TBD (post-deployment sprint)
