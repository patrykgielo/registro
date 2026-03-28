---
paths:
  - "app/Rules/Valid*NIP*"
  - "app/Rules/Valid*PESEL*"
  - "app/Rules/Valid*REGON*"
---

# Polish Tax ID Validation Rules

## CRITICAL: NIP Validation Algorithm

### The Checksum 10 Bug

**ALWAYS check if checksum === 10 and REJECT!**

Polish modulo-11 algorithms (NIP, REGON) can produce checksum of 10, which has NO valid single-digit representation. This is a common bug.

```php
// CRITICAL CHECK - NEVER FORGET THIS!
if ($checksum === 10) {
    $fail('Nieprawidłowy numer (błąd sumy kontrolnej).');
    return;
}
```

### NIP Algorithm Reference

| Step | Description |
|------|-------------|
| 1 | Extract 10 digits (remove all non-digits) |
| 2 | Weights: `[6, 5, 7, 2, 3, 4, 5, 6, 7]` |
| 3 | Multiply digits[0-8] by weights, sum products |
| 4 | Calculate `sum mod 11` |
| 5 | **If result === 10 → INVALID** |
| 6 | If result === digit[9] → VALID |

### Test Cases (MUST include these)

```php
// MUST PASS
'7751001452'      // Valid NIP
'775-100-14-52'   // With dashes (strip them)
'775 100 14 52'   // With spaces (strip them)
'1234563218'      // Another valid

// MUST REJECT
'1234567890'      // Checksum = 10 (CRITICAL!)
'7751001455'      // Wrong checksum (should be 2)
'123456789'       // Too short (9 digits)
'12345678901'     // Too long (11 digits)
''                // Empty
```

---

## Character Stripping

**Always use `/[^0-9]/` to remove ALL non-digits:**

```php
// ✅ CORRECT - removes everything except digits
$nip = preg_replace('/[^0-9]/', '', $value);

// ❌ WRONG - only removes spaces and dashes
$nip = preg_replace('/[\s-]/', '', $value);
// User could enter: "775.100.14.52" and it wouldn't work
```

---

## Incident History

### 2026-02-04: NIP Checksum 10 Bug

**Problem:** `ValidPolishNIP.php` was missing the `checksum === 10` check.

**Impact:** NIPs like `1234567890` were incorrectly accepted.

**Resolution:** Added explicit check and 20 unit tests.

**ADR:** [ADR-022: Polish NIP Validation Algorithm](../../docs/decisions/ADR-022-polish-nip-validation-algorithm.md)

---

---

## PESEL Validation (`app/Rules/ValidPolishPESEL.php`)

Zaimplementowane w `feature/checkout-legal-data` (2026-03-28). Wymagane dla B2C checkout.

```php
// Użycie
'customer_pesel' => ['required', new ValidPolishPESEL()],
```

### Algorytm

- Długość: 11 cyfr
- Wagi: `[1, 3, 7, 9, 1, 3, 7, 9, 1, 3]`
- Cyfra kontrolna (11.): `(10 - (suma mod 10)) mod 10`
- Używa `mod 10` — **brak problemu z checksum = 10**

**PESEL to PII** — zawiera datę urodzenia i płeć. Nigdy nie loguj, nigdy nie eksponuj w błędach API.

---

## REGON Validation (`app/Rules/ValidPolishREGON.php`)

Zaimplementowane w `feature/checkout-legal-data` (2026-03-28). Wymagane dla B2B checkout.

```php
// Użycie
'company_regon' => ['required', new ValidPolishREGON()],
```

### Algorytm 9-cyfrowy
- Wagi: `[8, 9, 2, 3, 4, 5, 6, 7]`
- Checksum = `suma mod 11` — **jeśli = 10 → 0** (jak NIP!)
- Cyfra kontrolna: cyfra[8]

### Algorytm 14-cyfrowy
- Wagi: `[2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8]`
- Checksum = `suma mod 11` — **jeśli = 10 → 0**
- Cyfra kontrolna: cyfra[13]

**REGON używa `mod 11` — ten sam problem checksum = 10 co NIP!**

---

## Validation Class Location

```
app/Rules/ValidPolishNIP.php       # NIP validation (mod 11, checksum≠10)
app/Rules/ValidPolishPESEL.php     # PESEL validation (mod 10, 11 cyfr) — PII!
app/Rules/ValidPolishREGON.php     # REGON 9/14-digit (mod 11, checksum≠10)
```

**Tests location:** `tests/Unit/ValidPolish*Test.php`
