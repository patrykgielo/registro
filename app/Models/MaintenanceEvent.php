<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceEvent extends Model
{
    protected $fillable = [
        'type',
        'action',
        'user_id',
        'ip_address',
        'message',
        'metadata',
    ];

    protected $casts = [
        'type' => MaintenanceType::class,
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by type
     */
    public function scopeOfType($query, MaintenanceType $type)
    {
        return $query->where('type', $type->value);
    }

    /**
     * Scope to filter by action
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Get formatted created_at for display
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('Y-m-d H:i:s');
    }
}
