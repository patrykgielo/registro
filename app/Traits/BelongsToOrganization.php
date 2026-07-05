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

                return;
            }

            // No tenant resolved. Fail closed ONLY if ResolveTenant genuinely ran for
            // this request (real HTTP/feature-test request through a ResolveTenant-
            // guarded route) and still found nothing — VULN-003 Layer 2. Bare Unit/
            // Feature tests that never dispatch through ResolveTenant keep today's
            // no-op behavior (unaffected).
            if (app()->bound('request') && app('request')->attributes->get('tenant_resolution_attempted') === true) {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
