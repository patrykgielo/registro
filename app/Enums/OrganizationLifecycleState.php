<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationLifecycleState: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closing = 'closing';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktywna',
            self::Suspended => 'Zawieszona',
            self::Closing => 'W trakcie zamknięcia',
            self::Closed => 'Zamknięta',
        };
    }

    /**
     * Whether this state permits the public-facing site (catalog, booking, rental) to operate.
     * Only Active allows full public access; all other states block it.
     */
    public function allowsPublicSite(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether new bookings/rentals may be created.
     * True only for Active — even Closing blocks new intake.
     */
    public function allowsNewBookings(): bool
    {
        return $this === self::Active;
    }

    /**
     * Terminal states have no outgoing transitions and represent end-of-life.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }
}
