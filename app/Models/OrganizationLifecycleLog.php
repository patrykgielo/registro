<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationLifecycleLog extends Model
{
    // Append-only log — no updated_at column in the table.
    public const UPDATED_AT = null;

    protected $table = 'organization_lifecycle_log';

    protected $fillable = [
        'organization_id',
        'organization_name',
        'event',
        'actor_id',
        'actor_label',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public static function record(
        Organization $org,
        string $event,
        ?User $actor = null,
        array $context = []
    ): static {
        return static::create([
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'event' => $event,
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->email,
            'context' => empty($context) ? null : $context,
            'created_at' => now(),
        ]);
    }
}
