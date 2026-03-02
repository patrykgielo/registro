<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SMS Suppression Model
 *
 * Maintains a list of phone numbers that should not receive SMS (invalid numbers, opt-outs, failed repeatedly).
 *
 * @property int $id
 * @property string $phone Suppressed phone number in international format
 * @property string $reason Reason for suppression: invalid_number, opted_out, failed_repeatedly, manual
 * @property \Illuminate\Support\Carbon $suppressed_at When phone number was suppressed
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SmsSuppression extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sms_suppressions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
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
     * Check if a phone number is suppressed.
     */
    public static function isSuppressed(string $phone): bool
    {
        // Normalize phone number before checking
        $normalizedPhone = static::normalizePhone($phone);

        return static::where('phone', $normalizedPhone)->exists();
    }

    /**
     * Suppress a phone number with a reason.
     */
    public static function suppress(string $phone, string $reason): self
    {
        $normalizedPhone = static::normalizePhone($phone);

        return static::updateOrCreate(
            ['phone' => $normalizedPhone],
            [
                'reason' => $reason,
                'suppressed_at' => now(),
            ]
        );
    }

    /**
     * Remove a phone number from the suppression list.
     */
    public static function unsuppress(string $phone): bool
    {
        $normalizedPhone = static::normalizePhone($phone);

        return static::where('phone', $normalizedPhone)->delete() > 0;
    }

    /**
     * Get all invalid number suppressions.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function invalidNumbers()
    {
        return static::reason('invalid_number')->get();
    }

    /**
     * Get all opted-out phone numbers.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function optedOut()
    {
        return static::reason('opted_out')->get();
    }

    /**
     * Get all repeatedly failed phone numbers.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function failedRepeatedly()
    {
        return static::reason('failed_repeatedly')->get();
    }

    /**
     * Get all manually suppressed phone numbers.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function manual()
    {
        return static::reason('manual')->get();
    }

    /**
     * Normalize phone number by removing spaces and dashes.
     */
    protected static function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s\-]/', '', $phone);
    }

    /**
     * Boot the model and set event listeners.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically normalize phone number before saving
        static::saving(function ($suppression) {
            $suppression->phone = static::normalizePhone($suppression->phone);
        });
    }
}
