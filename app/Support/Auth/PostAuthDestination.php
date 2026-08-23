<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Where a freshly-authenticated user belongs.
 *
 * Extracted from LoginController::authenticated() so the password-reset flow
 * reaches the SAME destination as a normal login. Resetting a password logs the
 * user in (Laravel's ResetsPasswords::resetPassword() calls guard()->login()),
 * so the two flows end in the same state and had no business disagreeing about
 * where that state should land.
 *
 * They did disagree: ResetPasswordController carried `$redirectTo = '/home'`,
 * and no route named or pathed `/home` exists in this application — the route
 * named `home` is `/`. Verified end-to-end against the real reset flow: the
 * password was set correctly, then the user was redirected to `/home` and got
 * a 404, on their own tenant subdomain, with no way back to their panel.
 *
 * This is NOT a pure URL computation — it also settles the intended-destination
 * session keys, because Filament reads the same `url.intended` key and a value
 * left over from browsing as a customer would otherwise bounce an admin login
 * somewhere random. Every branch decides explicitly: discard, or consume.
 */
final class PostAuthDestination
{
    /**
     * Absolute or root-relative URL to send $user to after authenticating.
     */
    public static function for(Request $request, User $user): string
    {
        // Super-admin always goes to the platform panel.
        if ($user->hasRole('super-admin')) {
            IntendedDestination::discard($request);

            return '/platform';
        }

        if ($user->hasAnyRole(['admin', 'staff'])) {
            IntendedDestination::discard($request);

            $tenant = $request->attributes->get('tenant');

            // On a tenant subdomain: that tenant's panel, if they may reach it.
            if ($tenant instanceof Organization && $user->canAccessTenant($tenant)) {
                return '/admin';
            }

            // On the root domain: the subdomain of their first organization.
            $firstOrg = $user->organizations()->first();
            if ($firstOrg) {
                return self::tenantAdminUrl($firstOrg, $request);
            }

            // No organization at all — no admin panel exists to send them to.
            return route('home');
        }

        return IntendedDestination::consume($request) ?? CustomerLandingUrl::for($request);
    }

    /**
     * Build the admin panel URL on a tenant's subdomain.
     */
    private static function tenantAdminUrl(Organization $org, Request $request): string
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $baseDomain = config('app.domain', 'registro.local');
        $port = $request->getPort();

        $portSuffix = '';
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $portSuffix = ':'.$port;
        }

        return "{$scheme}://{$org->slug}.{$baseDomain}{$portSuffix}/admin";
    }
}
