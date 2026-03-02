<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Menu Location Types
 *
 * Defines where a page should appear in the navigation menu.
 *
 * Usage:
 * - Page model casts 'menu_location' field to this enum
 * - Filament PageResource uses ->options() for admin selector
 * - NavigationService filters pages by location
 */
enum MenuLocation: string
{
    /**
     * Show in header navigation only.
     */
    case HEADER = 'header';

    /**
     * Show in footer navigation only.
     */
    case FOOTER = 'footer';

    /**
     * Show in both header and footer navigation.
     */
    case BOTH = 'both';

    /**
     * Get human-readable label for admin UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::HEADER => 'Header',
            self::FOOTER => 'Footer',
            self::BOTH => 'Header i Footer',
        };
    }

    /**
     * Get options array for Filament select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $location) => [$location->value => $location->label()])
            ->toArray();
    }
}
