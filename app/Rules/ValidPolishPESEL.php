<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPolishPESEL implements ValidationRule
{
    /**
     * Validate Polish PESEL (national identification number).
     *
     * Algorithm:
     * - Must be exactly 11 digits
     * - Weights: [1, 3, 7, 9, 1, 3, 7, 9, 1, 3]
     * - Sum of (digit[i] × weight[i]) for first 10 digits
     * - Control digit (11th) = (10 - (sum mod 10)) mod 10
     * - If control digit doesn't match digit[10] → invalid
     *
     * Note: Unlike NIP/REGON which use mod 11, PESEL uses mod 10 — no "checksum 10" issue.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Remove all non-digit characters (defensive)
        $pesel = preg_replace('/[^0-9]/', '', (string) $value);

        // Must be exactly 11 digits
        if (strlen($pesel) !== 11) {
            $fail('PESEL musi składać się z 11 cyfr.');

            return;
        }

        // Weighted checksum calculation
        $weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $pesel[$i] * $weights[$i];
        }

        $controlDigit = (10 - ($sum % 10)) % 10;

        if ($controlDigit !== (int) $pesel[10]) {
            $fail('Nieprawidłowy numer PESEL (błąd sumy kontrolnej).');
        }
    }
}
