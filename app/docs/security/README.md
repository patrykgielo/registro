# Security Documentation Hub

Complete security documentation for the Registro application.

---

## Quick Links

### Baseline & Compliance
- [Security Baseline](baseline.md) - Current security posture (Risk Score: 72/100)
- [Audit Reports](audit-reports/) - Historical security audits

### Known Issues
- [VULN-001: Missing Rate Limiting](vulnerabilities/VULN-001-missing-rate-limiting.md) (MEDIUM — FIXED)
- [VULN-002: No Audit Logging](vulnerabilities/VULN-002-no-audit-logging.md) (MEDIUM)
- [VULN-003: Root-Domain Tenant Isolation Bypass](vulnerabilities/VULN-003-root-domain-tenant-bypass.md) (CRITICAL — FIXED, Layers 1-6)
- [VULN-004: Template Rendering SSTI → RCE](vulnerabilities/VULN-004-template-rendering-rce.md) (CRITICAL — FIXED)
- [VULN-005: Cart/Rental Overselling Race](vulnerabilities/VULN-005-cart-rental-overselling-race.md) (CRITICAL — FIXED)
- [VULN-006: Tenant-Scoping Gaps](vulnerabilities/VULN-006-tenant-scoping-gaps.md) (HIGH — FIXED)
- [VULN-007: P24 Payment Reconciliation Gaps](vulnerabilities/VULN-007-payment-reconciliation.md) (HIGH — FIXED)
- [VULN-008: Audit Log PII Protection](vulnerabilities/VULN-008-audit-log-pii-protection.md) (MEDIUM — FIXED)
- [VULN-009: Low-Severity Cleanup Batch](vulnerabilities/VULN-009-low-severity-cleanup.md) (LOW — FIXED)

### Fix Guides
- [Rate Limiting Guide](remediation-guides/rate-limiting.md)
- [Audit Logging Guide](remediation-guides/audit-logging.md)
- [SQL Injection Prevention](remediation-guides/sql-injection-prevention.md)
- [XSS Prevention](remediation-guides/xss-prevention.md)
- [Authorization Policies](remediation-guides/authorization-policies.md)

### Project Patterns
- [Booking Wizard Security](patterns/booking-wizard-security.md)
- [Calendar Generation Security](patterns/calendar-generation-security.md)
- [Service Layer Security](patterns/service-layer-security.md)
- [Role Escalation Guard (UserResource/RoleResource)](patterns/role-escalation-guard.md)
- [Resource Authorization Layer (BaseResource)](patterns/resource-authorization.md)

---

## Security Status

**Last Audit**: 2025-12-10
**Risk Score**: 72/100 (MODERATE-LOW)
**Deployment Status**: ✅ APPROVED

| Severity | Count | Status |
|----------|-------|--------|
| CRITICAL | 0 | None |
| HIGH | 0 | None |
| MEDIUM | 2 | Non-blocking |
| LOW | 3 | Informational |

**OWASP Compliance**: 80% (8/10 fully passed)

---

## Quick Start

### For Security Questions

Ask the security-audit-specialist agent:
- "Is my booking endpoint secure?"
- "How do I prevent SQL injection?"
- "Check Docker security"

### Generate Initial Baseline

```bash
# First time only (5-7 minutes)
Ask agent: "Generate security baseline"
```

### Pre-Deployment Audit

```bash
# Before each deployment (1-2 minutes)
Ask agent: "Run pre-deployment security audit"
```

### Fix Vulnerability

```bash
# When vulnerability found
Ask agent: "Fix VULN-001"
# Agent will provide code examples and collaborate with laravel-senior-architect
```

---

## Recent Changes (v0.6.2 - Booking Wizard)

### New Endpoints
- POST `/booking/step/{step}` - Store wizard step data
- POST `/booking/confirm` - Create appointment
- GET `/booking/confirmation/{appointment}` - Show confirmation
- GET `/booking/ical/{appointment}` - Download calendar file
- POST `/booking/save-progress` - AJAX session save
- GET `/booking/unavailable-dates` - Calendar availability

