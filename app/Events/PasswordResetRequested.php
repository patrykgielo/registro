<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Password Reset Requested Event
 *
 * Dispatched when a user requests a password reset.
 * Triggers password reset email with token.
 */
class PasswordResetRequested
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\User  $user  The user requesting password reset
     * @param  string  $token  The password reset token
     */
    public function __construct(
        public User $user,
        public string $token
    ) {}
}
