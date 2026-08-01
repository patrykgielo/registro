# Security Baseline — moved

This file used to be an unfilled template ("Last Updated: Template (not yet scanned)",
Laravel 12.32.5 / PHP 8.2.29). It predated every real security finding in this project and,
sitting next to genuine documents, read like a sign-off artifact for a scan that had never
run.

**The real baseline is `app/docs/security/baseline.md`.**

Related, also under `app/docs/security/`:

- `vulnerabilities/` — VULN-001…009, including the VULN-003 root-domain tenant bypass and
  its six defence layers
- `audit-reports/` — the July 2026 remediation round
- `patterns/` — reusable hardening patterns (e.g. Livewire tenant isolation)

Note that the baseline there carries its own caveat: it is dated 2025-12-10, which is
*before* the VULN-003…009 remediation. Regenerate it before treating it as a go-live
sign-off. See `app/docs/deployment/production-readiness-checklist.md` §4.

## Why this file still exists

The rest of this directory is **not** a duplicate — `compliance.md`,
`content-security-policy.md`, `patterns/file-upload-security.md`, the two `SECURITY-FIX-*`
documents and `vulnerabilities/README.md` exist only here and are served by the MkDocs
portal. Whether to merge this tree into `app/docs/` or keep it deliberately separate is an
open decision (§6 of the readiness checklist), so only the misleading placeholder was
replaced.
