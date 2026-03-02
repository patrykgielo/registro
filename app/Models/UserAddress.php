<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'address',
        'latitude',
        'longitude',
        'place_id',
        'components',
        'nickname',
        'is_default',
    ];

    protected $casts = [
        'components' => 'array',
        'is_default' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    /**
     * Get the user that owns this address.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the display name for this address.
     * Returns nickname if set, otherwise the full address.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->nickname) {
            return $this->nickname;
        }

        return $this->getShortAddressAttribute();
    }

    /**
     * Get a shortened version of the address.
     * Extracts street and city from address.
     */
    public function getShortAddressAttribute(): string
    {
        // Try to extract from components first
        if ($this->components) {
            $street = '';
            $city = '';

            foreach ($this->components as $component) {
                $types = $component['types'] ?? [];

                if (in_array('route', $types)) {
                    $street = $component['long_name'] ?? '';
                }
                if (in_array('street_number', $types)) {
                    $streetNumber = $component['long_name'] ?? '';
                }
                if (in_array('locality', $types)) {
                    $city = $component['long_name'] ?? '';
                }
            }

            if ($street && $city) {
                $streetWithNumber = isset($streetNumber) ? "$street $streetNumber" : $street;

                return "$streetWithNumber, $city";
            }
        }

        // Fall back to truncated full address
        $address = $this->address;
        if (strlen($address) > 50) {
            return substr($address, 0, 47).'...';
        }

        return $address;
    }

    /**
     * Get Google Maps link for this address.
     */
    public function getGoogleMapsLinkAttribute(): string
    {
        if ($this->place_id) {
            return "https://www.google.com/maps/place/?q=place_id:{$this->place_id}";
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }

    /**
     * Get coordinates as array.
     */
    public function getCoordinatesAttribute(): array
    {
        return [
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
        ];
    }

    /**
     * Scope to filter addresses for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get only default addresses.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
