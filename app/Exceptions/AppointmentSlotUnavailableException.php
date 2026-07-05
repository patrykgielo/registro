<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside the DB::transaction() of AppointmentController::store() /
 * BookingController::confirm() when the requested slot turns out to be
 * unavailable (validation failure or lost the availability race) — allows a
 * single catch block to translate both cases into the same user-facing
 * "slot no longer available" redirect, and (critically) rolls back any
 * partial writes (e.g. customer profile updates) made earlier in the same
 * transaction.
 */
class AppointmentSlotUnavailableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'Wybrany termin nie jest dostępny.');
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
