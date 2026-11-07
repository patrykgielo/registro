<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\CatalogImportBatch;
use App\Support\Catalog\ValidationReport;

final class ImportResult
{
    public ValidationReport $report;

    public ?CatalogImportBatch $batch = null;

    /** @var array<string, int> */
    public array $counts = [
        'products' => 0,
        'skipped' => 0,
        'categories_created' => 0,
        'categories_reused' => 0,
        'images' => 0,
        'units' => 0,
    ];

    public function __construct(public readonly bool $dryRun)
    {
        $this->report = new ValidationReport;
    }

    public function ok(): bool
    {
        return ! $this->report->hasErrors();
    }
}
