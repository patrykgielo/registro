# Security Documentation Hub

**Last Updated**: 2025-11-30
**Maintained By**: security-audit-specialist agent
**Project**: Registro Car Detailing Application

---

## Quick Navigation

### 🛡️ Baseline & Compliance

- **[Security Baseline](baseline.md)** - Current security posture, risk profile, OWASP compliance
- **[Compliance Checklist](compliance.md)** - GDPR + OWASP Top 10 status

### 🔴 Known Vulnerabilities

- **[Vulnerabilities Index](vulnerabilities/README.md)** - All known security issues

**Critical Issues** (immediate action required):
- [VULN-001: Missing Rate Limiting](vulnerabilities/VULN-001-missing-rate-limiting.md) 🔴 **CRITICAL**
- [VULN-003: Exposed Docker Ports](vulnerabilities/VULN-003-exposed-docker-ports.md) 🔴 **CRITICAL**

**High Priority**:
- [VULN-002: Plaintext API Tokens](vulnerabilities/VULN-002-plaintext-api-tokens.md) 🟠 **HIGH**
- [VULN-004: Mass Assignment (User Model)](vulnerabilities/VULN-004-mass-assignment-user-model.md) 🟠 **HIGH**
- [VULN-005: No Webhook Signature Verification](vulnerabilities/VULN-005-no-webhook-signatures.md) 🟠 **HIGH**

### 🔧 Remediation Guides

**Common Fixes**:
- [Rate Limiting](remediation-guides/rate-limiting.md) - Protect endpoints from brute force
- [Authorization Policies](remediation-guides/authorization-policies.md) - Implement access control
- [SQL Injection Prevention](remediation-guides/sql-injection-prevention.md) - Secure database queries
- [XSS Prevention](remediation-guides/xss-prevention.md) - Escape user output safely
- [Field Encryption](remediation-guides/field-encryption.md) - Encrypt sensitive data

### 📊 Audit Reports

- [Initial Baseline (2025-11-30)](audit-reports/2025-11-30-initial-baseline.md) - First security scan
- [Pre-Deployment Audits](audit-reports/) - Historical security checks

### 🔧 Security Fixes

- [SECURITY-FIX-001: Booking Confirmation ID Exposure](SECURITY-FIX-001-booking-confirmation.md) - Session-based confirmation (2025-12-12)
- [SECURITY-FIX-002: Service Area Cleanup](SECURITY-FIX-002-service-area-cleanup.md) - Data integrity & seeder idempotency (2025-12-14)

### 🎯 Project-Specific Patterns

**Registro Security Patterns**:
- [Service Layer Security](patterns/service-layer-security.md) - AppointmentService, EmailService patterns
- [Maintenance Mode Security](patterns/maintenance-mode-security.md) - Redis state, secret tokens, bypass logic
- [Filament Authorization](patterns/filament-authorization.md) - Spatie Permission + Policies integration

---

## Using the Security Agent

### Quick Commands

```bash
# Generate initial baseline (first time)
Ask agent: "Generate security baseline"

# Check specific component
Ask agent: "Is my booking endpoint secure?"

# Pre-deployment audit
Ask agent: "Run pre-deployment security audit"

# Fix vulnerability
Ask agent: "Fix VULN-001" (hands off to laravel-senior-architect)
```

### Ad-Hoc Security Questions

```markdown
# Examples
"How do I prevent SQL injection in Laravel?"
"Is my Docker setup secure?"
"What security headers should I add?"
"How do I encrypt API tokens?"
"Is my authentication configuration secure?"
```

### Agent Capabilities

✅ **Answer ad-hoc security questions** (<30 second response using cached baseline)
✅ **Audit code for OWASP Top 10 vulnerabilities**
✅ **Provide remediation templates with code examples**
✅ **Maintain security baseline** (smart caching with file checksums)
✅ **Track compliance** (GDPR, OWASP, Laravel best practices)
✅ **Collaborate with laravel-senior-architect** for implementation

---

## Security Workflow

### 1. Initial Setup (First Time)

```bash
# Ask agent to generate baseline
"Generate security baseline"

# Agent scans entire project (5-7 minutes)
# Creates baseline.md, vulnerability docs, compliance checklist
```

### 2. Daily Development

```bash
# Ask security questions as you code
"Is this endpoint secure?"
"How do I validate file uploads?"

# Agent provides instant answers using cached baseline
# Response time: <30 seconds
```

### 3. Before Deployment

```bash
# Run pre-deployment audit
"Run pre-deployment security audit"

# Agent:
# 1. Detects changed files (checksums)
# 2. Runs incremental scan (1-2 min)
# 3. Updates baseline with new findings
# 4. Generates audit report
# 5. Blocks deployment if CRITICAL issues found
```

### 4. Fixing Vulnerabilities

```bash
# Ask agent to fix specific vulnerability
"Fix VULN-001"

# Agent:
# 1. Reads vulnerability doc
# 2. Provides detailed remediation plan
# 3. Offers to hand off to laravel-senior-architect
# 4. Verifies fix after implementation
# 5. Updates baseline, marks VULN as MITIGATED
```

---

## Risk Profile (Current State)

**Overall Rating**: 🟡 **MODERATE** (Acceptable for MVP, hardening recommended)
**Risk Score**: 45/100
**Last Updated**: 2025-11-30

| Severity | Count | Status |
|----------|-------|--------|
| 🔴 CRITICAL | 2 | Immediate action required |
| 🟠 HIGH | 3 | Fix within 1-2 weeks |
| 🟡 MEDIUM | 5 | Fix within 1 month |
| 🟢 LOW | 8 | Monitor, fix as capacity allows |

