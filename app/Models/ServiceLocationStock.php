<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The kotwica (anchor) row for how many units of a Service stand in a given
 * Location — app/docs/features/lokalizacje/model-danych.md. Faza 4's write
 * path will `lockForUpdate()` this row inside the hierarchy documented in
 * kontrakt-dostepnosci.md ("Po dodaniu kotwicy"); Faza 2 itself never locks
 * it — see Service::recalculateQuantityTotal()'s docblock.
 */
class ServiceLocationStock extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    /**
     * Only `quantity` is audited. `is_active` is a lower-stakes toggle not
     * yet consumed anywhere in this phase (kontrakt-dostepnosci.md); the
     * three identity columns (organization_id/service_id/location_id) never
     * change after a row is created — every write path here always
     * `updateOrCreate()`s or `insertOrIgnore()`s a NEW row instead of
     * repointing an existing one.
     */
    protected $auditInclude = ['quantity'];

    protected $fillable = [
        'organization_id',
        'service_id',
        'location_id',
        'quantity',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
