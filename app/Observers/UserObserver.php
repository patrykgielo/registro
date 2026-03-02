<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserObserver
{
    /**
     * Handle the User "updated" event.
     *
     * NOTE: Role change detection was removed because:
     * - getOriginal('roles') doesn't work for BelongsToMany relations
     * - Roles are stored in pivot table (model_has_roles), not user columns
     * - Previous implementation incorrectly cleared ALL sessions on ANY user update
     *
     * If role change session invalidation is needed in future, implement via:
     * - Spatie Permission events (RoleAttached, RoleDetached)
     * - Custom listener on syncRoles/assignRole calls
     *
     * @see https://spatie.be/docs/laravel-permission/v6/advanced-usage/events
     */
    public function updated(User $user): void
    {
        // Reserved for future security-critical field change detection
        // Example: invalidate sessions if email or password changes
        // Currently no action needed - role changes use different mechanism
    }

    /**
     * Handle the User "deleting" event.
     *
     * Runs BEFORE user deletion to clean up sensitive tokens.
     */
    public function deleting(User $user): void
    {
        // Clear all password/email/deletion tokens before user is deleted
        $user->update([
            'password_setup_token' => null,
            'password_setup_expires_at' => null,
            'pending_email_token' => null,
            'pending_email_expires_at' => null,
            'deletion_token' => null,
        ]);

        \Log::info('User tokens cleared before deletion', [
            'user_id' => $user->id,
            'user' => $user->email,
        ]);
    }

    /**
     * Handle the User "deleted" event.
     *
     * Clears all sessions when user is deleted.
     */
    public function deleted(User $user): void
    {
        $deletedCount = DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        \Log::info('User sessions cleared due to account deletion', [
            'user_id' => $user->id,
            'user' => $user->email,
            'sessions_cleared' => $deletedCount,
        ]);
    }
}
