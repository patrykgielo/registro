<?php

declare(strict_types=1);

namespace App\ValueObjects;

readonly class RentalPricing
{
    public function __construct(
        public float $pricePerDay,
        public ?float $pricePerWeek,
        public ?float $pricePerDayLong,
        public ?int $thresholdDays,
        public bool $priceOnRequest,
    ) {}

    public function nettoPrice(float $brutto, int $vatRate): float
    {
        return round($brutto / (1 + $vatRate / 100), 2);
    }

    public function hasWeeklyRate(): bool
    {
        return $this->pricePerWeek !== null;
    }

    public function hasLongTermRate(): bool
    {
        return $this->pricePerDayLong !== null && $this->thresholdDays !== null;
    }
}
