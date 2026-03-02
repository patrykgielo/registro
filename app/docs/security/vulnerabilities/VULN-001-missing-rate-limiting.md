# VULN-001: Missing Rate Limiting on Booking Endpoints

**Status**: OPEN
**Severity**: MEDIUM (Non-blocking)
**Priority**: P2 (Post-deployment)
**Detected**: 2025-12-10
**Affected Version**: v0.6.2

---

## Summary

Booking wizard endpoints lack rate limiting, allowing authenticated users to make unlimited booking requests. While authentication provides basic protection, high-frequency requests could lead to resource exhaustion or booking spam.

---

## Affected Endpoints

- POST `/booking/step/{step}` - Store wizard step data (no throttle)
- POST `/booking/confirm` - Create appointment (no throttle)
- POST `/booking/save-progress` - AJAX session save (no throttle)
- GET `/booking/restore-progress` - AJAX session restore (no throttle)
- GET `/booking/unavailable-dates` - Calendar availability (no throttle, heavy computation)

**Current Configuration** (routes/web.php:88-107):
```php
Route::middleware(['auth'])->group(function () {
    // NO throttle middleware on booking routes
    Route::get('/booking/step/{step}', [BookingController::class, 'showStep']);
    Route::post('/booking/step/{step}', [BookingController::class, 'storeStep']);
    Route::post('/booking/confirm', [BookingController::class, 'confirm']);
    Route::post('/booking/save-progress', [BookingController::class, 'saveProgress']);
    Route::get('/booking/restore-progress', [BookingController::class, 'restoreProgress']);
    Route::get('/booking/unavailable-dates', [BookingController::class, 'getUnavailableDates']);
});
```

---

## Risk Assessment

**Likelihood**: LOW
- Requires authenticated account
- Session-based (limits parallel attacks)
- Most endpoints write to session (not database)

**Impact**: MEDIUM
- Resource exhaustion (session storage bloat)
- Database writes (confirm endpoint creates appointments)
- CPU exhaustion (unavailable-dates endpoint, 60-day computation)

**Overall Risk**: MEDIUM (Likelihood: LOW × Impact: MEDIUM)

---

## Attack Scenarios

### Scenario 1: Booking Spam

**Attacker**: Authenticated user with malicious intent

**Attack**:
```bash
# Create 1000 bookings in 1 minute
for i in {1..1000}; do
  curl -X POST https://registro.local:8444/booking/confirm \
    -H "Cookie: session=..." \
    -H "X-CSRF-TOKEN: ..." \
    --data "..." &
done
```

**Impact**:
- 1000 appointments created in database
- Email queue flooded (1000 confirmation emails)
- SMS queue flooded (if SMS enabled)
- Staff schedule saturated (no slots available)

**Mitigation**: Current slot availability check prevents double-booking, but does not prevent spam.

---

### Scenario 2: Resource Exhaustion (Unavailable Dates)

**Attacker**: Authenticated user

**Attack**:
```bash
# Trigger heavy computation 1000 times
for i in {1..1000}; do
  curl "https://registro.local:8444/booking/unavailable-dates?service_id=1" \
    -H "Cookie: session=..." &
done
```

**Impact**:
- CPU exhaustion (1000 × 60-day availability checks)
- Database connection pool exhaustion
- Slow response for legitimate users
- Potential application crash

**Mitigation**: None currently (no throttle, no caching).

---

### Scenario 3: Session Bloat

**Attacker**: Authenticated user

**Attack**:
```bash
# Flood session with saveProgress requests
for i in {1..10000}; do
  curl -X POST https://registro.local:8444/booking/save-progress \
    -H "Cookie: session=..." \
    -H "X-CSRF-TOKEN: ..." \
    --data '{"step":1,"data":{"field1":"x","field2":"y",...}}' &
done
```

**Impact**:
- Session storage bloat (database table `sessions` grows)
- Memory exhaustion (if session driver is `file`)
- Slow session reads for all users

**Mitigation**: Session data validated on booking confirmation, but not on save.

---

## Mitigation (Current)

**Authentication Required**:
- All endpoints behind `auth` middleware
- Limits attack surface to registered users only
- Account banning possible for abusive users

**Slot Availability Check**:
- BookingController::confirm() re-checks slot availability (lines 350-362)
- Prevents double-booking via race condition protection
- Does NOT prevent creating multiple unique bookings

**Session-Based State**:
- Most endpoints write to session (not database)
- Reduces database write load
- Session bloat still possible

---

## Recommended Fix

### Solution 1: Add Rate Limiting (Recommended)

**Effort**: 15 minutes
**Deployment**: Immediate (no database changes)

