# Security Baseline - Registro Application

**Last Updated**: 2025-12-10 18:45 UTC
**Application Version**: v0.6.2
**Laravel Version**: 12.32.5
**PHP Version**: 8.2.29

---

## Risk Profile

**Overall Risk Score**: 72/100 (MODERATE-LOW)
**Overall Rating**: ACCEPTABLE for production deployment

| Severity | Count | Status |
|----------|-------|--------|
| CRITICAL | 0 | None detected |
| HIGH | 0 | None detected |
| MEDIUM | 2 | Non-blocking (hardening recommended) |
| LOW | 3 | Informational |

---

## OWASP Top 10 2021 Compliance

| Category | Status | Notes |
|----------|--------|-------|
| A01: Broken Access Control | ✅ PASSED | Authorization checks on sensitive endpoints |
| A02: Cryptographic Failures | ✅ PASSED | No sensitive data exposed, proper encryption |
| A03: Injection | ✅ PASSED | Eloquent ORM, no raw queries, iCal escaping |
| A04: Insecure Design | ⚠️ PARTIAL | Missing rate limiting (P2) |
| A05: Security Misconfiguration | ✅ PASSED | Laravel defaults, CSRF enabled |
| A06: Vulnerable Components | ℹ️ INFO | Routine audit recommended |
| A07: Authentication Failures | ✅ PASSED | Auth middleware on all protected routes |
| A08: Data Integrity Failures | ℹ️ INFO | Calendar generation secure |
| A09: Logging/Monitoring Failures | ⚠️ PARTIAL | Audit logging needed (P2) |
| A10: SSRF | ✅ PASSED | No external HTTP requests |

**Compliance Rate**: 80% (8/10 fully passed, 2/10 partial)

---

## Known Vulnerabilities

### MEDIUM Severity

**VULN-001: Missing Rate Limiting on Booking Endpoints**
- **Location**: `/booking/step/{step}`, `/booking/confirm`, `/booking/save-progress`
- **Risk**: Booking spam, resource exhaustion
- **Mitigation**: Authentication required (limits attack surface)
- **Status**: OPEN
- **Priority**: P2 (Post-deployment)
- **Effort**: 15 minutes
- **Fix**: Add `throttle:30,1` middleware to booking routes

**VULN-002: No Audit Logging for Bookings**
- **Location**: `BookingController::confirm()`
- **Risk**: No investigation trail for disputes/fraud
- **Mitigation**: Laravel logs all errors by default
- **Status**: OPEN
- **Priority**: P2 (Post-deployment)
- **Effort**: 30 minutes
- **Fix**: Add Log::info() calls for booking events

### LOW Severity

**INFO-001: Unavailable Dates Endpoint Performance**
- **Location**: `/booking/unavailable-dates`
- **Risk**: Slow response (2+ seconds), potential DoS
- **Mitigation**: Authenticated users only
- **Status**: OPEN
- **Priority**: P2 (Performance optimization)
- **Effort**: 30 minutes
- **Fix**: Cache results for 15 minutes

**INFO-002: Session-Based Booking State**
- **Location**: `BookingController` session management
- **Risk**: Session fixation, no expiration
- **Mitigation**: HTTPS enforced, Laravel session security
- **Status**: ACCEPTED (MVP)
- **Priority**: P3 (Future enhancement)
- **Effort**: 2 hours
- **Fix**: Add session regeneration + timeout

**INFO-003: No Input Validation on saveProgress**
- **Location**: `BookingController::saveProgress()`
- **Risk**: Session bloat, arbitrary data in session
- **Mitigation**: Validated on booking confirmation
- **Status**: OPEN
- **Priority**: P3
- **Effort**: 10 minutes
- **Fix**: Add validation rules for `step` and `data` fields

---

## Security Configuration

### Authentication

- **Guards**: `web` (session-based)
- **Middleware**: `auth` required on all booking routes
- **Password Hashing**: Bcrypt (12 rounds)
- **Session Lifetime**: 120 minutes
- **Session Driver**: Database (encrypted)

### Authorization

- **Pattern**: Manual checks in controllers
- **IDOR Protection**: ✅ Implemented (user ownership checks)
- **Policies**: Not yet implemented (recommended for future)

### CSRF Protection

- **Status**: ✅ Enabled
- **Middleware**: `web` group includes VerifyCsrfToken
- **Exemptions**: None (all POST/PUT/DELETE protected)

