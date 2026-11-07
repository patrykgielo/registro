<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/** A downloaded, validated image waiting in the scratch directory. */
final class FetchedImage
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $extension,
        public readonly string $mime,
        public readonly int $bytes,
        public readonly string $sha256,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
