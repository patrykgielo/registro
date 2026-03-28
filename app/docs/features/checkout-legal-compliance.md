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
- `customer_pesel` — validated (11-digit, mod-10 checksum)
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

## Notes for Developers

1. **Do not include `deposit_amount` in financial totals** — it is off-balance-sheet
2. **PESEL is PII** — never log it, never expose in error messages
3. **NIP/REGON in API responses** — only show to authorized users (admin, the customer themselves)
4. **Consent timestamps** — immutable once set; never update `rodo_accepted_at` on order update
5. **Electronic contracts** — valid for movable property (ruchomości) in Poland without qualified e-signature