### Security Highlights
✅ No SQL injection vulnerabilities (Eloquent ORM used throughout)
✅ Authorization checks on appointment viewing/download
✅ CSRF protection enabled on all POST routes
✅ iCal injection prevention (RFC 5545 escaping)
✅ Race condition mitigation (re-check slot availability)

### Recommendations
⚠️ Add rate limiting to booking endpoints (P2)
⚠️ Add audit logging for booking events (P2)
ℹ️ Cache unavailable dates for performance (P2)

**Full Report**: [2025-12-10 Pre-Deployment Audit](audit-reports/2025-12-10-pre-deployment-booking-wizard.md)

---

## Documentation Structure

```
app/docs/security/
├── README.md                          # This file (security hub)
├── baseline.md                        # Current security state
├── vulnerabilities/                   # Known issues
│   ├── README.md                      # Vulnerability index
│   ├── VULN-001-missing-rate-limiting.md
│   └── VULN-002-no-audit-logging.md
├── remediation-guides/                # Step-by-step fix guides
│   ├── rate-limiting.md
│   ├── audit-logging.md
│   ├── sql-injection-prevention.md
│   ├── xss-prevention.md
│   └── authorization-policies.md
├── audit-reports/                     # Historical audits
│   └── 2025-12-10-pre-deployment-booking-wizard.md
└── patterns/                          # Project-specific security
    ├── booking-wizard-security.md
    ├── calendar-generation-security.md
    └── service-layer-security.md
```

---

## Vulnerability Management

### Severity Levels

**CRITICAL** (Blocks Deployment)
- Remote code execution
- SQL injection with data exposure
- Authentication bypass
- Plaintext password storage

**HIGH** (Urgent Fix Required)
- Privilege escalation
- Sensitive data exposure
- CSRF on critical actions
- Authorization bypass

**MEDIUM** (Fix Within 30 Days)
- Missing rate limiting
- No audit logging
- Session fixation
- Information disclosure

**LOW** (Nice to Have)
- Performance issues
- Best practice violations
- Code quality improvements

### Workflow

1. **Detection**: Security agent scans code or user reports issue
2. **Documentation**: Create VULN-XXX file with details
3. **Prioritization**: Assign severity (CRITICAL/HIGH/MEDIUM/LOW)
4. **Remediation**: Implement fix (with laravel-senior-architect if needed)
5. **Verification**: Re-scan to confirm fix
6. **Update Baseline**: Update risk score and status

---

## OWASP Top 10 2021 Coverage

| Category | Status | Notes |
|----------|--------|-------|
| **A01: Broken Access Control** | ✅ PASSED | Authorization checks on appointment endpoints |
| **A02: Cryptographic Failures** | ✅ PASSED | No plaintext secrets, proper encryption |
| **A03: Injection** | ✅ PASSED | Eloquent ORM, no raw queries, iCal escaping |
| **A04: Insecure Design** | ⚠️ PARTIAL | Missing rate limiting on booking routes |
| **A05: Security Misconfiguration** | ✅ PASSED | Laravel defaults, CSRF enabled |
| **A06: Vulnerable Components** | ℹ️ INFO | Run `composer audit` and `npm audit` regularly |
| **A07: Authentication Failures** | ✅ PASSED | Auth middleware on all protected routes |
| **A08: Data Integrity Failures** | ℹ️ INFO | Calendar generation secure, download headers set |
| **A09: Logging/Monitoring** | ⚠️ PARTIAL | No audit logging for booking events |
| **A10: SSRF** | ✅ PASSED | No external HTTP requests from user input |

**Compliance**: 80% (8/10 fully passed, 2/10 partial with recommendations)

---

## Laravel-Specific Security

### Patterns Used

