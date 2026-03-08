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

    /**
     * Get available Heroicon solid icons for selection.
     *
     * Dynamically scans blade-heroicons package for s-* (solid) icons.
     *
     * @return array<string, string> Icon name => Human-readable label
     */
    protected static function getHeroiconOptions(): array
    {
        $iconPath = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');
        $files = glob($iconPath.'/s-*.svg');

        if (empty($files)) {
            return [
                'sparkles' => 'Sparkles',
                'rectangle-stack' => 'Rectangle Stack',
                'paint-brush' => 'Paint Brush',
                'sun' => 'Sun',
                'squares-plus' => 'Squares Plus',
                'swatch' => 'Swatch',
                'beaker' => 'Beaker',
                'shield-check' => 'Shield Check',
            ];
        }

        $icons = [];
        foreach ($files as $file) {
            $name = str_replace('.svg', '', basename($file));
            $name = str_replace('s-', '', $name);
            $icons[$name] = ucwords(str_replace('-', ' ', $name));
        }

        asort($icons);

        return $icons;
    }
}
