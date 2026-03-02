<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PortfolioItem extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'body',
        'content',
        'category_id',
        'before_image',
        'after_image',
        'gallery',
        'published_at',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'content' => 'array',
        'gallery' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($item) {
            if (empty($item->slug)) {
                $item->slug = Str::slug($item->title);
            }
        });

        static::updating(function ($item) {
            if ($item->isDirty('title') && empty($item->slug)) {
                $item->slug = Str::slug($item->title);
            }
        });
    }

    /**
     * Get the category for this portfolio item.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope for published portfolio items.
     */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope for draft portfolio items.
     */
    public function scopeDraft($query)
    {
        return $query->whereNull('published_at')
            ->orWhere('published_at', '>', now());
    }

    /**
     * Scope for portfolio items by category.
     */
    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Check if portfolio item is published.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * Check if portfolio item is draft.
     */
    public function isDraft(): bool
    {
        return ! $this->isPublished();
    }

    /**
     * Get the URL for this portfolio item.
     */
    public function getUrlAttribute(): string
    {
        return route('portfolio.show', $this->slug);
    }
}
