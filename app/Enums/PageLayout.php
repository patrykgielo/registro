<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * CMS Page Layout Types
 *
 * Defines available layout templates for Pages, Posts, and Promotions.
 * Each layout controls content width, sidebar presence, and styling.
 *
 * Usage:
 * - Page/Post/Promotion models cast 'layout' field to this enum
 * - Filament Resources use ->options() for admin selectors
 * - Controllers pass enum to Blade components for rendering
 */
enum PageLayout: string
{
    /**
     * Default layout with 8+4 grid (content + sidebar).
     * Use for: Blog posts, articles with related content sidebar.
     */
    case DEFAULT = 'default';

    /**
     * Full-width edge-to-edge layout (no max-width container).
     * Use for: Landing pages, hero sections, full-bleed galleries.
     */
    case FULL_WIDTH = 'full-width';

    /**
     * Minimal reading-focused layout (max-w-prose, ~65ch).
     * Use for: Long-form articles, documentation, privacy policies.
     */
    case MINIMAL = 'minimal';

    /**
     * Homepage special layout (no article wrapper, full control).
     * Use for: Homepage only, custom marketing pages.
     */
    case HOME = 'home';

    /**
     * Get human-readable label for admin UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::DEFAULT => 'Domyślny (z sidebarami)',
            self::FULL_WIDTH => 'Pełna szerokość',
            self::MINIMAL => 'Minimalny (wąski)',
            self::HOME => 'Strona główna (specjalny)',
        };
    }

    /**
     * Get Tailwind max-width class for layout.
     */
    public function maxWidthClass(): string
    {
        return match ($this) {
            self::DEFAULT => 'max-w-7xl',      // ~1280px
            self::FULL_WIDTH => '',            // No max-width
            self::MINIMAL => 'max-w-prose',    // ~65ch (~700px)
            self::HOME => '',                  // No wrapper
        };
    }

    /**
     * Check if layout supports sidebar.
     */
    public function hasSidebar(): bool
    {
        return match ($this) {
            self::DEFAULT => true,
            self::FULL_WIDTH, self::MINIMAL, self::HOME => false,
        };
    }

    /**
     * Get grid columns for content area.
     */
    public function gridColumns(): string
    {
        return match ($this) {
            self::DEFAULT => 'lg:grid-cols-12',  // 8+4 grid
            self::FULL_WIDTH, self::MINIMAL, self::HOME => 'grid-cols-1',
        };
    }

    /**
     * Get content area column span.
     */
    public function contentSpan(): string
    {
        return match ($this) {
            self::DEFAULT => 'lg:col-span-8',
            self::FULL_WIDTH, self::MINIMAL, self::HOME => 'col-span-1',
        };
    }

    /**
     * Get sidebar column span.
     */
    public function sidebarSpan(): string
    {
        return match ($this) {
            self::DEFAULT => 'lg:col-span-4',
            self::FULL_WIDTH, self::MINIMAL, self::HOME => '',
        };
    }

    /**
     * Check if this is homepage layout.
     */
    public function isHomepage(): bool
    {
        return $this === self::HOME;
    }

    /**
     * Get options array for Filament select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $layout) => [$layout->value => $layout->label()])
            ->toArray();
    }

    /**
     * Get options for specific content type (exclude HOME for Posts/Promotions).
     *
     * @param  string  $contentType  'page', 'post', 'promotion'
     * @return array<string, string>
     */
    public static function optionsFor(string $contentType): array
    {
        $cases = match ($contentType) {
            'page' => self::cases(),
            'post', 'promotion' => array_filter(
                self::cases(),
                fn (self $layout) => $layout !== self::HOME
            ),
            default => self::cases(),
        };

        return collect($cases)
            ->mapWithKeys(fn (self $layout) => [$layout->value => $layout->label()])
            ->toArray();
    }
}
