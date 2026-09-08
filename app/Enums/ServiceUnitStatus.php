<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza 3 (app/docs/features/lokalizacje/model-danych.md) — status of a single
 * physical ServiceUnit. Answers ONLY "where does it live and is it fit for
 * use" — NEVER "is it currently rented out". Occupancy in a date window lives
 * exclusively in order_items/rentals (kontrakt-dostepnosci.md Zasada 5,
 * rental-availability.md §5). A unit out on rental stays Available.
 */
enum ServiceUnitStatus: string
{
    case Available = 'available';
    case Maintenance = 'maintenance';
    case InTransit = 'in_transit';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Dostępny',
            self::Maintenance => 'Serwis',
            self::InTransit => 'W transporcie',
            self::Retired => 'Wycofany',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Maintenance => 'warning',
            self::InTransit => 'info',
            self::Retired => 'gray',
        };
    }

    /**
     * The ONLY predicate ServiceUnitObserver's anchor recalculation reads
     * (model-danych.md: `stocks.quantity = COUNT(units WHERE location_id = L
     * AND status = 'available')`). Every other status — including a unit out
     * on rental, which per this enum's own docblock never changes status —
     * is excluded from the count.
     */
    public function countsTowardStock(): bool
    {
        return $this === self::Available;
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
}
