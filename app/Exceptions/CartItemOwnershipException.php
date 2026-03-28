<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CartItemOwnershipException extends RuntimeException
{
    public static function make(string $message = 'Item nie należy do tego koszyka'): static
    {
        return new static($message);
    }
}
