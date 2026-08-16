<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The Przelewy24 gateway has no usable credentials on this machine.
 *
 * Deliberately a distinct type from "P24 rejected/refused the request": those
 * are transient and a retry can succeed, this one CANNOT — nothing the
 * customer does will fix a missing P24_CRC. The two get different user-facing
 * copy in CheckoutController::submit() for exactly that reason ("try again"
 * vs "online payment is unavailable, contact us / pay at pickup").
 *
 * Thrown from Przelewy24Service::client(), i.e. before any network I/O and
 * before the SDK constructor that used to blow up with a TypeError instead.
 */
class PaymentGatewayNotConfiguredException extends RuntimeException
{
    /**
     * @param  list<string>  $missing  env var names that are absent/empty
     */
    public static function forPrzelewy24(array $missing): self
    {
        return new self(
            'Przelewy24 is not configured — missing or empty: '.implode(', ', $missing)
        );
    }
}
