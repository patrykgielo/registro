<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel
{
    case TimeSlot = 'time_slot';
    case ItemRental = 'item_rental';

    public function label(): string
    {
        return match ($this) {
            self::TimeSlot => 'Usługa (rezerwacja terminu)',
            self::ItemRental => 'Wypożyczenie (przedmiot)',
        };
    }

    /** Lets Filament selects/filters built with `->options(ServiceType::class)` show the Polish label. */
    public function getLabel(): string
    {
        return $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::TimeSlot => 'heroicon-o-calendar',
            self::ItemRental => 'heroicon-o-cube',
        };
    }

    public function isRental(): bool
    {
        return $this === self::ItemRental;
    }

    public function isTimeSlot(): bool
    {
        return $this === self::TimeSlot;
    }
}
