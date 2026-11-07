<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One `catalog:import` run. Durable ledger header — deliberately no
 * BelongsToOrganization (like OrganizationLifecycleLog): every query against it
 * filters organization_id explicitly, console or not.
 */
class CatalogImportBatch extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERTED = 'reverted';

    public const STATUS_PARTIALLY_REVERTED = 'partially_reverted';

    protected $fillable = [
        'organization_id',
        'source_filename',
        'source_sha256',
        'status',
        'summary',
        'actor',
        'imported_at',
        'reverted_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'imported_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<CatalogImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogImportItem::class, 'batch_id');
    }
}
