<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

final class RevertResult
{
    public int $services = 0;

    public int $units = 0;

    public int $stocks = 0;

    public int $cartItems = 0;

    public int $categoriesDeleted = 0;

    /** @var array<int, string> */
    public array $categoriesKept = [];

    public int $filesDeleted = 0;

    /** @var array<int, string> */
    public array $filesKept = [];

    /** @var array<string, array{reset: bool, reason: string}> */
    public array $autoIncrement = [];
}
