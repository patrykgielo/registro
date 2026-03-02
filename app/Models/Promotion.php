<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Promotion extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'body',
        'content',
        'layout',
        'valid_from',
        'valid_until',
        'active',
        'meta_title',
        'meta_description',
        'featured_image',
    ];

    protected $casts = [
        'content' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'active' => 'boolean',
        'layout' => PageLayout::class,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($promotion) {
            if (empty($promotion->slug)) {
                $promotion->slug = Str::slug($promotion->title);
            }
        });

        static::updating(function ($promotion) {
            if ($promotion->isDirty('title') && empty($promotion->slug)) {
                $promotion->slug = Str::slug($promotion->title);
            }
        });
    }

    /**
     * Scope for active promotions.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for valid promotions (within date range).
     */
    public function scopeValid($query)
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
            $q->where(function ($q) use ($now) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $now);
            })
                ->where(function ($q) use ($now) {
                    $q->whereNull('valid_until')
                        ->orWhere('valid_until', '>=', $now);
                });
        });
    }

    /**
     * Scope for active and valid promotions.
     */
    public function scopeActiveAndValid($query)
    {
        return $query->active()->valid();
    }

    /**
     * Check if promotion is active.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Check if promotion is valid (within date range).
     *
     * Note: Uses inclusive comparison (<=, >=) to handle edge case
     * when valid_from or valid_until equals exactly "now".
     */
    public function isValid(): bool
    {
        $now = now();
        $validFrom = $this->valid_from === null || $this->valid_from->lessThanOrEqualTo($now);
        $validUntil = $this->valid_until === null || $this->valid_until->greaterThanOrEqualTo($now);

        return $validFrom && $validUntil;
    }

    /**
     * Check if promotion is active and valid.
     */
    public function isActiveAndValid(): bool
    {
        return $this->isActive() && $this->isValid();
    }

    /**
     * Get the URL for this promotion.
     */
    public function getUrlAttribute(): string
    {
        return route('promotion.show', $this->slug);
    }
}
