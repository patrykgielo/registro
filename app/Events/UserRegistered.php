<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Organization;
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
     * @param  \App\Models\Organization|null  $organization  The tenant this registration happened
     *                                                       on, resolved from the request by
     *                                                       RegisterController::registered(). Both the
     *                                                       form and the submit refuse a tenant-less
     *                                                       (root domain) request, so a customer
     *                                                       registration always carries one; null
     *                                                       remains possible for users created by
     *                                                       other code or tooling. Threaded through so
     *                                                       the welcome email is branded for that
     *                                                       tenant; keep the null guard, do not assume
     *                                                       non-null.
     */
    public function __construct(
        public User $user,
        public ?Organization $organization = null
    ) {}
}
