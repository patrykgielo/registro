<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAreaWaitlist extends Model
{
    use HasFactory;

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

        // TODO: Dispatch notification email job
        // dispatch(new NotifyServiceAreaAvailable($this));
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
