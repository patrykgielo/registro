<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that belong to an Organization (tenant).
 *
 * Adds:
 * - organization() relationship
 * - Global scope to filter by current tenant (when set)
 * - Auto-assign organization_id on creation
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        // Auto-assign organization_id on creation if in tenant context
        static::creating(function ($model) {
            if (! $model->organization_id && filament()->getTenant()) {
                $model->organization_id = filament()->getTenant()->id;
            }
        });

        // Global scope: filter by current tenant when in Filament panel context
        static::addGlobalScope('organization', function (Builder $builder) {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }

            // Only apply scope when Filament has a tenant set
            try {
                $tenant = filament()->getTenant();
                if ($tenant) {
                    $builder->where(
                        $builder->getModel()->getTable().'.organization_id',
                        $tenant->id
                    );
                }
            } catch (\Throwable) {
                // Filament not booted yet or no panel context — skip scoping
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
