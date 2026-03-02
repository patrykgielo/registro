<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Post extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'content',
        'layout',
        'category_id',
        'published_at',
        'meta_title',
        'meta_description',
        'featured_image',
    ];

    protected $casts = [
        'content' => 'array',
        'published_at' => 'datetime',
        'layout' => PageLayout::class,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
        });

        static::updating(function ($post) {
            if ($post->isDirty('title') && empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
        });
    }

    /**
     * Get the category for this post.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope for published posts.
     */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope for draft posts.
     */
    public function scopeDraft($query)
    {
        return $query->whereNull('published_at')
            ->orWhere('published_at', '>', now());
    }

    /**
     * Scope for posts by category.
     */
    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Check if post is published.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * Check if post is draft.
     */
    public function isDraft(): bool
    {
        return ! $this->isPublished();
    }

    /**
     * Get the URL for this post.
     */
    public function getUrlAttribute(): string
    {
        return route('post.show', $this->slug);
    }
}
