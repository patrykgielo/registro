<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'session_id',
        'event',
        'url',
        'referrer',
        'page_type',
        'device_type',
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

    public function scopeOfType(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeEvents(Builder $query, array $events): Builder
    {
        return $query->whereIn('event', $events);
    }
}