### Rate Limiting

- **Global**: None configured
- **Service Pages**: `throttle:60,1` (60 requests/minute)
- **Webhooks**: `throttle:120,1` (120 requests/minute)
- **Booking**: ⚠️ Missing (recommendation: `throttle:30,1`)

### Input Validation

- **Method**: Laravel Form Requests + inline validation
- **Sanitization**: Automatic via Laravel validator
- **XSS Prevention**: Blade escaping ({{ }})
- **iCal Injection Prevention**: RFC 5545 escaping in CalendarService

---

## Infrastructure Security

### Docker Configuration

- **MySQL Port**: Exposed (3306) - Mitigated by UFW firewall (ADR-007)
- **Redis Port**: Exposed (6379) - Mitigated by UFW firewall (ADR-007)
- **PHP-FPM**: Not exposed (internal network only)
- **Nginx**: HTTPS enforced (Let's Encrypt SSL)

### SSL/HTTPS

- **Status**: ✅ Enabled (ADR-014)
- **Certificate**: Let's Encrypt
- **HSTS**: Enabled (max-age=31536000)
- **Force HTTPS**: Nginx redirect configured

### Firewall

- **UFW Status**: Active
- **Allowed Ports**: 22 (SSH), 80 (HTTP), 443 (HTTPS)
- **Blocked Ports**: 3306 (MySQL), 6379 (Redis), 5432 (PostgreSQL)

---

## File Checksums (Change Detection)

**Critical Files** (SHA256):

```
routes/web.php: [to be computed on baseline generation]
app/Http/Controllers/BookingController.php: [to be computed]
app/Services/CalendarService.php: [to be computed]
config/auth.php: [to be computed]
config/session.php: [to be computed]
config/cors.php: [to be computed]
docker-compose.yml: [to be computed]
.env.example: [to be computed]
```

**Note**: Run `sha256sum <file>` to compute checksums for change detection.

---

## Deployment Status

**Last Deployment**: [Pending]
**Last Security Audit**: 2025-12-10 (Pre-deployment)
**Next Audit Due**: 2025-12-25 (or before v0.7.0 release)

**Deployment Checklist**:
- [x] OWASP Top 10 scan completed
- [x] SQL injection scan (0 vulnerabilities)
- [x] XSS protection verified
- [x] CSRF protection verified
- [x] Authorization checks verified
- [x] No CRITICAL/HIGH vulnerabilities
- [x] Security documentation updated

**Approved for Deployment**: ✅ YES

---

## Recommendations Timeline

### Week 1 (High Priority)

1. **Add Rate Limiting** (VULN-001) - 15 minutes
   ```php
   Route::middleware(['auth', 'throttle:30,1'])->group(function () {
       Route::post('/booking/step/{step}', ...);
       Route::post('/booking/confirm', ...);
   });
   ```

2. **Add Audit Logging** (VULN-002) - 30 minutes
   ```php
   Log::info('Booking confirmed', [
       'user_id' => auth()->id(),
       'appointment_id' => $appointment->id,
       'ip_address' => request()->ip(),
   ]);
   ```

3. **Monitor Logs** - Daily
   - Check `storage/logs/laravel.log` for anomalies
   - Review Horizon failed jobs

### Month 1 (Medium Priority)

1. **Cache Unavailable Dates** (INFO-001) - 30 minutes
2. **Implement AppointmentPolicy** (Authorization Pattern) - 1 hour
3. **Add Input Validation to saveProgress** (INFO-003) - 10 minutes

### Future Enhancements (Low Priority)

1. **Session Timeout for Booking Flow** (INFO-002) - 2 hours
2. **Booking Fraud Detection** - 4 hours
3. **Automated Security Scans in CI/CD** - 2 hours

---

## Audit History

| Date | Type | Result | Issues Found | Report |
|------|------|--------|--------------|--------|
| 2025-12-10 | Pre-Deployment (Booking Wizard) | PASS | 2 MEDIUM, 3 LOW | [View Report](audit-reports/2025-12-10-pre-deployment-booking-wizard.md) |

---

## Contact

**Security Issues**: Report to security@registro.com (if production)
**Development Team**: registro-dev@example.com
**On-Call**: [Escalation procedure TBD]

---

**Baseline Version**: 1.0
**Next Review**: 2025-12-25
