# ADR-022: Polish NIP Validation Algorithm

**Status:** Accepted
**Date:** 2026-02-04
**Context:** Booking Wizard Invoice Feature

---

## Problem

NIP (Numer Identyfikacji Podatkowej) validation was incorrectly accepting invalid NIPs where the checksum calculation resulted in 10.

### Bug Details

Polish NIP algorithm:
1. Take first 9 digits and multiply by weights: `[6, 5, 7, 2, 3, 4, 5, 6, 7]`
2. Sum the products
3. Calculate `sum mod 11`
4. If result equals digit[9] (10th digit) → valid

**Critical edge case:** If `sum mod 11 = 10`, there is **NO valid control digit** (only single digits 0-9 exist). Such NIP is INVALID.

### Example of Bug

NIP `1234567890`:
- Checksum calculation: (1×6 + 2×5 + 3×7 + 4×2 + 5×3 + 6×4 + 7×5 + 8×6 + 9×7) mod 11
- = (6 + 10 + 21 + 8 + 15 + 24 + 35 + 48 + 63) mod 11
- = 230 mod 11
- = **10**

This NIP should be REJECTED, but old implementation accepted it because it only checked `checksum === digit[9]`.

---

## Decision

### 1. Add explicit check for checksum === 10

```php
// CRITICAL: Checksum of 10 is INVALID (no digit "10" exists)
if ($checksum === 10) {
    $fail('Nieprawidłowy numer NIP (błąd sumy kontrolnej).');
    return;
}
```

### 2. Improve character stripping regex

```php
// OLD: Only removed spaces and dashes
$nip = preg_replace('/[\s-]/', '', $value);

// NEW: Remove ALL non-digit characters
$nip = preg_replace('/[^0-9]/', '', $value);
```

### 3. Make NIP required for VAT invoices

```php
'invoice_nip' => ['required_if:invoice_requested,1,true', 'nullable', 'string', 'max:13', new ValidPolishNIP],
```

---

## Consequences

### Positive
- Invalid NIPs are now correctly rejected
- More flexible input parsing (dots, parentheses, etc. now stripped)
- VAT invoice requires NIP (business logic correctness)

### Negative
- None identified

---

## Files Changed

| File | Change |
|------|--------|
| `app/Rules/ValidPolishNIP.php` | Added checksum === 10 check, improved regex |
| `app/Http/Controllers/BookingController.php` | NIP `required_if` validation |
| `resources/views/booking-wizard/steps/contact.blade.php` | Alpine.js validation, UI label |
| `tests/Unit/ValidPolishNIPTest.php` | **NEW** - 20 comprehensive unit tests |

---

## Test Coverage

Created 20 unit tests including:
- Valid NIPs (with/without formatting)
- Invalid checksums
- **Checksum of 10 rejection** (critical test)
- Wrong length (9 or 11 digits)
- Non-digit characters
- Empty value

---

## References

- [Polish NIP Algorithm (Wikipedia PL)](https://pl.wikipedia.org/wiki/Numer_identyfikacji_podatkowej)
- PR #230: fix(booking): NIP validation bug fix and make NIP required
