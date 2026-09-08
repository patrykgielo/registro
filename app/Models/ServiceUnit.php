<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceUnitStatus;
use App\Traits\Auditable;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The egzemplarz (physical unit) of an item_rental Service — Faza 3,
 * app/docs/features/lokalizacje/model-danych.md. WHERE it lives and whether
 * it is fit for use, never occupancy in a date window (that stays in
 * order_items/rentals — kontrakt-dostepnosci.md Zasada 5). Saving this model
 * (create/update/delete) triggers App\Observers\ServiceUnitObserver, which
 * keeps the Faza 2 anchor (ServiceLocationStock::quantity) and, through it,
 * Service::quantity_total in sync — see that observer's docblock for the
 * full mechanism.
 */
class ServiceUnit extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    /**
     * organization_id/service_id never change after creation (same
     * convention as ServiceLocationStock) — enforced below in booted(),
     * mirroring Order's immutable-field guard (.claude/rules/models.md,
     * "Order — Auditable + Immutable Fields"). location_id is deliberately
     * NOT guarded: transferring a unit to another location is a legal
     * operation (Faza 7), unlike re-pointing it at a different service,
     * which would silently overstate the OLD service's quantity_total (the
     * anchor's COUNT would still include a unit that no longer belongs to
     * it) — a real oversell risk, not just a bookkeeping nit. status and
     * identifier/inventory_number are typically assigned later (krok 3.6) —
     * all worth an audit trail.
     */
    protected $auditInclude = [
        'location_id',
        'status',
        'identifier',
        'inventory_number',
        'acquired_at',
        'notes',
    ];

    protected $fillable = [
        'organization_id',
        'service_id',
        'location_id',
        'identifier',
        'inventory_number',
        'status',
        'acquired_at',
        'notes',
    ];

    protected $casts = [
        'status' => ServiceUnitStatus::class,
        'acquired_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $unit): void {
            foreach (['organization_id', 'service_id'] as $field) {
                if ($unit->isDirty($field)) {
                    throw new \LogicException(
                        "Field '{$field}' is immutable on ServiceUnit and cannot be changed after creation."
                    );
                }
            }
        });
    }

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

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Used by the Faza 3 krok 3.6/3.7 handover/return Select options
     * (App\Filament\Resources\OrderResource\Support\ServiceUnitAssignmentForms)
     * — `identifier` is the tenant's own mark and the thing staff actually
     * recognise on the equipment, `inventory_number` is the fallback for a
     * unit nobody has labelled yet, and "Egzemplarz #ID" only appears for a
     * unit with neither (both are nullable — model-danych.md).
     */
    public function getDisplayLabelAttribute(): string
    {
        return $this->identifier ?? $this->inventory_number ?? "Egzemplarz #{$this->id}";
    }
}
