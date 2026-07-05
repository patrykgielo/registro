<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Confirms the given staff_id belongs to a user with the 'staff' role.
 *
 * Does NOT check tenant membership itself — that's handled explicitly in
 * AppointmentController::store() via a separate
 * `Rule::exists('organization_user', 'user_id')->where('organization_id', ...)`
 * check (2026-07 booking integrity review, defense in depth: added
 * deliberately rather than relying only on the invariant below).
 *
 * Background: even without that explicit check, a cross-tenant staff_id
 * could not slip through anyway, because AppointmentService's
 * checkStaffAvailability() → StaffScheduleService::canPerformService() calls
 * `$staff->services()->where('service_id', $serviceId)->exists()` —
 * `services()` is a relation on the Service model, which uses
 * BelongsToOrganization and therefore auto-filters to the CURRENT tenant on
 * every query, including through this pivot. Combined with service_id itself
 * being tenant-scoped, a staff member can only ever be matched to a service
 * in their OWN organization's pivot rows. That invariant is fragile (depends
 * on canPerformService() always being called downstream, and on
 * RequireTenant staying on this route) — the explicit check in the
 * controller no longer relies on it alone.
 */
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
