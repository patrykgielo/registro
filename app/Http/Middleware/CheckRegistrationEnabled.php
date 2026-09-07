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
 * through TenantFeature::currentTenant(). Until Layer 8 (2026-08-31), that
 * helper's 3rd branch fell back to session('tenant_id'), which ResolveTenant
 * writes on every successful subdomain resolution — a stale tenant_id from a
 * prior subdomain visit could then decide THIS request's registration-enabled
 * toggle (e.g. on the root domain, where ResolveTenant intentionally resolves
 * no tenant). That branch is now gone from every real HTTP request (only a
 * narrow Livewire::test()-only escape hatch remains, unreachable outside
 * APP_ENV=testing — see TenantFeature::currentTenant()); the request
 * attribute is kept anyway as the one signal scoped to THIS request. Same
 * class of bug as RequireTenant / home-route (see .claude/rules/middleware.md,
 * Layer 5).
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
