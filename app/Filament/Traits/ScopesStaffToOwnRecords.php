<?php

declare(strict_types=1);

namespace App\Filament\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Own-records access for Resources whose model has a `user_id` (the staff member it describes).
 *
 * `staff` (granted staff.manage_availability / staff.view_availability by the seeder) sees,
 * creates, edits and deletes ONLY records with their own user_id; `admin` / `super-admin` keep
 * everything. Same shape as StaffVacationPeriodResource's query filter.
 *
 * Used by a Resource extending BaseResource: the trait's can*() methods win over the
 * deny-by-default admin-only ones inherited from it. The Create/Edit pages must call
 * forceOwnUserId() so the `user_id` the form posts cannot be tampered with.
 */
trait ScopesStaffToOwnRecords
{
    /**
     * True for a user who only has the staff role (an admin who also holds `staff` is not limited).
     */
    protected static function isStaffLimitedToOwnRecords(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasRole('staff')
            && ! $user->hasAnyRole(['admin', 'super-admin']);
    }

    /**
     * The `user_id` a write must carry: the actor's own for limited staff, the submitted one otherwise.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forceOwnUserId(array $data): array
    {
        if (static::isStaffLimitedToOwnRecords()) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    protected static function mayManageRecord(?Model $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'super-admin'])) {
            return true;
        }

        return $user->hasRole('staff')
            && ($record === null || (int) $record->getAttribute('user_id') === (int) $user->id);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return static::isStaffLimitedToOwnRecords()
            ? $query->where($query->getModel()->getTable().'.user_id', auth()->id())
            : $query;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::mayManageRecord($record);
    }

    public static function canCreate(): bool
    {
        return static::mayManageRecord(null);
    }

    public static function canEdit(Model $record): bool
    {
        return static::mayManageRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::mayManageRecord($record);
    }

    /**
     * Bulk delete is offered to staff. What they can select is bounded by getEloquentQuery()
     * above: selected ids are resolved through the scoped table query, so a colleague's id
     * (even a forged one) is simply not found.
     */
    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
    }
}
