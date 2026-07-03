<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;

/**
 * Shared observer for the models aggregated into SitemapBuilder's per-tenant
 * sitemap. Registered on Post, PortfolioItem and Page (AppServiceProvider) so
 * publishing/unpublishing/deleting content invalidates the cached sitemap
 * within the same request, instead of waiting up to an hour for
 * SitemapController's Cache::remember TTL to expire.
 */
class SitemapCacheObserver
{
    public function saved(Post|PortfolioItem|Page $model): void
    {
        $this->forget($model);
    }

    public function deleted(Post|PortfolioItem|Page $model): void
    {
        $this->forget($model);
    }

    private function forget(Post|PortfolioItem|Page $model): void
    {
        if ($model->organization_id) {
            Cache::forget("sitemap:{$model->organization_id}");
        }
    }
}
