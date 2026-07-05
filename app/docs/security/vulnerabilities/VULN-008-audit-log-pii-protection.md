# VULN-008: Audit Log PII Protection (Encryption + Export Masking)

**Status**: FIXED
**Severity**: MEDIUM
**Detected**: 2026-07-04 (multi-agent security review, 13 review domains)
**Fixed**: 2026-07-05
**Branch**: `fix/auth-and-audit-hardening`

## Problem

`Order::$auditInclude`/`User::$auditInclude` include PESEL/ID-document fields (`customer_pesel`,
`signatory_id_number`, `pickup_person_id_number`, `pesel`). Every create/update wrote the
plaintext value into `audit_logs.old_values`/`new_values` (JSON, no encryption). Access was
already correctly restricted to super-admin — this fix is about blast radius, not widening
access: `AuditLogResource`'s bulk CSV export streamed these fields unmasked, and the export
action itself wasn't audited.

## Rozwiązanie

- New `EncryptedJsonCast` on `AuditLog::$old_values`/`$new_values` — encrypts the stored blob at
  rest. Precisely scoped threat model (confirmed in review, not overclaimed): this protects
  against a raw DB dump/backup leak, NOT against an already-authenticated super-admin session
  (which transparently decrypts on every read, as it must to remain useful for investigation).
  Falls back to `json_decode()` for legacy pre-migration plaintext rows, now with a
  `Log::warning()` (record id/model only, never the value) so a genuine decryption failure
  (corruption, botched key rotation) is distinguishable from a known legacy row instead of
  silently vanishing.
- Migration changes the columns from MySQL native `json` to `longText` (ciphertext isn't valid
  JSON) — confirmed no other code path uses MySQL JSON functions against these columns.
- New `AuditFieldMasker` masks PESEL/ID fields to last-4-digits, applied ONLY in the CSV export
  path (`ExportAuditLogsToCsv`, extracted from the Filament Resource) — the in-app table/view
  stays unmasked (already role-gated, investigators need real values), only the higher-blast-radius
  exportable-file path is masked. The export action now fires `AuditLog::EVENT_EXPORTED`.

## Related fix in the same branch: StaffDateException/StaffSchedule/StaffVacationPeriod date-comparison bug

While fixing `StaffDateException`'s overlap-precedence bug (undefined winner between an all-day
exception and a time-specific override for the same date — now resolved via a deterministic
two-pass check, time-specific first), a driver-portability bug was found and fixed: `date`-cast
columns serialize to full `Y-m-d H:i:s`, which MySQL's native `DATE` column silently truncates on
write (masking the bug) but SQLite does not — so raw string `where('col', '<=', $date->format('Y-m-d'))`
comparisons silently broke on SQLite (the test-suite driver), meaning this whole domain had no
real boundary-day test coverage. Fixed via `whereDate()` in `StaffDateException::scopeOnDate()`,
and — found by the same review, empirically reproduced — the identical bug in
`StaffVacationPeriod::scopeOverlapping()`/`scopeIncludesDate()` and
`StaffSchedule::scopeEffectiveOn()` (both incorrectly excluded their own boundary day).

## Also in this branch

- Session regeneration (`session()->regenerate()`) added after `Auth::login()` in both
  registration flows (`BusinessRegisterController`, `RegisterController`), matching the existing
  login flow's session-fixation defense.
- VULN-001 (missing rate limiting) closed out fully — the remaining unthrottled GET booking
  routes (`booking.step`, `booking.change-service`, `booking.restore-progress`,
  `booking.unavailable-dates`, `booking.create`, `booking.slots`) now carry `throttle:60,1` (or
  `throttle:20,1` for the two heavier-computation endpoints).

## Verification

Two independent review rounds. Full suite: 807 passed, 3 pre-existing unrelated failures
(baseline unchanged), 5 skipped.

## Zapobieganie

- A `date` Eloquent cast serializes to a full datetime string — never compare it with a raw
  string equality/inequality against `Y-m-d`; always use `whereDate()`.
- Encryption-at-rest for audit data should fail loudly (logged), not silently, on decrypt
  failure — a tamper-evidence system that quietly loses data on corruption defeats its purpose.