**See**: [baseline.md](baseline.md) for full details

---

## OWASP Top 10 Compliance (Quick View)

| Category | Status | Priority |
|----------|--------|----------|
| A01: Broken Access Control | ⚠️ Partial | Fix policies (HIGH) |
| A02: Cryptographic Failures | 🔴 Failed | Encrypt tokens (CRITICAL) |
| A03: Injection | ✅ Passed | Maintain (LOW) |
| A04: Insecure Design | 🔴 Failed | Add rate limiting (CRITICAL) |
| A05: Security Misconfiguration | 🔴 Failed | Remove exposed ports (CRITICAL) |
| A06: Vulnerable Components | ✅ Passed | Monitor dependencies (LOW) |
| A07: Authentication Failures | ⚠️ Partial | Add 2FA (MEDIUM) |
| A08: Integrity Failures | ⚠️ Partial | Webhook signatures (HIGH) |
| A09: Logging Failures | ⚠️ Partial | Add audit logging (MEDIUM) |
| A10: SSRF | ✅ Passed | Maintain (LOW) |

**See**: [compliance.md](compliance.md) for detailed checklist

---

## GDPR Compliance (Quick View)

| Requirement | Status | Notes |
|-------------|--------|-------|
| Data Minimization | ✅ Passed | Only necessary fields collected |
| Consent Tracking | ✅ Passed | marketing_consent, sms_consent, email_consent |
| Right to Erasure | ⚠️ Partial | Needs account deletion implementation |
| Data Encryption | ⚠️ Partial | Encrypt personal data fields |
| Audit Logging | 🔴 Failed | No data access audit trail |
| Data Retention | ⚠️ Partial | Define retention policies |

**See**: [compliance.md](compliance.md) for full GDPR checklist

---

## Emergency Contacts

### Security Incident Response

**If you discover a security breach**:

1. **Immediate**: Enable maintenance mode
   ```bash
   docker compose exec app php artisan maintenance:enable --type=emergency --message="Security incident"
   ```

2. **Notify**: Project owner/security lead

3. **Assess**: Ask security agent for impact assessment
   ```bash
   "Security incident: [description]. What's the impact?"
   ```

4. **Contain**: Follow agent recommendations

5. **Document**: Create incident report in `audit-reports/`

6. **GDPR**: If personal data breach, notify authorities within 72 hours (Article 33)

---

## Contributing to Security Documentation

### Adding New Vulnerability

```bash
# Ask agent to create VULN doc
"Create vulnerability doc for [issue description]"

# Agent will:
# 1. Assign VULN-XXX number
# 2. Assess severity (CRITICAL/HIGH/MEDIUM/LOW)
# 3. Identify OWASP category
# 4. Create vulnerability doc with template
# 5. Update this README.md
# 6. Update baseline.md
```

### Adding Remediation Guide

```bash
# Ask agent to create remediation guide
"Create remediation guide for [security topic]"

# Agent will:
# 1. Use template format
# 2. Include Laravel-specific code examples
# 3. Add to remediation-guides/
# 4. Update this README.md
```

---

## Maintenance Schedule

### Weekly

- [ ] Review open vulnerabilities (vulnerabilities/README.md)
- [ ] Check for Laravel/Composer security updates (`composer audit`)
- [ ] Review failed login attempts (if logging enabled)

### Before Each Deployment

- [ ] Run pre-deployment security audit
- [ ] Verify all CRITICAL vulnerabilities resolved
- [ ] Update baseline.md
- [ ] Create audit report

### Monthly

- [ ] Full security baseline refresh (even if no changes)
- [ ] Review and update compliance.md
- [ ] Check for new OWASP guidelines
- [ ] Review project-specific patterns for updates

### Quarterly

- [ ] Comprehensive security audit (all OWASP categories)
- [ ] GDPR compliance review
- [ ] Penetration testing (if budget allows)
- [ ] Security training for development team

---

## Resources

### Internal Documentation

- [Project Map](../project_map.md) - System topology, modules, key files
- [Deployment History](../deployment/deployment-history.md) - Infrastructure changes
- [ADR-012: GitHub Actions Security](../deployment/ADR-012-github-actions-security-hardening.md)
- [ADR-007: UFW-Docker Security](../deployment/ADR-007-ufw-docker-security.md)

### External Resources

- **Laravel Security**: https://laravel.com/docs/security
- **OWASP Top 10 2021**: https://owasp.org/Top10/
- **OWASP Cheat Sheets**: https://cheatsheetseries.owasp.org/
- **GDPR Guidelines**: https://gdpr.eu/developers/
- **Filament Security**: https://filamentphp.com/docs/panels/resources#authorization
- **Spatie Permission**: https://spatie.be/docs/laravel-permission/

---

## Changelog

### 2025-12-12 - Critical Security Fix
- **FIXED:** SECURITY-FIX-001 - Booking confirmation ID exposure vulnerability
- Replaced sequential ID URLs with single-use session tokens
- Fixed authorization bug (user_id vs customer_id)
- Enhanced confirmation page UX with improved navigation
- GDPR compliance restored (no personal data exposure)

### 2025-11-30 - Initial Setup
- Created security documentation structure
- Generated initial security baseline
- Documented 5 known vulnerabilities
- Created 5 remediation guides
- Established OWASP + GDPR compliance tracking

---

**For security questions or incidents, invoke the `security-audit-specialist` agent.**
