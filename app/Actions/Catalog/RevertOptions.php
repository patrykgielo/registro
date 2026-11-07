<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

final class RevertOptions
{
    public function __construct(
        public readonly bool $partial = false,
        public readonly bool $includeModified = false,
        public readonly bool $discardCartItems = false,
        public readonly bool $resetAutoIncrement = false,
    ) {}
}
