<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Email Suppression Model
 *
 * Maintains a list of email addresses that should not receive emails (bounces, complaints, unsubscribes).
 *
 * @property int $id
 * @property string $email Suppressed email address
 * @property string $reason Reason for suppression: bounced, complained, unsubscribed, manual
 * @property \Illuminate\Support\Carbon $suppressed_at When email was suppressed
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class EmailSuppression extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'reason',
        'suppressed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'suppressed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope a query to filter by suppression reason.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReason($query, string $reason)
    {
        return $query->where('reason', $reason);
    }

    /**
     * Scope a query to only include active suppressions.
     *
     * Currently returns all suppressions. Can be extended with TTL logic if needed.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        // Future: Add TTL logic here if suppressions should expire
        // Example: return $query->where('suppressed_at', '>=', now()->subDays(90));
        return $query;
    }

    /**
     * Check if an email address is suppressed.
     */
    public static function isSuppressed(string $email): bool
    {
        return static::where('email', strtolower($email))->exists();
    }

    /**
     * Suppress an email address with a reason.
     */
    public static function suppress(string $email, string $reason): self
    {
        return static::updateOrCreate(
            ['email' => strtolower($email)],
            [
                'reason' => $reason,
                'suppressed_at' => now(),
            ]
        );
    }

    /**
     * Remove an email address from the suppression list.
     */
    public static function unsuppress(string $email): bool
    {
        return static::where('email', strtolower($email))->delete() > 0;
    }

    /**
     * Get all bounced email addresses.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function bounced()
    {
        return static::reason('bounced')->get();
    }

    /**
     * Get all complained email addresses.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function complained()
    {
        return static::reason('complained')->get();
    }

    /**
     * Get all unsubscribed email addresses.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function unsubscribed()
    {
        return static::reason('unsubscribed')->get();
    }

    /**
     * Get all manually suppressed email addresses.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function manual()
    {
        return static::reason('manual')->get();
    }

    /**
     * Boot the model and set event listeners.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically normalize email to lowercase before saving
        static::saving(function ($suppression) {
            $suppression->email = strtolower($suppression->email);
        });
    }
}
