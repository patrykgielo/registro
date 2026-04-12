<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * Where to redirect users after login (fallback, rarely used — authenticated() handles most cases).
     */
    protected function redirectTo(): string
    {
        return route('appointments.index');
    }

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
     * After authentication, redirect based on role and tenant context.
     *
     * - Super-admin → Platform panel (/platform)
     * - Admin/Staff on subdomain → Filament admin panel for that tenant
     * - Admin/Staff on root domain → Filament admin panel for their first org
     * - Customer → appointments page
     */
    protected function authenticated(Request $request, $user)
    {
        // Super-admin always goes to platform panel
        if ($user->hasRole('super-admin')) {
            return redirect('/platform');
        }

        // Admin/Staff → Filament admin panel
        if ($user->hasAnyRole(['admin', 'staff'])) {
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
        }

        return redirect()->route('appointments.index');
    }
}
