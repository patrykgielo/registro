<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Email Event Model
 *
 * Tracks delivery events for sent emails (sent, delivered, bounced, opened, clicked, etc.).
 *
 * organization_id is deliberately NOT auto-populated from ambient tenant context here — most
 * rows are created from webhook/queue paths with no tenant HTTP context (ResolveTenant never
 * ran). Callers must pass it explicitly, copied from the owning EmailSend (see EmailService).
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int $email_send_id FK to email_sends.id
 * @property string $event_type Type of event: sent, delivered, bounced, complained, opened, clicked
 * @property array|null $event_data Provider-specific data
 * @property \Illuminate\Support\Carbon $occurred_at When the event occurred
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class EmailEvent extends Model
{
    use BelongsToOrganization;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'email_send_id',
        'event_type',
        'event_data',
        'occurred_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_data' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the email send associated with this event.
     */
    public function emailSend(): BelongsTo
    {
        return $this->belongsTo(EmailSend::class);
    }

    /**
     * Scope a query to filter by event type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope a query to only include events from the last 30 days.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query)
    {
        return $query->where('occurred_at', '>=', now()->subDays(30));
    }

    /**
     * Check if this is a sent event.
     */
    public function isSent(): bool
    {
        return $this->event_type === 'sent';
    }

    /**
     * Check if this is a delivered event.
     */
    public function isDelivered(): bool
    {
        return $this->event_type === 'delivered';
    }

    /**
     * Check if this is a bounced event.
     */
    public function isBounced(): bool
    {
        return $this->event_type === 'bounced';
    }

    /**
     * Check if this is a complained event.
     */
    public function isComplained(): bool
    {
        return $this->event_type === 'complained';
    }

    /**
     * Check if this is an opened event.
     */
    public function isOpened(): bool
    {
        return $this->event_type === 'opened';
    }

    /**
     * Check if this is a clicked event.
     */
    public function isClicked(): bool
    {
        return $this->event_type === 'clicked';
    }
}
