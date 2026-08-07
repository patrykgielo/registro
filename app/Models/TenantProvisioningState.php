<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable "has this stack been provisioned" marker for registro:tenant-provision.
 *
 * See the migration (2026_08_06_100001_create_tenant_provisioning_state_table)
 * for why this is a dedicated table rather than "does organizations have a row".
 *
 * No BelongsToOrganization — this table is not tenant data, it describes the
 * stack itself, and must remain readable/writable from console context the same
 * way regardless of tenant resolution.
 */
class TenantProvisioningState extends Model
{
    protected $table = 'tenant_provisioning_state';

    protected $fillable = [
        'organization_id',
        'slug',
        'provisioned_at',
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
    ];

    public static function isProvisioned(): bool
    {
        return static::query()->whereNotNull('provisioned_at')->exists();
    }

    /**
     * Idempotent by design, and called from INSIDE the provisioning transaction.
     *
     * Writing it afterwards would leave a window where the organization is
     * committed (so the client can already log in and customise e-mail
     * templates) but the marker is not — and a kill in that window makes the
     * marker absent for ever, so the next run re-executes EmailTemplateSeeder,
     * whose updateOrCreate silently overwrites exactly those customisations.
     * That is the failure this table exists to prevent, so the write has to
     * share the organization's transaction rather than trail it.
     */
    public static function markProvisioned(Organization $org): self
    {
        return static::query()->firstOrCreate(
            ['organization_id' => $org->id],
            ['slug' => $org->slug, 'provisioned_at' => now()],
        );
    }
}
