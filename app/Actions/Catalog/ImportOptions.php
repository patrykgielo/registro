<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

final class ImportOptions
{
    /**
     * @param  string  $images  required (any image failure fails the import) | optional (warn, drop the image) | skip (ignore images)
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly bool $skipExisting = false,
        public readonly string $images = 'required',
        public readonly bool $checkImagesInDryRun = true,
        public readonly int $imageDelayMs = 1000,
        public readonly ?string $actor = null,
    ) {}
}
