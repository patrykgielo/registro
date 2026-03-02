<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StaffRoleRule implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value) {
            $fail('Pole pracownika jest wymagane.');

            return;
        }

        $user = User::find($value);

        if (! $user) {
            $fail('Wybrany pracownik nie istnieje.');

            return;
        }

        if (! $user->hasRole('staff')) {
            $fail('Tylko użytkownicy z rolą "staff" mogą być przypisani do wizyt.');
        }
    }
}
