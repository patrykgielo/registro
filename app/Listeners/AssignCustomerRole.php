<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use Spatie\Permission\Models\Role;

class AssignCustomerRole
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $event->user->assignRole('customer');
    }
}
