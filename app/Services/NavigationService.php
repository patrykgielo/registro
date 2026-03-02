<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MenuLocation;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing frontend navigation menus.
 *
 * Retrieves pages marked for menu display with caching.
 * Cache is invalidated automatically when pages are saved/deleted.
 */
class NavigationService
{
    /**
     * Cache TTL in seconds (30 minutes).
     */
    private const CACHE_TTL = 1800;

    /**
     * Get menu items for a specific location.
     *
     * @param  string  $location  Menu location ('header', 'footer')
     * @return Collection<int, array{label: string, url: string, active: bool}>
     */
    public function getMenuItems(string $location = 'header'): Collection
    {
        return Cache::remember(
            "navigation.pages.{$location}",
            self::CACHE_TTL,
            fn () => $this->fetchMenuItems($location)
        );
    }

    /**
     * Fetch menu items from database.
     *
     * @param  string  $location  Menu location
     * @return Collection<int, array{label: string, url: string, active: bool}>
     */
    private function fetchMenuItems(string $location): Collection
    {
        return Page::query()
            ->published()
            ->where('show_in_menu', true)
            ->where(function ($query) use ($location) {
                $query->where('menu_location', $location)
                    ->orWhere('menu_location', MenuLocation::BOTH->value);
            })
            ->orderBy('menu_order')
            ->select('id', 'title', 'slug', 'menu_label', 'menu_order')
            ->get()
            ->map(fn (Page $page) => [
                'label' => $page->menu_label ?: $page->title,
                'url' => $page->url,
                'active' => request()->url() === $page->url,
            ]);
    }

    /**
     * Clear navigation cache for all locations.
     */
    public function clearCache(): void
    {
        Cache::forget('navigation.pages.header');
        Cache::forget('navigation.pages.footer');
    }
}
