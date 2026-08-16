<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\Category;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Service;
use Illuminate\Support\Str;

/**
 * Builds `<title>` / meta-description values for content-detail pages,
 * unifying the meta_title/meta_description fallback chain used by
 * Post, PortfolioItem, Page, Service and Category so controllers don't re-implement it.
 */
class MetaTagBuilder
{
    /**
     * @param  array<string, string|null>  $overrides  Explicit values that win over both meta_* fields and fallbacks.
     * @return array{metaTitle: ?string, metaDescription: ?string}
     */
    public static function forModel(Post|PortfolioItem|Page|Service|Category $model, array $overrides = []): array
    {
        $explicitTitle = $model->meta_title;
        $fallbackTitle = self::fallbackTitle($model);

        $title = filled($explicitTitle) ? $explicitTitle : $fallbackTitle;

        if (! filled($explicitTitle) && filled($title)) {
            // brandName(), not config('app.name') — every tenant page's <title>
            // must end with the tenant's own name, not "Registro" (see
            // SettingsManager::brandName() docblock for the fallback chain).
            $title .= ' — '.app(\App\Support\Settings\SettingsManager::class)->brandName();
        }

        $description = $model->meta_description ?? self::fallbackDescription($model);

        return array_merge([
            'metaTitle' => $title,
            'metaDescription' => $description,
        ], $overrides);
    }

    private static function fallbackTitle(Post|PortfolioItem|Page|Service|Category $model): ?string
    {
        return match (true) {
            $model instanceof Service, $model instanceof Category => $model->name,
            default => $model->title,
        };
    }

    private static function fallbackDescription(Post|PortfolioItem|Page|Service|Category $model): ?string
    {
        return match (true) {
            $model instanceof PortfolioItem => Str::limit(strip_tags((string) ($model->body ?? '')), 160) ?: null,
            $model instanceof Page => null,
            $model instanceof Category => $model->description,
            default => $model->excerpt,
        };
    }
}
