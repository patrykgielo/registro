# Checkout Legal Compliance — Polish Equipment Rental

**Implemented:** 2026-03-28, branch `feature/checkout-legal-data`

---

## Legal Basis

Polish equipment rental requires compliance with:

| Act | Requirement |
|-----|-------------|
| Art. 659 KC | Written or equivalent rental contract |
| Art. 126 § 2 pkt 1 KPC | PESEL in legal documents for natural persons |
| Art. 13 RODO / GDPR | Inform data subject at collection point |
| Art. 38(1)(12) UoPK | Inform consumer that withdrawal right is excluded for dated services |
| Ustawa o VAT | Kaucja is NOT subject to VAT (refundable security deposit) |

---

## Customer Types

### B2C — natural_person
Required fields:
- `customer_first_name`, `customer_last_name` — contract parties
- `customer_pesel` — **required only if the tenant opted in** (`checkout.pesel_required`, default `false` — see "PESEL Requirement Toggle" below). Always validated (11-digit, mod-10 checksum) when present, required or not.
- Address: `customer_street`, `customer_building`, `customer_apartment`, `customer_city`, `customer_postal_code`

### B2B — business
Required fields:
- `invoice_company_name` — company name on contract
- `invoice_nip` — NIP (validated by `ValidPolishNIP` rule, mod-11 checksum)
- `company_regon` — validated by `ValidPolishREGON` (9-digit or 14-digit, mod-11)
- `company_krs` — optional, KRS registry number
- `company_contact_name` — name of authorized signatory
- Billing address: `invoice_street`, `invoice_street_number`, `invoice_postal_code`, `invoice_city`
- Invoice is always requested for B2B (`invoice_requested = true` forced)

---

## PESEL Validation (`app/Rules/ValidPolishPESEL.php`)

```
Length: exactly 11 digits
Weights: [1, 3, 7, 9, 1, 3, 7, 9, 1, 3]
Control digit (11th): (10 - (sum(digit_i * weight_i) % 10)) % 10
```

---

## REGON Validation (`app/Rules/ValidPolishREGON.php`)

**9-digit:**
```
Weights: [8, 9, 2, 3, 4, 5, 6, 7]
Control (9th digit): sum % 11, if result == 10 → 0
```

**14-digit:**
```
Weights: [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8]
Control (14th digit): sum % 11, if result == 10 → 0
```

---

## Kaucja (Security Deposit)

### Key facts
- NOT a payment — it is a refundable security deposit
- NOT subject to VAT (does NOT appear on VAT invoice)
- NOT included in `orders.total_amount`
- Tracked separately in `deposit_amount` + `deposit_status`
- Collected physically at equipment pickup

### Deposit lifecycle
```
not_required (deposit_amount = 0)
    OR
pending → collected → returned
                    ↘ partial_return
                    ↘ forfeited
```

### Admin actions (Filament OrderResource)
- **Pobrano kaucję** — sets `collected`, records `deposit_collected_at`
- **Zwrócono kaucję** — sets `returned`, records `deposit_returned_at`
- **Kaucja przepadła** — sets `forfeited`, requires reason in `deposit_notes`

### Service-level deposit
`Service` model has `deposit_amount` (per-day or per-item, DECIMAL). CartService calculates order deposit as:
```php
$depositTotal = cart_items.sum(item->service->deposit_amount * item->quantity)
```

---

## Required Consents (3 checkboxes)

All three are `required|accepted` in `SubmitCheckoutRequest`. Timestamps + IP are stored on acceptance:

| Field | Legal basis | Stored as |
|-------|-------------|-----------|
| `rodo_accepted` | Art. 13 RODO | `rodo_accepted_at` + `rodo_accepted_ip` |
| `terms_accepted` | Contract obligation | `terms_accepted_at` |
| `withdrawal_exclusion_accepted` | Art. 38(1)(12) UoPK | `withdrawal_exclusion_accepted_at` |

---

## Profile Pre-fill

`CheckoutController::show()` builds `$profileData` from the authenticated user and passes it to Alpine.js `x-data`. Users can check "Zapisz dane do profilu" to persist data back to `users` table via `CartService::saveProfileData()`.

Fields saved to profile: `customer_type`, `pesel`, `regon`, `krs`, address fields, billing fields.

---

## Key Files

| File | Purpose |
|------|---------|
| `app/Rules/ValidPolishPESEL.php` | PESEL checksum validation |
| `app/Rules/ValidPolishREGON.php` | REGON 9/14-digit validation |
| `app/Rules/ValidPolishNIP.php` | NIP validation (pre-existing) |
| `app/Http/Requests/Checkout/SubmitCheckoutRequest.php` | B2C/B2B validation rules + Polish messages |
| `app/Services/Cart/CartService.php` | `convertToOrder()` — maps all legal fields, calculates deposit |
| `app/Http/Controllers/CheckoutController.php` | `show()` — builds `$profileData` for pre-fill |
| `resources/views/checkout/show.blade.php` | B2C/B2B form with Alpine.js toggle, consent checkboxes |
| `database/migrations/2026_03_28_000001_*` | New `users` columns: customer_type, pesel, regon, krs |
| `database/migrations/2026_03_28_000002_*` | New `orders` columns: all legal + deposit fields |
| `database/migrations/2026_03_28_000003_*` | New `order_items` column: deposit_amount |

