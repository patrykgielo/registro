<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Setting Model
 *
 * Stores application-wide settings grouped by context (booking, email, map, etc.)
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property array $value
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Setting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Scope a query to only include settings of a given group.
     */
    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * Scope a query to only include settings with a given key.
     */
    public function scopeKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }
}
