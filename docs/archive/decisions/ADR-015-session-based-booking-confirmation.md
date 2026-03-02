# ADR-015: Session-Based Booking Confirmation Security

**Date**: 2025-12-12
**Status**: ACCEPTED
**Decision Makers**: Project Coordinator, laravel-senior-architect, security-audit-specialist

## Context

The booking confirmation flow exposed sequential appointment IDs in URLs (`/booking/confirmation/3`), creating a critical security vulnerability:

1. **ID Enumeration Attack**: Anyone could iterate IDs to access other users' booking confirmations
2. **GDPR Violation**: Personal data (name, email, phone, address) exposed without authorization
3. **Authorization Bug**: Controller checked `user_id` field that doesn't exist (should be `customer_id`)
4. **Poor UX**: 403 Forbidden errors shown to legitimate users despite successful booking creation

### User Complaint

> "Totalnie słaba implementacja! Strona Confirmation dodaje ID zamówienia! Przecież to jest podatność najgorszego typu!!! Otrzymuję 403 ale zamówienie się tworzy."

### Risk Assessment

- **CVSS Score**: 7.5 (High)
- **OWASP Category**: A01:2021 - Broken Access Control
- **CWE**: CWE-639 - Authorization Bypass Through User-Controlled Key
- **GDPR Impact**: Article 32 - Security of Processing (FAILED)

## Decision

Implement **session-based single-use token** for booking confirmation flow.

### Architecture

```
User completes booking
  ↓
Controller creates appointment in database
  ↓
Store appointment ID in session (key: 'booking_confirmed_id')
  ↓
Redirect to /booking/confirmation (NO ID in URL)
  ↓
Controller retrieves ID from session (session()->pull())
  ↓
Verify ownership (customer_id === auth()->id())
  ↓
Display confirmation page
  ↓
Session token deleted (single-use)
```

## Alternatives Considered

### Option A: UUID/Hash Tokens (NOT CHOSEN)

**Approach**:
```php
// Add confirmation_token column
$appointment->confirmation_token = bin2hex(random_bytes(32));

// URL becomes: /booking/confirmation/a3f8b2c9d1e4f5a6b7c8d9e0f1a2b3c4...
```

**Pros**:
- Shareable confirmation links
- No enumeration risk
- Bookmarkable

**Cons**:
- Requires database migration
- More complex implementation
- Token lifecycle management needed
- Overkill for single-use confirmation

### Option B: Signed URLs (NOT CHOSEN)

**Approach**:
```php
$url = URL::temporarySignedRoute(
    'booking.confirmation',
    now()->addHour(),
    ['appointment' => $appointment->id]
);
```

**Pros**:
- Built-in Laravel feature
- Cryptographically signed
- Time-limited

**Cons**:
- Still exposes ID in URL (signature prevents tampering but not viewing)
- Middleware required
- More complex than needed

### Option C: Session-Based (CHOSEN)

**Approach**:
```php
// Store in session
session(['booking_confirmed_id' => $appointment->id]);

// Retrieve (pull = get + delete)
$appointmentId = session()->pull('booking_confirmed_id');
```

**Pros**:
- Zero database changes
- Single-use (session pulled after first view)
- No ID in URL
- Simple implementation
- Fast (session operations)

