<?php

declare(strict_types=1);

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Oczekująca',
            self::Confirmed => 'Potwierdzona',
            self::Cancelled => 'Anulowana',
            self::Completed => 'Zakończona',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Cancelled => 'danger',
            self::Completed => 'info',
        };
    }

    public function hexColor(): string
    {
        return match ($this) {
            self::Pending => '#f59e0b',
            self::Confirmed => '#10b981',
            self::Cancelled => '#ef4444',
            self::Completed => '#6366f1',
        };
    }

    /**
     * Statuses that count as "active" (not finished/cancelled).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed]);
    }

    /**
     * Options for Filament Select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }

    /**
     * Filament badge color map.
     *
     * @return array<string, string>
     */
    public static function colorMap(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->color() => $status->value])
            ->all();
    }
}
