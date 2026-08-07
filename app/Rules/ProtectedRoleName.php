<?php

namespace App\Rules;

use App\Support\RoleAssignmentGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Closes the neighboring escalation vector on RoleResource: without this, a
 * non-super-admin with role-management access could rename any role (or
 * create a new one) to "super-admin" — Spatie resolves roles by name, so
 * that alone grants every user holding that role (including the attacker,
 * via UserResource) super-admin, without ever touching the protected name in
 * AssignableRole's picker.
 */
class ProtectedRoleName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! RoleAssignmentGuard::canGrant($value)) {
            $fail('Nie masz uprawnień do nadania nazwy roli Super Admin.');
        }
    }
}
