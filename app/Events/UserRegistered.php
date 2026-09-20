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
     *                                                       on, when RegisterController::registered()
     *                                                       could resolve one from the request. The
     *                                                       GET form redirects away with no tenant,
     *                                                       but a direct POST to the root domain is
     *                                                       NOT currently blocked the same way
     *                                                       (separate, already-flagged gap — not
     *                                                       fixed here) — so this can legitimately be
     *                                                       null even for a "real" registration, not
     *                                                       just dev/test tooling. Threaded through so
     *                                                       the welcome email can be branded for that
     *                                                       tenant when one is known; RegisterController
     *                                                       already passes null defensively otherwise
     *                                                       — keep that guard, do not assume non-null.
     */
    public function __construct(
        public User $user,
        public ?Organization $organization = null
    ) {}
}
