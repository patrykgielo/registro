<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger row: one entity a catalog import batch created or reused. See the
 * migration docblock — this table is what makes the revert's scope explicit.
 */
class CatalogImportItem extends Model
{
    public const TYPE_CATEGORY = 'rental_category';

    public const TYPE_SERVICE = 'service';

    public const TYPE_UNIT = 'service_unit';

    public const TYPE_STOCK = 'service_location_stock';

    public const TYPE_FILE = 'file';

    public const ORIGIN_CREATED = 'created';

    public const ORIGIN_REUSED = 'reused';

    protected $fillable = [
        'batch_id',
        'organization_id',
        'entity_type',
        'entity_id',
        'service_id',
        'origin',
        'external_key',
        'name',
        'slug',
        'payload_hash',
        'path',
        'file_sha256',
        'snapshot',
        'reverted_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'reverted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CatalogImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CatalogImportBatch::class, 'batch_id');
    }
}
