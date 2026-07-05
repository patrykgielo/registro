<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDateException extends Model
{
    use BelongsToOrganization;

    /**
     * Exception types.
     */
    public const TYPE_UNAVAILABLE = 'unavailable';

    public const TYPE_AVAILABLE = 'available';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'exception_date',
        'exception_type',
        'start_time',
        'end_time',
        'reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'exception_date' => 'date',
    ];

    /**
     * Get the staff member (user) that owns this exception.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include exceptions for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include exceptions on a specific date.
     *
     * Uses whereDate() (not a raw string `where()`) because the `date` cast's
     * storage format is `Y-m-d H:i:s` (Eloquent's date/datetime casts share the
     * connection's date format). MySQL's native DATE column silently truncates
     * that to a date-only value on write, so a plain `where('exception_date',
     * 'Y-m-d')` happens to work there — but SQLite (used in tests) stores the
     * column as TEXT with no truncation, so an exact-string match against
     * '2026-07-06 00:00:00' silently fails. whereDate() extracts the date part
     * on both drivers.
     */
    public function scopeOnDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('exception_date', $date->format('Y-m-d'));
    }

    /**
     * Scope a query to only include unavailable exceptions.
     */
    public function scopeUnavailable(Builder $query): Builder
    {
        return $query->where('exception_type', self::TYPE_UNAVAILABLE);
    }

    /**
     * Scope a query to only include available exceptions.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('exception_type', self::TYPE_AVAILABLE);
    }

    /**
     * Check if this is an all-day exception.
     */
    public function isAllDay(): bool
    {
        return is_null($this->start_time) && is_null($this->end_time);
    }

    /**
     * Check if this exception is unavailable type.
     */
    public function isUnavailable(): bool
    {
        return $this->exception_type === self::TYPE_UNAVAILABLE;
    }

    /**
     * Check if this exception is available type.
     */
    public function isAvailable(): bool
    {
        return $this->exception_type === self::TYPE_AVAILABLE;
    }

    /**
     * Get the exception type label in Polish.
     */
    public function getTypeLabel(): string
    {
        return match ($this->exception_type) {
            self::TYPE_UNAVAILABLE => 'Niedostępny',
            self::TYPE_AVAILABLE => 'Dostępny',
            default => 'Nieznany',
        };
    }
}
