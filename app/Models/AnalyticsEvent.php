<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// No BelongsToOrganization trait intentionally — platform super-admin needs cross-tenant
// queries (StatisticsService, PlatformOverview). Tenant isolation enforced at call sites.
class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'session_id',
        'anonymous_id',
        'event',
        'url',
        'referrer',
        'page_type',
        'device_type',
        'browser',
        'os',
        'viewport_w',
        'properties',
        'ip_hash',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer_domain',
        'occurred_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'viewport_w' => 'integer',
        ];
    }

    public function scopeForTenant(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeInPeriod(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    public function scopeEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeEvents(Builder $query, array $events): Builder
    {
        return $query->whereIn('event', $events);
    }

    public function getProductSlugAttribute(): ?string
    {
        return $this->properties['service_slug'] ?? null;
    }

    public function getCartIdAttribute(): ?string
    {
        return $this->properties['cart_id'] ?? null;
    }

    public function getOrderIdAttribute(): ?string
    {
        return $this->properties['order_id'] ?? null;
    }

    public function getRevenueAttribute(): ?float
    {
        $value = $this->properties['total'] ?? null;

        return ($value !== null && $value !== '') ? (float) $value : null;
    }
}
