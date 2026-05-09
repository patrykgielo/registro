<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ServiceType;

trait HasTimeSlotBehavior
{
    protected static function bootHasTimeSlotBehavior(): void
    {
        // Reserved for time-slot specific boot logic
    }

    public function isTimeSlotService(): bool
    {
        return $this->service_type === ServiceType::TimeSlot;
    }
}
