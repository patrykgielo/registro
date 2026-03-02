<?php

namespace App\Enums;

enum MaintenanceType: string
{
    case DEPLOYMENT = 'deployment';
    case PRELAUNCH = 'prelaunch';
    case SCHEDULED = 'scheduled';
    case EMERGENCY = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::DEPLOYMENT => 'Deployment',
            self::PRELAUNCH => 'Pre-Launch',
            self::SCHEDULED => 'Scheduled',
            self::EMERGENCY => 'Emergency',
        };
    }

    public function canBypass(): bool
    {
        return match ($this) {
            self::DEPLOYMENT, self::SCHEDULED, self::EMERGENCY => true,
            self::PRELAUNCH => false,
        };
    }

    public function retryAfter(): int
    {
        return match ($this) {
            self::DEPLOYMENT => 60,
            self::SCHEDULED => 300,
            self::EMERGENCY => 120,
            self::PRELAUNCH => 3600,
        };
    }
}
