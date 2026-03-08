<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Organization;
use App\Support\TenantFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that belong to an Organization (tenant).
 *
 * Adds:
 * - organization() relationship
 * - Global scope to filter by current tenant (Filament OR public request context)
 * - Auto-assign organization_id on creation
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        // Auto-assign organization_id on creation if in tenant context
        static::creating(function ($model) {
            if (! $model->organization_id) {
                $tenant = TenantFeature::currentTenant();
                if ($tenant) {
                    $model->organization_id = $tenant->id;
                }
            }
        });

        // Global scope: filter by current tenant (both Filament and public request contexts)
        static::addGlobalScope('organization', function (Builder $builder) {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }

            $tenant = TenantFeature::currentTenant();
            if ($tenant) {
                $builder->where(
                    $builder->getModel()->getTable().'.organization_id',
                    $tenant->id
                );
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
