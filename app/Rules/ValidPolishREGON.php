<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPolishREGON implements ValidationRule
{
    /**
     * Validate Polish REGON (business statistical number).
     *
     * Supports both formats:
     * - 9-digit (individual/single-unit entities)
     * - 14-digit (legal entities / sub-units)
     *
     * 9-digit algorithm:
     * - Weights: [8, 9, 2, 3, 4, 5, 6, 7]
     * - Control digit (9th) = (sum mod 11) mod 10
     * - CRITICAL: if sum mod 11 === 10 → control digit is 0 (unlike NIP, this IS valid for REGON 9-digit)
     *
     * 14-digit algorithm:
     * - Weights: [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8]
     * - Control digit (14th) = (sum mod 11) mod 10
     * - CRITICAL: same rule — mod 11 = 10 yields control digit 0
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Remove all non-digit characters (defensive)
        $regon = preg_replace('/[^0-9]/', '', (string) $value);

        $length = strlen($regon);

        if ($length === 9) {
            $this->validate9($regon, $fail);
        } elseif ($length === 14) {
            $this->validate14($regon, $fail);
        } else {
            $fail('REGON musi składać się z 9 lub 14 cyfr.');
        }
    }

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    private function validate9(string $regon, Closure $fail): void
    {
        $weights = [8, 9, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $regon[$i] * $weights[$i];
        }

        // For REGON: (sum mod 11) mod 10 gives the control digit (handles the "10" case by mapping to 0)
        $controlDigit = ($sum % 11) % 10;

        if ($controlDigit !== (int) $regon[8]) {
            $fail('Nieprawidłowy numer REGON (błąd sumy kontrolnej).');
        }
    }

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    private function validate14(string $regon, Closure $fail): void
    {
        $weights = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
        $sum = 0;

        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $regon[$i] * $weights[$i];
        }

        // Same rule: (sum mod 11) mod 10
        $controlDigit = ($sum % 11) % 10;

        if ($controlDigit !== (int) $regon[13]) {
            $fail('Nieprawidłowy numer REGON (błąd sumy kontrolnej).');
        }
    }
}
