<?php

declare(strict_types=1);

namespace App\Enums;

enum RentalStatus: string
{
    case Held = 'held';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Zarezerwowane tymczasowo',
            self::Pending => 'Oczekujące',
            self::Confirmed => 'Potwierdzone',
            self::Active => 'Aktywne',
            self::Returned => 'Zwrócone',
            self::Cancelled => 'Anulowane',
            self::Expired => 'Wygasłe',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Held => 'gray',
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::Active => 'success',
            self::Returned => 'gray',
            self::Cancelled => 'danger',
            self::Expired => 'gray',
        };
    }

    /**
     * Statuses that block rental item availability.
     * Held counts as consumed capacity — this is critical for the hold pattern.
     */
    public function blocksAvailability(): bool
    {
        return in_array($this, [self::Held, self::Pending, self::Confirmed, self::Active]);
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
