<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'reminder_config_id',
        'channel',
        'message_key',
        'status',
        'sent_at',
        'external_id',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<ReminderConfig, $this>
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(ReminderConfig::class, 'reminder_config_id');
    }

    /**
     * Get only sent logs
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    /**
     * Get only failed logs
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Get logs by channel
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Generate unique message key for idempotency
     */
    public static function generateMessageKey(int $appointmentId, int $configId): string
    {
        return md5("reminder:{$appointmentId}:{$configId}");
    }

    /**
     * Check if reminder was already sent for this appointment + config combination
     */
    public static function alreadySent(int $appointmentId, int $configId): bool
    {
        $key = self::generateMessageKey($appointmentId, $configId);

        return self::where('message_key', $key)
            ->whereIn('status', ['sent', 'pending'])
            ->exists();
    }

    /**
     * Mark as sent with external ID
     */
    public function markAsSent(?string $externalId = null): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'external_id' => $externalId,
        ]);
    }

    /**
     * Mark as failed with error message
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }
}
