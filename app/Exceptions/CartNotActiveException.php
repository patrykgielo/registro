<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CartNotActiveException extends RuntimeException
{
    public static function make(string $message = 'Koszyk nie jest aktywny'): static
    {
        return new static($message);
    }
}
