<?php

declare(strict_types=1);

namespace App\Enums;

enum RentalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Oczekujące',
            self::Confirmed => 'Potwierdzone',
            self::Active => 'Aktywne',
            self::Returned => 'Zwrócone',
            self::Cancelled => 'Anulowane',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::Active => 'success',
            self::Returned => 'gray',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Statuses that block rental item availability.
     */
    public function blocksAvailability(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Active]);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function colorMap(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->color() => $status->value])
            ->all();
    }
}
