<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new business signed up and became a tenant of this installation.
 *
 * Deliberately separate from UserRegistered, which fires when an end CUSTOMER
 * creates an account on a tenant's site. The two look similar and mean entirely
 * different things: conflating them is how the business registration ended up
 * silently sending nothing at all, while the customer flow had a welcome e-mail
 * all along.
 */
class TenantRegistered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Organization $organization,
        public User $owner,
    ) {}
}
