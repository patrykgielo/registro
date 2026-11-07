<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/**
 * Content-sniffing validation of downloaded bytes. Same contract as ServiceResource's
 * featured_image FileUpload (jpeg/png/webp, 6144 KB — ServiceResource.php:385-386), plus
 * dimension caps so a later GD decode can never exhaust the 256M memory_limit.
 * Never re-encodes: Filament stores uploaded bytes as-is and GD here has no WebP.
 * The extension is derived from the SNIFFED type — the URL's extension and the
 * response's Content-Type are never trusted.
 */
final class ImageInspector
{
    /** @var array<string, string> */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly int $maxBytes = 6144 * 1024,
        private readonly int $maxSide = 8000,
        private readonly int $maxPixels = 25_000_000,
        private readonly int $minSide = 32,
    ) {}

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @throws ImageFetchException
     */
    public function inspect(string $bytes, string $tmpPath): FetchedImage
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new ImageFetchException('pusta odpowiedź');
        }

        if ($size > $this->maxBytes) {
            throw new ImageFetchException('plik większy niż '.intdiv($this->maxBytes, 1024).' KB');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! isset(self::ALLOWED[$mime])) {
            throw new ImageFetchException("niedozwolony typ pliku ({$mime}); dozwolone: jpeg, png, webp");
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new ImageFetchException('uszkodzony lub nierozpoznany obraz');
        }

        [$width, $height] = $info;

        if ($width < $this->minSide || $height < $this->minSide) {
            throw new ImageFetchException("obraz zbyt mały ({$width}x{$height}px, minimum {$this->minSide}px)");
        }

        if ($width > $this->maxSide || $height > $this->maxSide || $width * $height > $this->maxPixels) {
            throw new ImageFetchException("obraz zbyt duży ({$width}x{$height}px)");
        }

        if (file_put_contents($tmpPath, $bytes) === false) {
            throw new ImageFetchException('nie można zapisać pliku tymczasowego');
        }

        return new FetchedImage($tmpPath, self::ALLOWED[$mime], $mime, $size, hash('sha256', $bytes), $width, $height);
    }
}
