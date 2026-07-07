<?php

declare(strict_types=1);

namespace App\Enums;

enum ExtensionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Oczekuje',
            self::Approved => 'Zatwierdzone',
            self::Rejected => 'Odrzucone',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
