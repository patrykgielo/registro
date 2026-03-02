<?php

namespace App\Models;

use App\Enums\TemplateKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReminderConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'channel',
        'trigger_type',
        'trigger_hours',
        'trigger_minutes',
        'window_buffer_minutes',
        'template_key',
        'enabled',
        'settings',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'template_key' => TemplateKey::class,
            'enabled' => 'boolean',
            'settings' => 'array',
            'trigger_hours' => 'integer',
            'trigger_minutes' => 'integer',
            'window_buffer_minutes' => 'integer',
            'priority' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReminderLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
    }

    /**
     * Get only enabled configs
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Get configs by channel
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Get configs for "before" appointment triggers
     */
    public function scopeBefore(Builder $query): Builder
    {
        return $query->where('trigger_type', 'before');
    }

    /**
     * Get configs for "after" appointment triggers (follow-ups)
     */
    public function scopeAfter(Builder $query): Builder
    {
        return $query->where('trigger_type', 'after');
    }

    /**
     * Calculate total trigger offset in minutes
     */
    public function getTriggerMinutesTotal(): int
    {
        return ($this->trigger_hours * 60) + $this->trigger_minutes;
    }

    /**
     * Get human-readable description of timing
     */
    public function getTimingDescription(): string
    {
        $hours = $this->trigger_hours;
        $minutes = $this->trigger_minutes;
        $isBefore = $this->trigger_type === 'before';
        $type = $isBefore ? 'przed' : 'po';
        $noun = $isBefore ? 'wizytą' : 'wizycie';

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}min {$type} {$noun}";
        } elseif ($hours > 0) {
            return "{$hours}h {$type} {$noun}";
        } else {
            return "{$minutes}min {$type} {$noun}";
        }
    }
}
