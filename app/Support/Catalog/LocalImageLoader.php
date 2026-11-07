<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use Illuminate\Support\Str;

/**
 * `{"path": "images/x.jpg"}` images: read from disk, confined to the directory of the
 * JSON file. Relative paths only; `..`, absolute paths, NUL bytes and symlinks pointing
 * outside are refused (realpath() resolves symlinks BEFORE the prefix check). Same
 * ImageInspector validation as a downloaded image.
 */
final class LocalImageLoader
{
    public function __construct(private readonly ImageInspector $inspector) {}

    /**
     * @throws ImageFetchException
     */
    public function load(string $relativePath, string $baseDir, string $scratchDir): FetchedImage
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new ImageFetchException('nieprawidłowa ścieżka lokalna');
        }

        $normalized = str_replace('\\', '/', $relativePath);

        if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:#', $normalized) || in_array('..', explode('/', $normalized), true)) {
            throw new ImageFetchException('ścieżka lokalna musi być względna i nie może zawierać ".."');
        }

        $base = realpath($baseDir);
        $full = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$normalized);

        if ($base === false || $full === false || ! is_file($full)) {
            throw new ImageFetchException("plik lokalny nie istnieje: {$relativePath}");
        }

        if (! str_starts_with($full, rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new ImageFetchException('ścieżka lokalna wychodzi poza katalog pliku JSON');
        }

        if ((int) filesize($full) > $this->inspector->maxBytes()) {
            throw new ImageFetchException('plik większy niż '.intdiv($this->inspector->maxBytes(), 1024).' KB');
        }

        return $this->inspector->inspect((string) file_get_contents($full), $scratchDir.'/'.Str::random(24));
    }
}
