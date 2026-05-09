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

        // 2. Request context (public pages via ResolveTenant middleware)
        try {
            $request = app('request');
            $tenant = $request->attributes->get('tenant');
            if ($tenant instanceof Organization) {
                return $tenant;
            }
        } catch (\Throwable) {
        }

        // 3. Session fallback — for Livewire update requests that bypass ResolveTenant.
        //    ResolveTenant stores tenant_id in session on every full page request.
        try {
            $tenantId = session('tenant_id');
            if ($tenantId) {
                return Organization::find($tenantId);
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
