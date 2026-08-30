<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MenuLocation;
use App\Enums\PageLayout;
use App\Support\Settings\SettingsManager;
use App\Traits\BelongsToOrganization;
use App\Traits\NormalizesEmptyJsonToNull;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Page extends Model
{
    use BelongsToOrganization, NormalizesEmptyJsonToNull;

    protected $fillable = [
        'organization_id',
        'title',
        'slug',
        'body',
        'content',
        'layout',
        'published_at',
        'meta_title',
        'meta_description',
        'featured_image',
        'show_in_menu',
        'menu_order',
        'menu_label',
        'menu_location',
    ];

    protected $casts = [
        'content' => 'array',
        'published_at' => 'datetime',
        'layout' => PageLayout::class,
        'show_in_menu' => 'boolean',
        'menu_order' => 'integer',
        'menu_location' => MenuLocation::class,
    ];

    /**
     * Reserved slugs that cannot be used for pages.
     * These conflict with existing routes.
     */
    public const RESERVED_SLUGS = [
        'admin',
        'uslugi',
        'aktualnosci',
        'promocje',
        'portfolio',
        'kontakt',
        'rezerwacja',
        'horizon',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'verify-email',
        'profil',
        'health',
        'storage',
        'api',
        'livewire',
        'filament',
    ];

    /**
     * @return array<int, string>
     */
    protected function normalizeEmptyJsonToNullFields(): array
    {
        return ['content'];
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeEmptyHtmlToNullFields(): array
    {
        return ['body'];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($page) {
            if (empty($page->slug)) {
                $page->slug = Str::slug($page->title);
            }
        });

        static::updating(function ($page) {
            if ($page->isDirty('title') && empty($page->slug)) {
                $page->slug = Str::slug($page->title);
            }
        });

        // Cache invalidation for navigation menu
        static::saved(function () {
            Cache::forget('navigation.pages.header');
            Cache::forget('navigation.pages.footer');
        });

        static::deleted(function () {
            Cache::forget('navigation.pages.header');
            Cache::forget('navigation.pages.footer');
        });
    }

    /**
     * Scope for published pages.
     */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope for draft pages.
     */
    public function scopeDraft($query)
    {
        return $query->whereNull('published_at')
            ->orWhere('published_at', '>', now());
    }

    /**
     * Scope for pages shown in menu.
     */
    public function scopeInMenu(Builder $query, ?string $location = null): Builder
    {
        return $query->where('show_in_menu', true)
            ->when($location, function (Builder $q) use ($location) {
                $q->where(function (Builder $inner) use ($location) {
                    $inner->where('menu_location', $location)
                        ->orWhere('menu_location', MenuLocation::BOTH->value);
                });
            })
            ->orderBy('menu_order');
    }

    /**
     * Check if page is published.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * Check if page is draft.
     */
    public function isDraft(): bool
    {
        return ! $this->isPublished();
    }

    /**
     * Check if this page is set as the homepage.
     */
    public function getIsHomepageAttribute(): bool
    {
        $settingsManager = app(SettingsManager::class);
        $homepageId = $settingsManager->get('cms.homepage_page_id');

        return $homepageId !== null && (int) $homepageId === $this->id;
    }

    /**
     * Get the URL for this page.
     * Returns "/" for homepage, otherwise the page route.
     */
    public function getUrlAttribute(): string
    {
        if ($this->is_homepage) {
            return route('home');
        }

        return route('page.show', $this->slug);
    }
}
