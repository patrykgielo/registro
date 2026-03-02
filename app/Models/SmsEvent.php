<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS Event Model
 *
 * Tracks delivery events for sent SMS (sent, delivered, failed, invalid_number, expired).
 * Events typically come from SMSAPI webhook callbacks.
 *
 * @property int $id
 * @property int $sms_send_id FK to sms_sends.id
 * @property string $event_type Type of event: sent, delivered, failed, invalid_number, expired
 * @property array|null $event_data SMSAPI-specific data from webhook
 * @property \Illuminate\Support\Carbon $occurred_at When the event occurred
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SmsEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sms_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'sms_send_id',
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
     * Get the SMS send associated with this event.
     */
    public function smsSend(): BelongsTo
    {
        return $this->belongsTo(SmsSend::class);
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
     * Check if this is a failed event.
     */
    public function isFailed(): bool
    {
        return $this->event_type === 'failed';
    }

    /**
     * Check if this is an invalid number event.
     */
    public function isInvalidNumber(): bool
    {
        return $this->event_type === 'invalid_number';
    }

    /**
     * Check if this is an expired event.
     */
    public function isExpired(): bool
    {
        return $this->event_type === 'expired';
    }
}
