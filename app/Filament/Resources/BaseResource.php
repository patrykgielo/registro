<?php

namespace App\Filament\Resources;

use App\Traits\BelongsToOrganization;
use Filament\Resources\Resource;

abstract class BaseResource extends Resource
{
    /**
     * Auto-detect tenant scoping based on whether the model uses BelongsToOrganization trait.
     *
     * Models WITH BelongsToOrganization → scoped to tenant automatically.
     * Models WITHOUT it (Role, CarBrand, User, etc.) → excluded automatically.
     *
     * No manual $isScopedToTenant configuration needed on any Resource.
     */
    public static function isScopedToTenant(): bool
    {
        $model = static::getModel();

        return in_array(BelongsToOrganization::class, class_uses_recursive($model));
    }
}
