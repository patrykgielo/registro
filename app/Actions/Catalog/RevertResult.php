<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

final class RevertResult
{
    public int $services = 0;

    public int $units = 0;

    public int $stocks = 0;

    public int $cartItems = 0;

    public int $staffAssignments = 0;

    /** The cache key of the public sitemap was dropped (idempotent; true on every successful clean-up). */
    public bool $sitemapForgotten = false;

    /** Post-commit steps that failed (the DB work is committed). @var array<int, array{step: string, label: string, message: string, retry: string}> */
    public array $warnings = [];

    public int $categoriesDeleted = 0;

    /** @var array<int, string> */
    public array $categoriesKept = [];

    public int $filesDeleted = 0;

    /** @var array<int, string> */
    public array $filesKept = [];

    /** @var array<string, array{reset: bool, reason: string}> */
    public array $autoIncrement = [];
}
