<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Auth\CustomerLandingUrl;
use App\Support\Auth\IntendedDestination;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * Build the admin panel URL on a tenant's subdomain.
     */
    private function tenantAdminUrl(Organization $org, Request $request): string
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

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Show the login form. Captures where the visitor came from (see
     * IntendedDestination) before rendering — must happen here, not only in
     * authenticated(), to cover a voluntary click on a "Zaloguj się" link
     * (no AuthenticationException, so Laravel's own url.intended capture
     * never fires).
     */
    public function showLoginForm(Request $request)
    {
        IntendedDestination::capture($request);

        return view('auth.login');
    }

    /**
     * After authentication, redirect based on role and tenant context.
     *
     * - Super-admin → Platform panel (/platform)
     * - Admin/Staff on subdomain → Filament admin panel for that tenant
     * - Admin/Staff on root domain → Filament admin panel for their first org
     * - Admin/Staff without any organization → home (nothing to send them to)
     * - Customer → captured IntendedDestination, else CustomerLandingUrl
     *
     * Every branch except the customer one discards the intended-destination
     * session keys explicitly: Filament reads the same `url.intended` key,
     * and a value left over from browsing as a customer earlier in this
     * session would otherwise bounce an admin/staff login somewhere random.
     */
    protected function authenticated(Request $request, $user)
    {
        // Super-admin always goes to platform panel
        if ($user->hasRole('super-admin')) {
            IntendedDestination::discard($request);

            return redirect('/platform');
        }

        // Admin/Staff → Filament admin panel
        if ($user->hasAnyRole(['admin', 'staff'])) {
            IntendedDestination::discard($request);

            $tenant = $request->attributes->get('tenant');

            // On subdomain: use that tenant (if user has access)
            if ($tenant instanceof Organization && $user->canAccessTenant($tenant)) {
                return redirect('/admin');
            }

            // On root domain: redirect to first accessible org's subdomain
            $firstOrg = $user->organizations()->first();
            if ($firstOrg) {
                return redirect($this->tenantAdminUrl($firstOrg, $request));
            }

            // No organization at all — no admin panel exists to send them to.
            return redirect()->route('home');
        }

        // Customer
        return redirect(IntendedDestination::consume($request) ?? CustomerLandingUrl::for($request));
    }
}
