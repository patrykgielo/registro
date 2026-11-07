<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A sign-up for a location outside the tenant's service areas.
 *
 * Tenant-owned: `organization_id` is stamped by BelongsToOrganization from the
 * tenant the public form was submitted on (the route requires one) and is
 * deliberately NOT in $fillable. Rows from before the column existed on a
 * multi-organization database may have NULL — they match no tenant's scope.
 */
class ServiceAreaWaitlist extends Model
{
    use BelongsToOrganization, HasFactory, Notifiable;

    protected $table = 'service_area_waitlist';

    protected $fillable = [
        'email',
        'name',
        'phone',
        'requested_address',
        'requested_latitude',
        'requested_longitude',
        'requested_place_id',
        'distance_to_nearest_area_km',
        'nearest_area_city',
        'status',
        'admin_notes',
        'notified_at',
        'session_id',
        'ip_address',
    ];

    protected $casts = [
        'requested_latitude' => 'decimal:8',
        'requested_longitude' => 'decimal:8',
        'distance_to_nearest_area_km' => 'decimal:2',
        'notified_at' => 'datetime',
    ];

    /**
     * Scope: Pending entries only
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Group by requested location (for admin overview)
     */
    public function scopeGroupedByLocation($query)
    {
        return $query->orderBy('requested_address')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Mark as contacted
     */
    public function markAsContacted(?string $notes = null): void
    {
        $this->update([
            'status' => 'contacted',
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Mark as area added and notify user
     */
    public function markAsAreaAdded(?string $notes = null): void
    {
        $this->update([
            'status' => 'area_added',
            'notified_at' => now(),
            'admin_notes' => $notes,
        ]);

        $this->notify(new \App\Notifications\ServiceAreaAvailableNotification($this));
    }

    /**
     * Get coordinates as array
     */
    public function getCoordinatesAttribute(): array
    {
        return [
            'lat' => (float) $this->requested_latitude,
            'lng' => (float) $this->requested_longitude,
        ];
    }
}
