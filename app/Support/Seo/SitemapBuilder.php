<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Enums\ServiceType;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use SimpleXMLElement;

/**
 * Builds the `<urlset>` sitemap XML for a single tenant.
 *
 * Every query is explicitly filtered by `organization_id` on top of the
 * model's own BelongsToOrganization global scope — defense in depth, since
 * this builder could plausibly run outside a per-request tenant context
 * (e.g. a future console command looping over tenants) where the scope's
 * TenantFeature::currentTenant() resolution can't be relied upon.
 */
class SitemapBuilder
{
    public function build(Organization $tenant): string
    {
        $urlset = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>'
        );

        $this->addPages($urlset, $tenant);
        $this->addPosts($urlset, $tenant);
        $this->addPortfolioItems($urlset, $tenant);
        $this->addServices($urlset, $tenant);

        return (string) $urlset->asXML();
    }

    private function addPages(SimpleXMLElement $urlset, Organization $tenant): void
    {
        Page::where('organization_id', $tenant->id)
            ->published()
            ->get()
            ->each(fn (Page $page) => $this->addUrl($urlset, $page->url, $page->updated_at));
    }

    private function addPosts(SimpleXMLElement $urlset, Organization $tenant): void
    {
        $posts = Post::where('organization_id', $tenant->id)
            ->published()
            ->get();

        $posts->each(
            fn (Post $post) => $this->addUrl($urlset, route('post.show', $post->slug), $post->updated_at)
        );

        $this->addCategoryArchives($urlset, $tenant, $posts->pluck('category_id'), 'post.category');
    }

    private function addPortfolioItems(SimpleXMLElement $urlset, Organization $tenant): void
    {
        $items = PortfolioItem::where('organization_id', $tenant->id)
            ->published()
            ->get();

        $items->each(
            fn (PortfolioItem $item) => $this->addUrl($urlset, route('portfolio.show', $item->slug), $item->updated_at)
        );

        $this->addCategoryArchives($urlset, $tenant, $items->pluck('category_id'), 'portfolio.category');
    }

    private function addServices(SimpleXMLElement $urlset, Organization $tenant): void
    {
        // Mirrors ServiceController::index()'s "active" condition:
        // time_slot must be published, item_rental only needs is_active.
        Service::where('organization_id', $tenant->id)
            ->active()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('service_type', ServiceType::TimeSlot->value)->published();
                })->orWhere(function ($q) {
                    $q->where('service_type', ServiceType::ItemRental->value);
                });
            })
            ->get()
            ->each(
                fn (Service $service) => $this->addUrl($urlset, route('service.show', $service->slug), $service->updated_at)
            );
    }

    /**
     * @param  Collection<int, int|null>  $categoryIds
     */
    private function addCategoryArchives(SimpleXMLElement $urlset, Organization $tenant, Collection $categoryIds, string $routeName): void
    {
        $categoryIds = $categoryIds->filter()->unique();

        if ($categoryIds->isEmpty()) {
            return;
        }

        Category::where('organization_id', $tenant->id)
            ->whereIn('id', $categoryIds)
            ->get()
            ->each(
                fn (Category $category) => $this->addUrl($urlset, route($routeName, $category->slug), $category->updated_at)
            );
    }

    private function addUrl(SimpleXMLElement $urlset, string $loc, ?Carbon $lastmod): void
    {
        $url = $urlset->addChild('url');
        $url->addChild('loc', htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8'));

        if ($lastmod) {
            $url->addChild('lastmod', $lastmod->toAtomString());
        }
    }
}