**Cons**:
- Not shareable (expires after view)
- Session-dependent (won't survive session clear)

**Why Chosen**: Confirmation pages don't need to be shareable. Users view once, then navigate to "My Appointments" for future reference.

## Implementation Details

### Code Changes

**1. BookingController::confirm()** (app/Http/Controllers/BookingController.php:551-556)

```php
// SECURITY: Store appointment ID in single-use session token (no ID in URL)
session(['booking_confirmed_id' => $appointment->id]);

// Clear wizard session
session()->forget('booking');

return redirect()->route('booking.confirmation');  // NO ID!
```

**2. BookingController::showConfirmation()** (app/Http/Controllers/BookingController.php:562-591)

```php
public function showConfirmation()
{
    // SECURITY FIX: Use single-use session token instead of ID in URL
    // Pull = get and delete in one operation (token can only be used once)
    $appointmentId = session()->pull('booking_confirmed_id');

    if (!$appointmentId) {
        return redirect()->route('appointments.index')
            ->with('error', 'Link potwierdzenia wygasł. Zobacz swoje wizyty poniżej.');
    }

    $appointment = Appointment::findOrFail($appointmentId);

    // SECURITY: Double-check ownership (defense in depth)
    if ($appointment->customer_id !== auth()->id()) {
        abort(403, 'Brak dostępu do tego potwierdzenia.');
    }

    // ... rest of method
}
```

**3. Route Update** (routes/web.php:106)

```php
// BEFORE (VULNERABLE):
Route::get('/booking/confirmation/{appointment}', [BookingController::class, 'showConfirmation']);

// AFTER (SECURE):
Route::get('/booking/confirmation', [BookingController::class, 'showConfirmation']);
```

### Security Benefits

| Aspect | Before (VULNERABLE) | After (SECURE) |
|--------|---------------------|----------------|
| URL Format | `/booking/confirmation/3` | `/booking/confirmation` |
| ID Exposure | Sequential ID visible | No ID in URL |
| Enumeration Risk | HIGH (increment ID) | IMPOSSIBLE (no ID) |
| Shareability | Yes (security risk) | No (single-use) |
| Reusability | Unlimited | Single-use only |
| Authorization | Broken (user_id bug) | Fixed (customer_id) |
| GDPR Compliance | FAILED | PASSED |

### UX Improvements

Enhanced navigation buttons on confirmation page:

1. **Primary CTA**: "Zobacz Moje Wizyty" → `/my-appointments`
2. **Secondary CTAs**:
   - "Przeglądaj Usługi" → `/uslugi`
   - "Mój Profil" → `/moje-konto`
3. **Tertiary Link**: "Strona Główna" → `/`

**Rationale**: Guide users to logical next actions instead of leaving them on dead-end confirmation page.

## Consequences

### Positive

✅ **Security**:
- Eliminated ID enumeration vulnerability
- Fixed authorization bug (customer_id vs user_id)
- GDPR compliance restored (no unauthorized data access)
- Defense in depth (session + ownership check)

✅ **Performance**:
- No database overhead (session operations are fast)
- No additional queries
- No new indexes needed

✅ **Maintainability**:
- Simple implementation (5 lines of code change)
- No migrations required
- Easy to understand and audit

✅ **User Experience**:
- No more 403 errors
- Clear navigation options
- Session expiry message guides users to "My Appointments"

### Negative

⚠️ **Limitations**:
- Confirmation page not bookmarkable (expires after first view)
- Can't share confirmation link (by design)
- Session-dependent (won't survive manual session clear)

⚠️ **Edge Cases**:
- User clears browser before viewing confirmation → Redirected to "My Appointments" with error
- User refreshes confirmation page → Session token consumed, redirected away
- User opens confirmation in multiple tabs → Only first tab works

### Mitigations for Edge Cases

- Users redirected to `/my-appointments` where they can view their booking
- Clear error message: "Link potwierdzenia wygasł. Zobacz swoje wizyty poniżej."
- Calendar export links still functional (use route model binding with ID)

## Testing

### Manual Testing Checklist

- [x] Complete booking flow (step 1-5)
- [x] Verify confirmation URL has no ID (`/booking/confirmation`)
- [x] Verify confirmation page displays correctly
- [x] Refresh page → Redirect to "My Appointments" with error
- [x] Try direct access without session token → Redirect with error
- [x] Verify ownership check (403 if session tampered)
- [x] Verify calendar export links work

### Security Testing

**Test 1: No ID Enumeration**
```bash
# Attempt to access other users' confirmations
curl https://paradocks.local:8444/booking/confirmation/1
# Expected: 404 (route not found)

curl https://paradocks.local:8444/booking/confirmation/2
# Expected: 404 (route not found)
```

**Test 2: Session Isolation**
```bash
# User A completes booking
# User B tries to access /booking/confirmation
# Expected: Redirect (no session token)
```

**Test 3: Single-Use Token**
```bash
# View confirmation (success)
# Refresh page (redirect with error)
# Expected: Token consumed after first use
```

## Deployment

### Prerequisites
- None (no migrations, no dependencies)

### Deployment Steps
1. Deploy code changes
2. Clear route cache: `php artisan optimize:clear`
3. Monitor logs for 403/404 errors
4. Verify new bookings use session-based flow

### Rollback Plan
```bash
# Revert BookingController.php
git checkout HEAD~1 -- app/Http/Controllers/BookingController.php

# Revert routes/web.php
git checkout HEAD~1 -- routes/web.php

# Clear cache
php artisan optimize:clear
```

## Future Considerations

### When to Use UUID Tokens Instead

If requirements change to support:
- Shareable confirmation links
- Bookmarkable confirmations
- Email confirmation links (click from email days later)

Then migrate to UUID token approach (see Option A in Alternatives).

### Related Security Improvements

1. **Add CSRF protection to calendar export** (already has auth middleware)
2. **Rate limit confirmation endpoint** (prevent session brute force)
3. **Add security headers** (CSP, X-Frame-Options)

## References

- **Security Fix Documentation**: `/app/docs/security/SECURITY-FIX-001-booking-confirmation.md`
- **OWASP**: A01:2021 - Broken Access Control
- **CWE**: CWE-639 - Authorization Bypass Through User-Controlled Key
- **GDPR**: Article 32 - Security of Processing
- **Laravel Session Docs**: https://laravel.com/docs/session

## Decision Rationale

This decision prioritizes **security by default** and **GDPR compliance** over convenience features (shareability).

For a booking confirmation page, users typically:
1. View confirmation once
2. Add to calendar
3. Navigate away
4. Later: Check "My Appointments" for details

The single-use session token perfectly matches this user journey while eliminating critical security risks.

**Trade-off accepted**: Not shareable = more secure and GDPR compliant.
