<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class RentalUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Wybrany sprzęt nie jest dostępny w podanym terminie.')
    {
        parent::__construct($message);
    }
}
