<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSchedule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'day_of_week',
        'start_time',
        'end_time',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'day_of_week' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the staff member (user) that owns this schedule.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include schedules for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include schedules for a specific day of week.
     */
    public function scopeForDay(Builder $query, int $dayOfWeek): Builder
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    /**
     * Scope a query to only include active schedules.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include schedules effective on a given date.
     */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query->where(function ($q) use ($date) {
            $q->where(function ($q2) use ($date) {
                // effective_from is null OR date >= effective_from
                $q2->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $date->format('Y-m-d'));
            })->where(function ($q2) use ($date) {
                // effective_until is null OR date <= effective_until
                $q2->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $date->format('Y-m-d'));
            });
        });
    }

    /**
     * Check if this schedule is effective on a given date.
     */
    public function isEffectiveOn(Carbon $date): bool
    {
        // Check effective_from
        if ($this->effective_from && $date->lt($this->effective_from)) {
            return false;
        }

        // Check effective_until
        if ($this->effective_until && $date->gt($this->effective_until)) {
            return false;
        }

        return $this->is_active;
    }

    /**
     * Get the day name in Polish.
     */
    public function getDayNameAttribute(): string
    {
        $days = [
            0 => 'Niedziela',
            1 => 'Poniedziałek',
            2 => 'Wtorek',
            3 => 'Środa',
            4 => 'Czwartek',
            5 => 'Piątek',
            6 => 'Sobota',
        ];

        return $days[$this->day_of_week] ?? 'Nieznany';
    }
}
