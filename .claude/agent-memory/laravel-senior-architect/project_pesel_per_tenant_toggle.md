---
name: project-pesel-per-tenant-toggle
description: PESEL required-ness became a per-tenant toggle (checkout.pesel_required, default false); found signatory_id_number is an unvalidated PESEL-collection side-door not covered by it
metadata:
  type: project
---

Branch `feature/pesel-per-tenant-toggle` (2026-08-17), from `develop`. Made PESEL requirement for
natural-person checkout a per-tenant `SettingsManager` toggle (`checkout.pesel_required`, default
`false`), replacing the hardcoded `required_if:customer_type,natural_person`. Full mechanism in
`app/docs/features/checkout-legal-compliance.md` → "PESEL Requirement Toggle".

**Why:** product decision — PESEL stays in the system (still checksum-validated whenever present)
but collecting it becomes opt-in per tenant. It was never actually used for anything downstream
(not on handover/return protocol PDFs, contrary to what the old checkout hint text claimed) — data
minimization, RODO Art. 5(1)(c).

**How to apply:** `Rule::requiredIf(closure)` is the right tool for "required, but only under a
condition resolved at runtime (here: a Settings read), combined with `nullable` + a checksum rule
that must still run either way." Stringifies to `'required'` or `''` (empty rule, silently dropped
by Laravel's rule parser) — confirmed by reading `vendor/laravel/framework/.../Rules/RequiredIf.php`
directly rather than assuming, since this project's `filament-settings-pages.md`/`tests.md` rules
have a strong "verify Laravel behavior from source, don't guess" precedent. The validation message
key for a triggered `RequiredIf` is `'required'`, not `'required_if'` — easy to miss when editing
the `messages()` array.

**Found in verification, NOT fixed here (flagged in the PR as a separate item):**
`signatory_id_number` (B2B/business flow, `SubmitCheckoutRequest.php`) is free-text with zero
validation, and its own placeholder in `checkout/show.blade.php` explicitly invites entering a
PESEL there ("PESEL lub numer dowodu osobistego" / `"np. ABC123456 lub 12345678901"`). This toggle
only gates `customer_pesel` (natural-person flow) — business checkouts can and likely do collect
unvalidated PESELs through this completely different field. Next PESEL-adjacent task should
probably start here.

**Pre-existing, unrelated quirk hit while writing the business-customer test:** `orders.customer_first_name`/`customer_last_name` are NOT NULL at the DB level even though
`SubmitCheckoutRequest` only requires them `required_if:customer_type,natural_person` — a business
checkout that omits them 500s on insert, not on validation. Test payload works around it by keeping
those fields populated rather than unsetting them (matches what the real Alpine form always sends
anyway, since both customer-type sections share one `x-data` scope). Did not fix — outside this
task's scope, but worth knowing before touching B2B checkout again.