**Implementation**:
```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    // View routes (higher limit)
    Route::middleware(['throttle:60,1'])->group(function () {
        Route::get('/booking/step/{step}', [BookingController::class, 'showStep']);
        Route::get('/booking/restore-progress', [BookingController::class, 'restoreProgress']);
    });

    // Write routes (lower limit)
    Route::middleware(['throttle:30,1'])->group(function () {
        Route::post('/booking/step/{step}', [BookingController::class, 'storeStep']);
        Route::post('/booking/save-progress', [BookingController::class, 'saveProgress']);
    });

    // Critical routes (strict limit)
    Route::middleware(['throttle:10,1'])->group(function () {
        Route::post('/booking/confirm', [BookingController::class, 'confirm']);
    });

    // Heavy computation (strict limit)
    Route::middleware(['throttle:20,1'])->group(function () {
        Route::get('/booking/unavailable-dates', [BookingController::class, 'getUnavailableDates']);
    });

    // Confirmation routes (no throttle needed)
    Route::get('/booking/confirmation/{appointment}', [BookingController::class, 'showConfirmation'])->name('booking.confirmation');
    Route::get('/booking/ical/{appointment}', [BookingController::class, 'downloadIcal'])->name('booking.ical');
});
```

**Rate Limit Explanation**:
- `throttle:60,1` = 60 requests per 1 minute (read-only endpoints)
- `throttle:30,1` = 30 requests per 1 minute (session writes)
- `throttle:10,1` = 10 requests per 1 minute (critical actions)
- `throttle:20,1` = 20 requests per 1 minute (heavy computation)

**Per-User Limiting**: Laravel's `throttle` middleware uses session ID for authenticated users.

---

### Solution 2: Add Caching (Performance Optimization)

**Effort**: 30 minutes
**Deployment**: Immediate (no database changes)

**Implementation**:
```php
// app/Http/Controllers/BookingController.php:285
public function getUnavailableDates(Request $request)
{
    $request->validate([
        'service_id' => 'required|exists:services,id',
    ]);

    $serviceId = $request->service_id;

    // Cache results for 15 minutes (reduces computation)
    $cacheKey = "unavailable-dates:{$serviceId}";

    return Cache::remember($cacheKey, 15 * 60, function () use ($serviceId, $request) {
        // ... existing computation (lines 293-330)
    });
}
```

**Cache Invalidation**: Clear cache when appointments created/cancelled.

---

## Testing

### Test 1: Rate Limit Enforcement

**Objective**: Verify 429 Too Many Requests response after limit exceeded

**Procedure**:
```bash
# Test confirm endpoint (limit: 10/min)
for i in {1..15}; do
  curl -w "\n%{http_code}\n" -X POST https://registro.local:8444/booking/confirm \
    -H "Cookie: session=..." \
    -H "X-CSRF-TOKEN: ..." \
    --data "..."
done

# Expected:
# Requests 1-10: 302 (redirect) or 200
# Requests 11-15: 429 Too Many Requests
```

---

### Test 2: User-Specific Throttling

**Objective**: Verify rate limits are per-user, not global

**Procedure**:
1. User A makes 10 booking confirm requests (hits limit)
2. User B makes 1 booking confirm request
3. Expected: User B's request succeeds (429 for User A only)

---

### Test 3: Cache Hit Performance

**Objective**: Verify unavailable-dates caching improves performance

**Procedure**:
```bash
# First request (cache miss)
time curl "https://registro.local:8444/booking/unavailable-dates?service_id=1"
# Expected: 1-2 seconds

# Second request (cache hit)
time curl "https://registro.local:8444/booking/unavailable-dates?service_id=1"
# Expected: < 200ms
```

---

## Acceptance Criteria

- [ ] Rate limiting middleware added to all booking POST routes
- [ ] Appropriate limits configured (60/30/10 per minute)
- [ ] Test: 11th confirm request returns 429
- [ ] Test: User A hitting limit does not affect User B
- [ ] Cache implemented for unavailable-dates endpoint (optional)
- [ ] Documentation updated (CLAUDE.md, security baseline)

---

## Deployment Impact

**Breaking Changes**: None

**Backwards Compatibility**: ✅ Fully compatible
- Existing bookings unaffected
- Normal users unaffected (30 requests/minute is generous)
- Only high-frequency abuse blocked

**Rollback**: Remove `throttle` middleware from routes/web.php

---

## Related Issues

- INFO-001: Unavailable Dates Endpoint Performance (caching solves both)

---

## References

- [Laravel Rate Limiting Documentation](https://laravel.com/docs/12.x/routing#rate-limiting)
- [OWASP API4: Lack of Resources & Rate Limiting](https://owasp.org/API-Security/editions/2023/en/0xa4-unrestricted-resource-consumption/)

---

**Created**: 2025-12-10
**Last Updated**: 2025-12-10
**Assigned To**: TBD (post-deployment sprint)
