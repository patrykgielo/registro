<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

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

        // Admin/Staff → Filament admin panel with correct tenant slug
        if ($user->hasAnyRole(['admin', 'staff'])) {
            $tenant = $request->attributes->get('tenant');

            // On subdomain: use that tenant (if user has access)
            if ($tenant instanceof Organization && $user->canAccessTenant($tenant)) {
                return redirect("/admin/{$tenant->slug}");
            }

            // On root domain: redirect to first accessible org
            $firstOrg = $user->organizations()->first();
            if ($firstOrg) {
                return redirect("/admin/{$firstOrg->slug}");
            }
        }

        return redirect()->route('appointments.index');
    }
}
