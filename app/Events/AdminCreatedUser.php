<?php

namespace App\Events;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * @param  \App\Models\Organization|null  $organization  Tenant to brand the password-setup email
 *                                                       for. The two Filament dispatch sites
 *                                                       (UserResource, CreateUser page) run inside
 *                                                       a resolved panel tenant and pass it;
 *                                                       `registro:password-setup-link` (CLI) passes
 *                                                       null deliberately — a user can belong to
 *                                                       MULTIPLE organizations (see that command's
 *                                                       own "Organization(s)" plural output), so
 *                                                       there is no single tenant to safely pick.
 */
class AdminCreatedUser
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public ?Organization $organization = null
    ) {}
}
