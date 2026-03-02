<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;

class AssignCustomerRole
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        // Assign 'customer' role to newly registered user
        $event->user->assignRole('customer');
    }
}
