<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use App\Support\TenantUrl;
use Illuminate\Http\Request;

/**
 * Default landing page for a customer role after login/registration, used
 * only when there is no (or an untrusted) captured IntendedDestination.
 *
 * Deliberately reads $request->attributes->get('tenant') — set by
 * ResolveTenant for THIS request only — never TenantFeature::currentTenant(),
 * which falls back to session('tenant_id') and would resolve whichever
 * tenant subdomain this browser last happened to visit (VULN-003 class of
 * bug), not the tenant this login actually belongs to.
 */
class CustomerLandingUrl
{
    public static function for(Request $request): string
    {
        $tenant = $request->attributes->get('tenant');

        if ($tenant instanceof Organization) {
            return route(static::routeNameForOrg($tenant, checkBookingEnabled: true));
        }

        // Root domain: no tenant on THIS request, but the user may still
        // belong to exactly one (customers only ever register on a
        // subdomain). Send them to that tenant's own landing page rather
        // than a dead root-domain route.
        $org = $request->user()?->organizations()->first();

        if ($org instanceof Organization) {
            return TenantUrl::route($org, static::routeNameForOrg($org, checkBookingEnabled: false));
        }

        return route('home');
    }

    /**
     * $checkBookingEnabled must be false when $org is NOT the tenant of the
     * current request — SettingsManager::isBookingEnabled() resolves the
     * tenant via TenantFeature::currentTenant(), which has no way to know
     * about $org in that case and would evaluate the wrong tenant's setting.
     */
    private static function routeNameForOrg(Organization $org, bool $checkBookingEnabled): string
    {
        $bookingEnabled = ! $checkBookingEnabled || app(SettingsManager::class)->isBookingEnabled();

        if ($org->supportsAppointments() && $bookingEnabled) {
            return 'appointments.index';
        }

        if ($org->supportsRentals()) {
            return 'orders.index';
        }

        return 'profile.index';
    }
}
