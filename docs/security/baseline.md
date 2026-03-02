# Security Baseline - Registro

**Last Updated**: Template (not yet scanned)
**Laravel Version**: 12.32.5
**PHP Version**: 8.2.29
**Scan Duration**: Not yet performed

---

## 🚀 Initial Setup Required

This is a **template file**. To generate your security baseline, ask the `security-audit-specialist` agent:

```bash
"Generate security baseline"
```

The agent will:
1. Scan entire project (5-7 minutes)
2. Detect vulnerabilities using OWASP Top 10 patterns
3. Compute file checksums for change detection
4. Generate risk profile and compliance status
5. Create vulnerability docs for CRITICAL/HIGH issues
6. Update this file with complete baseline data

---

## What Gets Scanned

**Routes & Endpoints**:
- `routes/web.php` - Authentication, booking, CMS routes
- `routes/api.php` - API endpoints

**Authentication & Authorization**:
- `config/auth.php` - Guards, providers, password settings
- `config/session.php` - Session security configuration
- `app/Http/Middleware/*.php` - Security middleware
- `app/Policies/*.php` - Authorization policies

**Models & Validation**:
- `app/Models/*.php` - Mass assignment vulnerabilities
- `app/Http/Requests/**/*.php` - Input validation coverage

**Infrastructure**:
- `docker-compose.yml` - Exposed ports, default passwords
- `.env.example` - Security configuration patterns
- `.github/workflows/*.yml` - CI/CD security
- `docker/nginx/app.conf` - Security headers

---

## Expected Baseline Structure

After first scan, this file will contain:

### Risk Profile
- Overall rating (CRITICAL/HIGH/MODERATE/LOW)
- Risk score (0-100)
- Vulnerability counts by severity

### OWASP Top 10 Compliance
- Status for each category (Passed/Failed/Partial)
- Notes on findings

### Authentication & Authorization
- Guard configuration
- Session settings
- 2FA status
- Rate limiting status

### Input Validation
- Form Request count
- Validation coverage percentage
- Missing validation on endpoints

### API Security
- Authenticated vs public endpoints
- Rate limiting status
- CSRF protection status

### Infrastructure Security
- Docker port exposure
- Nginx security headers
- SSL/TLS configuration
- Firewall status

### File Checksums (Change Detection)
- SHA256 hashes of monitored files
- Used to detect changes for incremental scans

### Next Actions
- Prioritized list of fixes (CRITICAL → LOW)

---

## Quick Start

```bash
# Ask the security agent
"Generate security baseline"

# Or trigger a full audit
"Run full security audit"

# Agent will populate this file with real data
```

---

**Status**: ⏳ Awaiting first scan
**Invoke**: `security-audit-specialist` agent to generate baseline
