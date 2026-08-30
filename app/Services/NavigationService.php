<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MenuLocation;
use App\Models\Organization;
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
            $this->cacheKey($location),
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
     *
     * @param  int|null  $tenantId  Explicit tenant id to clear. Pass this when the caller
     *                              already knows the exact tenant a change belongs to (e.g.
     *                              a model observer working off $model->organization_id) —
     *                              relying on the ambient TenantFeature::currentTenant() there
     *                              would clear the WRONG bucket for console/queue writes that
     *                              have no resolved "current" tenant. Left null for
     *                              caller-context clears (admin panel action, tests).
     */
    public function clearCache(?int $tenantId = null): void
    {
        Cache::forget($this->cacheKey('header', $tenantId));
        Cache::forget($this->cacheKey('footer', $tenantId));
    }

    /**
     * Tenant-scoped cache key (fixes cross-tenant navigation leak on shared stack).
     *
     * Mirrors the pattern in ServiceAreaValidator::cacheKey() — the query behind
     * fetchMenuItems() is already correctly scoped by Page's BelongsToOrganization trait,
     * but that scoping is on the RESULT, not the cache KEY, and the key is what decides who
     * gets served that result. A bare "navigation.pages.{location}" key meant the first
     * tenant to warm it for up to CACHE_TTL dictated every other tenant's menu.
     *
     * Falls back to a shared 'none' bucket when no tenant is resolved. Unlike
     * ServiceAreaValidator's routes (behind RequireTenant), navigation renders on every
     * public page INCLUDING the root domain, where there genuinely is no tenant — so the
     * fallback bucket is reachable in normal operation, not just console/edge cases, and
     * must not leak tenant-specific content into it.
     *
     * Deliberately reads the request attribute directly instead of
     * TenantFeature::currentTenant() (the ServiceAreaValidator pattern this otherwise
     * mirrors) — currentTenant()'s 3rd fallback branch reads session('tenant_id'), which
     * ResolveTenant writes on EVERY subdomain visit, including anonymous ones. A visitor
     * who merely browsed tenant A's subdomain earlier in the same browser session and then
     * lands on the root domain would have their session's stale tenant id resolve here,
     * serving A's cached menu on what is supposed to be a neutral, tenant-less page —
     * empirically confirmed while writing this fix's own tests (see
     * NavigationCacheTenantIsolationTest's class docblock). This is the same failure class
     * already fixed in routes/web.php's home route and documented across VULN-003
     * Layers 1/2/5 — see that route's own "public home route" docblock and
     * models.md's "tenant_resolution_attempted" section.
     */
    private function cacheKey(string $location, ?int $tenantId = null): string
    {
        $tenantId ??= $this->requestTenantId();

        return "navigation.pages.{$location}.".($tenantId ?? 'none');
    }

    private function requestTenantId(): ?int
    {
        try {
            $tenant = app('request')->attributes->get('tenant');

            return $tenant instanceof Organization ? $tenant->id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
