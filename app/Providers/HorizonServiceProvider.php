<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Shared stack: super-admin only. Dedicated tenant stack (TENANT_SLUG set): there is NO
     * super-admin user and never will be, so a super-admin-only gate is a 403 for everyone —
     * the tenant's own `admin` (plus `super-admin`, should one ever exist) may open it.
     * The provider itself must stay registered: the same image runs the queue worker.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(
                config('app.tenant_slug') ? ['admin', 'super-admin'] : ['super-admin']
            );
        });
    }
}
