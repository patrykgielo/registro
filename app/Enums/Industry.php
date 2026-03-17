<?php

declare(strict_types=1);

namespace App\Enums;

enum Industry: string
{
    case EquipmentRental = 'equipment_rental';
    case AutoDetailing = 'auto_detailing';
    case GeneralServices = 'general_services';

    public function label(): string
    {
        return match ($this) {
            self::EquipmentRental => 'Wypożyczalnia sprzętu',
            self::AutoDetailing => 'Auto detailing',
            self::GeneralServices => 'Inna działalność',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EquipmentRental => 'wrench-screwdriver',
            self::AutoDetailing => 'truck',
            self::GeneralServices => 'cog-6-tooth',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EquipmentRental => 'Zarządzaj katalogiem, rezerwacjami i zwrotami',
            self::AutoDetailing => 'Usługi z cenami per rozmiar auta, dojazd do klienta',
            self::GeneralServices => 'Rezerwacje terminowe dla dowolnej branży',
        };
    }

    public function bookingType(): string
    {
        return match ($this) {
            self::EquipmentRental => 'item_rental',
            self::AutoDetailing, self::GeneralServices => 'time_slot',
        };
    }

    /**
     * Default feature flags for this industry.
     *
     * @return array<string, bool>
     */
    public function defaultFeatures(): array
    {
        return match ($this) {
            self::EquipmentRental => [
                'vehicles' => false,
                'mobile_service' => false,
                'service_area' => false,
            ],
            self::AutoDetailing => [
                'vehicles' => true,
                'mobile_service' => true,
                'service_area' => true,
            ],
            self::GeneralServices => [
                'vehicles' => false,
                'mobile_service' => false,
                'service_area' => false,
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function terminology(): array
    {
        return match ($this) {
            self::EquipmentRental => [
                'service' => 'przedmiot',
                'booking' => 'wypożyczenie',
                'customer' => 'wypożyczający',
            ],
            self::AutoDetailing, self::GeneralServices => [
                'service' => 'usługa',
                'booking' => 'rezerwacja',
                'customer' => 'klient',
            ],
        };
    }

    /**
     * Default modules enabled for this industry.
     *
     * @return array<int, string>
     */
    public function defaultModules(): array
    {
        return match ($this) {
            self::EquipmentRental => ['services', 'rentals'],
            self::AutoDetailing => ['services', 'bookings'],
            self::GeneralServices => ['services', 'bookings'],
        };
    }

    public function seederClass(): string
    {
        return match ($this) {
            self::EquipmentRental => \App\Actions\Onboarding\Seeders\SeedEquipmentRental::class,
            self::AutoDetailing => \App\Actions\Onboarding\Seeders\SeedAutoDetailing::class,
            self::GeneralServices => \App\Actions\Onboarding\Seeders\SeedGeneralServices::class,
        };
    }
}
