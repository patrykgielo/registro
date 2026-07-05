<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IMPORTANT (VULN-003 follow-up): reads the tenant from the `tenant` request
 * ATTRIBUTE — set deterministically by ResolveTenant for THIS request — NOT
 * via SettingsManager::isRegistrationEnabled(), which resolves the tenant
 * through TenantFeature::currentTenant(). That helper's 3rd branch falls back
 * to session('tenant_id'), which ResolveTenant writes on every successful
 * subdomain resolution. Gating on the session fallback would let a stale
 * tenant_id from a prior subdomain visit decide THIS request's
 * registration-enabled toggle (e.g. on the root domain, where ResolveTenant
 * intentionally resolves no tenant). Same class of bug as RequireTenant /
 * home-route (see .claude/rules/middleware.md, Layer 5).
 */
class CheckRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');
        $tenant = $tenant instanceof Organization ? $tenant : null;

        if (! app(SettingsManager::class)->isRegistrationEnabledFor($tenant)) {
            return redirect()->route('login')
                ->with('info', 'Rejestracja jest tymczasowo niedostępna.');
        }

        return $next($request);
    }
}
