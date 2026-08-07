<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Single source of truth for "who may grant/hold the super-admin role".
 *
 * Shared by UserResource's role picker (options filter), the role-assignment
 * validation rule, and RoleResource's role-name protection. Keeping this in
 * one place is deliberate: the original escalation bug existed because the
 * role list was filtered nowhere except a UI-only ->options() override — a
 * second call site (validation) drifting out of sync with the first is
 * exactly how that class of bug reappears.
 */
class RoleAssignmentGuard
{
    public const PROTECTED_ROLE = 'super-admin';

    public static function canGrant(string $roleName, ?User $actor = null): bool
    {
        if (! self::isProtectedName($roleName)) {
            return true;
        }

        $actor ??= auth()->user();

        return (bool) $actor?->hasRole(self::PROTECTED_ROLE);
    }

    /**
     * Trim and case-fold before comparing.
     *
     * `Super-Admin`, `super-admin ` and similar are rejected by MySQL today only
     * because `roles.name` carries a case-insensitive, PAD SPACE collation — an
     * accident of the schema, not a decision, and one that does not hold under
     * SQLite, which is what the test suite runs on. Normalising here means the
     * guard defends itself instead of leaning on the database engine.
     */
    public static function isProtectedName(string $roleName): bool
    {
        return mb_strtolower(trim($roleName)) === self::PROTECTED_ROLE;
    }

    public static function assignableRolesQuery(?User $actor = null): Builder
    {
        $actor ??= auth()->user();

        $query = Role::query();

        if (! $actor?->hasRole(self::PROTECTED_ROLE)) {
            $query->where('name', '!=', self::PROTECTED_ROLE);
        }

        return $query;
    }
}
