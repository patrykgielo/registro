<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * User Registered Event
 *
 * Dispatched when a new user successfully registers on the platform.
 * Triggers welcome email notification.
 */
class UserRegistered
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\User  $user  The newly registered user
     */
    public function __construct(
        public User $user
    ) {}
}
