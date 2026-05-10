<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-aggregated daily statistics snapshot.
 *
 * Intentionally does NOT use BelongsToOrganization trait — this is a flat
 * aggregate table that the platform super-admin must query across all tenants.
 * Tenant-scoping is applied manually in StatisticsService::forTenant().
 *
 * @property int $id
 * @property int $organization_id
 * @property \Illuminate\Support\Carbon $date
 * @property string $source orders|appointments|rentals
 * @property float $revenue
 * @property int $count
 * @property \Illuminate\Support\Carbon $computed_at
 */
class StatisticsSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'statistics_daily_snapshots';

    protected $fillable = [
        'organization_id',
        'date',
        'source',
        'revenue',
        'count',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'revenue' => 'decimal:2',
            'count' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