✅ **Eloquent ORM** - SQL injection prevention (no raw queries)
✅ **Laravel Validation** - Input sanitization
✅ **Auth Middleware** - Authentication on protected routes
✅ **Manual Authorization** - IDOR prevention (user ownership checks)
✅ **CSRF Protection** - VerifyCsrfToken middleware
✅ **Blade Escaping** - XSS prevention ({{ }} auto-escapes)

### Future Enhancements

⚠️ **Authorization Policies** - Replace manual checks with policies
⚠️ **Rate Limiting** - Add throttle middleware to booking routes
⚠️ **Audit Logging** - Log security-critical events
⚠️ **Session Regeneration** - Prevent session fixation on login

---

## Infrastructure Security

### Docker

**Status**: Mixed
- ✅ PHP-FPM not exposed (internal network only)
- ✅ Nginx HTTPS enforced (Let's Encrypt SSL)
- ⚠️ MySQL port exposed (3306) - Mitigated by UFW firewall
- ⚠️ Redis port exposed (6379) - Mitigated by UFW firewall

**Mitigation**: UFW firewall blocks external access (ADR-007)

### VPS Hardening

✅ **UFW Firewall**: Active (ports 22, 80, 443 allowed)
✅ **SSH**: Key-based authentication (password disabled)
✅ **SSL/HTTPS**: Let's Encrypt certificates (ADR-014)
✅ **HSTS**: Enabled (max-age=31536000)
✅ **Security Headers**: X-Frame-Options, X-Content-Type-Options, etc.

### CI/CD Security

✅ **GitHub Actions**: Secret scanning (GitGuardian)
✅ **Actions Pinned**: Commit hashes (supply chain security)
⚠️ **Automated Audits**: Not yet configured (recommendation: add to workflow)

---

## Compliance

### GDPR

✅ **User Consent**: Tracked (notify_email, notify_sms fields)
✅ **Data Minimization**: Only necessary fields collected
✅ **Right to Access**: Users can view own bookings
⚠️ **Audit Logging**: Needed for data processing activities (P2)
✅ **Right to Erasure**: Implemented in profile deletion flow

### PCI DSS (Future)

❌ **Not Applicable**: No payment processing yet
⚠️ **Future**: If payments added, full PCI DSS compliance required

---

## Contact & Escalation

### Security Issues

**Email**: security@registro.com (if production)
**Development**: registro-dev@example.com

### Escalation Procedure

1. **CRITICAL**: Immediate notification to team lead + deployment freeze
2. **HIGH**: Notify within 4 hours, fix within 48 hours
3. **MEDIUM**: Fix within 30 days
4. **LOW**: Include in next sprint

### Bug Bounty (Future)

**Status**: Not yet active
**Scope**: TBD when application goes live

---

## Automated Scanning

### Commands

```bash
# Composer dependency audit
cd app && composer audit

# npm dependency audit
cd app && npm audit --audit-level=moderate

# Docker image CVE scan
docker scout cves registro-app:latest

# Laravel security check
cd app && php artisan security:check  # If package installed
```

### Scheduled Scans (Future)

**Weekly**: Automated dependency audit (GitHub Actions)
**Monthly**: Manual penetration testing
**Quarterly**: Third-party security audit

---

## Resources

### OWASP
- [OWASP Top 10 2021](https://owasp.org/Top10/)
- [OWASP API Security Top 10](https://owasp.org/API-Security/editions/2023/en/0x00-header/)
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/)

### Laravel Security
- [Laravel Security Best Practices](https://laravel.com/docs/12.x/security)
- [Laravel Authorization](https://laravel.com/docs/12.x/authorization)
- [Laravel Rate Limiting](https://laravel.com/docs/12.x/routing#rate-limiting)

### Tools
- [Snyk](https://snyk.io/) - Dependency scanning
- [OWASP ZAP](https://www.zaproxy.org/) - Web app security scanner
- [Docker Scout](https://docs.docker.com/scout/) - Container CVE scanning

---

**Documentation Version**: 1.0
**Last Updated**: 2025-12-10
**Next Review**: 2025-12-25
