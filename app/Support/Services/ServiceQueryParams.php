<?php

declare(strict_types=1);

namespace App\Support\Services;

class ServiceQueryParams
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $category = null,
        public readonly ?bool $featured = null,
        public readonly array $exclude = [],
        public readonly string $orderBy = 'sort_order',
        public readonly int $limit = 0,
        public readonly bool $withPagination = false,
        public readonly int $perPage = 20,
    ) {}
}
