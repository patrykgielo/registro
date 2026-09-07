<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;

class TenantFeature
{
    /**
     * Check if a feature is active for the current tenant.
     */
    public static function active(string $feature): bool
    {
        $tenant = static::currentTenant();

        return $tenant?->hasFeature($feature) ?? false;
    }

    /**
     * Resolve the current tenant from available contexts.
     */
    public static function currentTenant(): ?Organization
    {
        // 1. Filament context (admin panel)
        try {
            if (function_exists('filament') && $tenant = filament()->getTenant()) {
                if ($tenant instanceof Organization) {
                    return $tenant;
                }
            }
        } catch (\Throwable) {
        }

        // 2. Request context (public pages via ResolveTenant middleware). ResolveTenant
        //    now runs in the base 'web' middleware group (VULN-003 Layer 7), so this
        //    branch covers Livewire's /livewire/update requests directly too — the
        //    outer request re-resolves the tenant from its own real Host header before
        //    Livewire ever dispatches to a component. No session fallback needed.
        try {
            $request = app('request');
            $tenant = $request->attributes->get('tenant');
            if ($tenant instanceof Organization) {
                return $tenant;
            }
        } catch (\Throwable) {
        }

        // 3. Test-only escape hatch (VULN-003 Layer 8, 2026-08-31) — NOT a production
        //    fallback. `Livewire::test(SomeFilamentPage::class)` (used by ~20 existing
        //    Filament resource tests) never dispatches through the HTTP kernel at all, so
        //    branches 1 (Filament panels here never enable native tenancy — no
        //    ->tenant() call in either PanelProvider) and 2 above have nothing to read:
        //    no real request was ever built, let alone routed through ResolveTenant.
        //    Those tests' only way to say "run this component as tenant X" is
        //    `session(['tenant_id' => $tenant->id])` before `Livewire::test(...)` — removing
        //    this branch entirely would break that established pattern across the whole
        //    Filament test suite, not just this file.
        //
        //    Both guards below are load-bearing, independently:
        //    - `runningUnitTests()` (APP_ENV=testing) is `false` in any real deployment —
        //      this whole branch is structurally unreachable outside the PHPUnit process.
        //    - `tenant_resolution_attempted` being ABSENT means ResolveTenant genuinely
        //      never ran for this request. A REAL HTTP test request (e.g. this repo's own
        //      VULN-003 regression suite, which drives real Host headers through the real
        //      middleware — see NavigationCacheTenantIsolationTest's docblock) sets that
        //      marker even when it resolves no tenant (root domain) — and this branch must
        //      NOT rescue that case with a poisoned session, or it silently reintroduces
        //      the exact bug Layer 8 removed, but only in tests, making every regression
        //      test in this file pass for the wrong reason. Falsified empirically: with
        //      this guard removed, NavigationCacheTenantIsolationTest's root-domain case
        //      fails again exactly like it did before this fix existed.
        if (
            app()->runningUnitTests()
            && ! (app()->bound('request') && app('request')->attributes->get('tenant_resolution_attempted') === true)
        ) {
            try {
                $tenantId = session('tenant_id');
                if ($tenantId) {
                    return Organization::find($tenantId);
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
