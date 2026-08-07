<?php

namespace App\Rules;

use App\Support\RoleAssignmentGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Role;

/**
 * Server-side twin of RoleAssignmentGuard::assignableRolesQuery() (used for
 * the UserResource role picker's ->options()). The picker only stops a
 * well-behaved browser from *offering* super-admin; Filament's relationship
 * Select is dehydrated(false) when multiple(), so mutateFormDataBeforeSave()
 * never sees the submitted role IDs — this rule, attached directly to the
 * field, is what actually runs during $this->form->getState() on every
 * create()/save() call, regardless of what the client sent as form state.
 */
class AssignableRole implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $roleIds = array_filter((array) $value);

        if ($roleIds === []) {
            return;
        }

        $requestedNames = Role::query()->whereIn('id', $roleIds)->pluck('name');

        foreach ($requestedNames as $name) {
            if (! RoleAssignmentGuard::canGrant($name)) {
                $fail('Nie masz uprawnień do nadania roli Super Admin.');

                return;
            }
        }
    }
}