---

## Checkout Settings (Admin Panel — `feature/checkout-settings`)

Tenant admins can manage all consent texts in **Panel Admina → Ustawienia → zakładka Checkout**.

| Setting key | Field | Default |
|-------------|-------|---------|
| `checkout.terms_url` | URL Regulaminu | `''` (brak linku) |
| `checkout.privacy_policy_url` | URL Polityki prywatności | `''` (brak linku) |
| `checkout.terms_label` | Tekst zgody na Regulamin | hardcoded fallback |
| `checkout.rodo_label` | Tekst zgody RODO (`{org_name}` placeholder) | hardcoded fallback |
| `checkout.withdrawal_label` | Tekst wyłączenia prawa odstąpienia | hardcoded fallback |
| `checkout.deposit_policy_note` | Notatka o kaucji | hardcoded fallback |

Settings are **fully per-tenant** via `SettingsManager`. URL fields: when non-empty, a "Przeczytaj regulamin" / "Polityka prywatności" link is appended after the consent text automatically in the view.

---

## PESEL Requirement Toggle (`feature/pesel-per-tenant-toggle`, 2026-08-17)

`checkout.pesel_required` (Toggle in **Panel Admina → Ustawienia → Checkout → Dane osobowe**),
read via `SettingsManager::isPeselRequired()`. **Default `false`.**

### Why default off — data minimization

Despite Art. 126 § 2 pkt 1 KPC listing PESEL as identifying data for court documents, this
application never actually uses the number for anything once collected:

- It does **not** appear on the handover or return protocol PDFs
  (`resources/views/orders/protocols/{handover,return}.blade.php`) — staff at pickup cannot see it
  without opening the admin panel, so it cannot serve as an in-person identity check.
- It is not read by any invoicing, audit, or export code path beyond storing/masking it as PII
  (`AuditFieldMasker`, `OrganizationAnonymizationService`).

Collecting a national ID number "just in case" without a concrete downstream use is exactly what
RODO's data-minimization principle (Art. 5(1)(c)) exists to prevent. The setting makes collection
an explicit, informed choice each tenant makes for their own contracts — rather than a default
every tenant inherited from a single early implementation decision.

### Behavior

| `checkout.pesel_required` | Natural-person customer | Business customer |
|---|---|---|
| `false` (default) | Field shown, optional. If filled, still checksum-validated. | Never required — the toggle only ever applies to `customer_type=natural_person`. |
| `true` | Field shown, required (asterisk, `aria-required="true"`, `required_if`-equivalent server validation via `Rule::requiredIf`). | Same as above — unaffected. |

Server-side: `SubmitCheckoutRequest::rules()` builds the `customer_pesel` rule as
`[Rule::requiredIf(fn () => customer_type === natural_person && $settings->isPeselRequired()), 'nullable', new ValidPolishPESEL]`
— i.e. the checksum rule (`ValidPolishPESEL`) always runs when a value is present, independent of
whether it was mandatory. A tenant turning this off never means "accept garbage PESELs."

Front-end: `CheckoutController::show()` passes `$peselRequired` (from
`SettingsManager::isPeselRequired()`) to `checkout/show.blade.php`, which conditionally renders the
asterisk / `(opcjonalnie)` label, `aria-required`, and the hint text under the field. The hint text
was corrected in this change — it previously claimed the number was "required for identity
verification at pickup," which was never true (see above); it now states the real reason
(contract) without implying a process the code doesn't perform.

### Extending to another field

The setting is deliberately **not** a generic `checkout.required_fields` JSON blob — it follows the
existing flat-boolean shape of this settings group (`settlement_online_enabled`,
`settlement_offline_enabled`). A future per-field toggle (e.g. for `company_krs`) should follow the
same mechanical pattern: `checkout.{field}_required` setting key, `is{Field}Required()` on
`SettingsManager`, a `Rule::requiredIf` on that field in `SubmitCheckoutRequest`, and a
`${field}Required`-driven conditional in the Blade view — not a new structure.

### Known gap — NOT fixed in this change

`signatory_id_number` (business/B2B flow, `SubmitCheckoutRequest.php`) is a free-text field with
**no validation at all**, and its own placeholder text in `checkout/show.blade.php` explicitly
suggests entering a PESEL there ("PESEL lub numer dowodu osobistego", placeholder
`"np. ABC123456 lub 12345678901"`). This means B2B checkouts can and likely do collect PESELs
through a completely different, unvalidated field that this toggle does not gate. Flagged for a
follow-up change — out of scope here because it is a separate field with a separate validation gap,
not a toggle-plumbing problem.


## Notes for Developers

1. **Do not include `deposit_amount` in financial totals** — it is off-balance-sheet
2. **PESEL is PII** — never log it, never expose in error messages
3. **NIP/REGON in API responses** — only show to authorized users (admin, the customer themselves)
4. **Consent timestamps** — immutable once set; never update `rodo_accepted_at` on order update
5. **Electronic contracts** — valid for movable property (ruchomości) in Poland without qualified e-signature
