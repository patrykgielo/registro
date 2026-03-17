<?php

namespace App\Enums;

enum ServiceType: string
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

    public function icon(): string
    {
        return match ($this) {
            self::TimeSlot => 'heroicon-o-calendar',
            self::ItemRental => 'heroicon-o-cube',
        };
    }
}
