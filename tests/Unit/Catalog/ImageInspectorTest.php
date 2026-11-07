<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Support\Catalog\ImageFetchException;
use App\Support\Catalog\ImageInspector;
use PHPUnit\Framework\TestCase;

class ImageInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/inspector-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function gd(string $fn, int $w = 64, int $h = 64): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        $fn($im);

        return (string) ob_get_clean();
    }

    public function test_accepts_png_and_jpeg_and_takes_the_extension_from_the_sniffed_type(): void
    {
        $inspector = new ImageInspector;

        $bytes = $this->gd('imagepng');
        $png = $inspector->inspect($bytes, $this->dir.'/a');
        $jpg = $inspector->inspect($this->gd('imagejpeg'), $this->dir.'/b');

        $this->assertSame('png', $png->extension);
        $this->assertSame('image/png', $png->mime);
        $this->assertSame(64, $png->width);
        $this->assertSame('jpg', $jpg->extension);
        $this->assertFileExists($png->tmpPath);
        $this->assertSame(hash('sha256', $bytes), $png->sha256);
        $this->assertSame($bytes, file_get_contents($png->tmpPath));
    }

    public function test_accepts_webp_by_header_even_without_gd_webp_support(): void
    {
        // 1x1 lossless WebP
        $webp = (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');

        $image = (new ImageInspector(minSide: 1))->inspect($webp, $this->dir.'/w');

        $this->assertSame('webp', $image->extension);
    }

    public function test_rejects_html_pretending_to_be_an_image(): void
    {
        $this->expectException(ImageFetchException::class);
        $this->expectExceptionMessage('niedozwolony typ');

        (new ImageInspector)->inspect('<html><body>not an image</body></html>', $this->dir.'/x');
    }

    public function test_rejects_svg(): void
    {
        $this->expectException(ImageFetchException::class);

        (new ImageInspector)->inspect('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>', $this->dir.'/x');
    }

    public function test_rejects_a_truncated_image(): void
    {
        $png = $this->gd('imagepng');

        $this->expectException(ImageFetchException::class);

        (new ImageInspector)->inspect(substr($png, 0, 20), $this->dir.'/x');
    }

    public function test_rejects_oversize_bytes_empty_and_bad_dimensions(): void
    {
        $png = $this->gd('imagepng');

        $cases = [
            'too many bytes' => [new ImageInspector(maxBytes: 100), $png],
            'empty' => [new ImageInspector, ''],
            'too small' => [new ImageInspector(minSide: 100), $png],
            'too wide' => [new ImageInspector(maxSide: 32), $png],
            'too many pixels' => [new ImageInspector(maxPixels: 1000), $png],
        ];

        foreach ($cases as $label => [$inspector, $bytes]) {
            try {
                $inspector->inspect($bytes, $this->dir.'/x');
                $this->fail("{$label} was accepted");
            } catch (ImageFetchException) {
                $this->assertTrue(true);
            }
        }
    }
}
