# VULN-009: Low-Severity Cleanup Batch

**Status**: FIXED
**Severity**: LOW
**Detected**: 2026-07-04 (multi-agent security review, 13 review domains)
**Fixed**: 2026-07-05
**Branch**: `fix/low-severity-cleanup`

Four small, independent findings, the last phase of the 2026-07-04 remediation effort.

## 1. VehicleDataController lookup endpoints had no rate limiting
`GET /api/vehicle-types`/`car-brands`/`car-models`/`vehicle-years` sat behind `auth`+`ResolveTenant`
only. Data is global reference data (no tenant scope), so this was a defense-in-depth gap, not a
leak. Added `throttle:60,1,vehicle-data` — given its own rate-limiter bucket rather than sharing
Laravel's default identifier-only throttle key with several unrelated route groups already using
bare `throttle:60,1` elsewhere in `routes/web.php` (a pre-existing quirk, fixed here for this group
since it was already being touched).

## 2. OrderController::cancel() duplicated OrderService::cancel()
Now delegates (`$this->orderService->cancel($order, 'Anulowane przez klienta')`) instead of
inlining the state transition — confirmed no observable behavior change for the customer
self-cancel endpoint (verified `OrderService::cancel()` contains only the identical transition +
timestamp logic, no additional side effects reachable from this guarded endpoint).

## 3. iCal ATTENDEE/ORGANIZER CN parameter not properly escaped
`CalendarService`'s `escapeIcalText()` (RFC 5545 TEXT-property backslash-escaping) was applied to
the `CN` **parameter** value — a different grammar entirely. An unquoted iCal parameter value
cannot contain `;`/`:`/`,`/control characters at all, and there is no backslash-escape mechanism
for them — the first-pass fix's `Jan\;RSVP=TRUE` output left the raw `;` physically present,
so a semicolon in a customer's name could still inject/forge additional parameters onto the
ATTENDEE line (a narrower "parameter injection" surviving the original CRLF-injection fix).

Fixed properly per RFC 5545 §3.2: new `CalendarService::sanitizeIcalParam()` wraps the value in a
quoted-string and *strips* (not escapes — none exists) the characters forbidden inside one (`"`
and control characters, which covers CR/LF). `;`/`:`/`,` are preserved unescaped inside the
quotes, which is valid there. Applied to both `ATTENDEE;CN=` and `ORGANIZER;CN=` for consistency.

## 4. VULN-003 doc correction (no code change)
The doc's own premise — "no in-app navigation path to /login from the root domain" — was
factually wrong: the root-domain home-fallback page renders the standard nav header, which has a
direct "Zaloguj" link. Corrected the doc and added a regression test proving the load-bearing
assumption it actually depends on: the customer-redirect target (`appointments.index`) still
404s under Layer 3's `RequireTenant`, so the corrected premise doesn't change the risk assessment.

## Verification

Independent review caught that the first-pass iCal fix used the wrong RFC grammar (and that its
own test asserted the broken output as correct) — fixed properly per above. Full suite: 787
passed, 3 pre-existing unrelated failures (baseline unchanged), 5 skipped.

**Related**: [VULN-003](VULN-003-root-domain-tenant-bypass.md), closing out the full 9-phase
remediation of the 2026-07-04 multi-agent security review (VULN-004 through VULN-009).
