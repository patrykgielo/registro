<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffVacationPeriod extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'reason',
        'is_approved',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_approved' => 'boolean',
    ];

    /**
     * Get the staff member (user) that owns this vacation period.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include vacation periods for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include approved vacation periods.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope a query to only include pending (not approved) vacation periods.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('is_approved', false);
    }

    /**
     * Scope a query to only include vacation periods that overlap with a given date range.
     */
    public function scopeOverlapping(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->orWhereBetween('end_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->orWhere(function ($q2) use ($startDate, $endDate) {
                    $q2->where('start_date', '<=', $startDate->format('Y-m-d'))
                        ->where('end_date', '>=', $endDate->format('Y-m-d'));
                });
        });
    }

    /**
     * Scope a query to only include vacation periods that include a specific date.
     */
    public function scopeIncludesDate(Builder $query, Carbon $date): Builder
    {
        return $query->where('start_date', '<=', $date->format('Y-m-d'))
            ->where('end_date', '>=', $date->format('Y-m-d'));
    }

    /**
     * Check if this vacation period includes a specific date.
     */
    public function includesDate(Carbon $date): bool
    {
        // Guard against null dates (e.g., during record creation)
        if (! $this->start_date || ! $this->end_date) {
            return false;
        }

        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Get the number of days in this vacation period.
     */
    public function getDurationInDays(): int
    {
        // Guard against null dates (e.g., during record creation)
        if (! $this->start_date || ! $this->end_date) {
            return 0;
        }

        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Get all dates in this vacation period.
     *
     * RENAMED from getDates() to avoid collision with Laravel's
     * protected getDates() method in HasAttributes trait.
     *
     * @return array<Carbon>
     */
    public function getDateRange(): array
    {
        // Guard against null dates (e.g., during record creation)
        if (! $this->start_date || ! $this->end_date) {
            return [];
        }

        $period = CarbonPeriod::create($this->start_date, $this->end_date);

        return iterator_to_array($period);
    }
}
