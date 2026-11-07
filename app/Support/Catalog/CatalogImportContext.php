<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\Organization;
use Closure;

/**
 * Runs a console operation "as" a tenant. In console BelongsToOrganization neither
 * scopes reads nor — worse — knows the tenant for writes made by OTHER models' hooks:
 * Auditable's AuditLog::create() would store organization_id = NULL and the rows would
 * be invisible in the tenant's audit log. TenantFeature::currentTenant() reads the
 * request attribute, so set it for the duration of the run and put it back after.
 */
final class CatalogImportContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asTenant(Organization $org, Closure $callback): mixed
    {
        $attributes = app('request')->attributes;
        $had = $attributes->has('tenant');
        $previous = $attributes->get('tenant');

        $attributes->set('tenant', $org);

        try {
            return $callback();
        } finally {
            $had ? $attributes->set('tenant', $previous) : $attributes->remove('tenant');
        }
    }
}
