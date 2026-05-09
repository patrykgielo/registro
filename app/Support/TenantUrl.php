<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;

/**
 * Helper for generating tenant-aware URLs with correct subdomain.
 *
 * Used in notifications, emails, and any context where URLs must point
 * to the correct tenant subdomain.
 */
class TenantUrl
{
    /**
     * Generate a URL for a specific tenant's subdomain.
     *
     * Example: TenantUrl::route($org, 'appointments.index')
     * → https://demo.registro.app/my-appointments
     */
    public static function route(Organization $tenant, string $routeName, array $parameters = []): string
    {
        $path = route($routeName, $parameters, false); // relative path

        return static::url($tenant, $path);
    }

    /**
     * Generate a base URL for a tenant's subdomain with an optional path.
     *
     * Example: TenantUrl::url($org, '/my-appointments')
     * → https://demo.registro.app/my-appointments
     */
    public static function url(Organization $tenant, string $path = ''): string
    {
        $scheme = config('app.env') === 'local' ? 'https' : 'https';
        $baseDomain = config('app.domain', 'registro.local');
        $port = parse_url(config('app.url'), PHP_URL_PORT);

        $portSuffix = '';
        if ($port && $port !== 443 && $port !== 80) {
            $portSuffix = ':'.$port;
        }

        return "{$scheme}://{$tenant->slug}.{$baseDomain}{$portSuffix}{$path}";
    }

    /**
     * Generate the Filament admin panel URL for a tenant.
     *
     * Example: TenantUrl::admin($org)
     * → https://demo.registro.app/admin/demo
     */
    public static function admin(Organization $tenant): string
    {
        return static::url($tenant, "/admin/{$tenant->slug}");
    }
}
