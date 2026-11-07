<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Support\Catalog\ImageFetchException;
use App\Support\Catalog\ImageInspector;
use App\Support\Catalog\LocalImageLoader;
use PHPUnit\Framework\TestCase;

class LocalImageLoaderTest extends TestCase
{
    private string $root;

    private string $base;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/local-img-'.bin2hex(random_bytes(4));
        $this->base = $this->root.'/dataset';
        $this->scratch = $this->root.'/scratch';
        mkdir($this->base.'/images', 0777, true);
        mkdir($this->scratch, 0777, true);

        $im = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();

        file_put_contents($this->base.'/images/ok.png', $png);
        file_put_contents($this->root.'/secret.png', $png); // outside the dataset dir
        file_put_contents($this->base.'/images/fake.jpg', '<html>not an image</html>');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tree($this->root)) as $p) {
            is_link($p) || is_file($p) ? @unlink($p) : @rmdir($p);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    /** @return array<int, string> */
    private function tree(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $out[] = $dir.'/'.$f;
            if (is_dir($dir.'/'.$f) && ! is_link($dir.'/'.$f)) {
                $out = array_merge($out, $this->tree($dir.'/'.$f));
            }
        }

        return $out;
    }

    private function loader(): LocalImageLoader
    {
        return new LocalImageLoader(new ImageInspector);
    }

    public function test_loads_a_relative_image_confined_to_the_json_directory(): void
    {
        $image = $this->loader()->load('images/ok.png', $this->base, $this->scratch);

        $this->assertSame('png', $image->extension);
    }

    public function test_rejects_escape_attempts_absolute_paths_and_missing_files(): void
    {
        $bad = [
            '../secret.png' => 'poza|\.\.',
            'images/../../secret.png' => '\.\.',
            '/etc/passwd' => 'względna',
            $this->root.'/secret.png' => 'względna',
            'C:\\Windows\\win.ini' => 'względna',
            'images/nope.png' => 'nie istnieje',
            "images/ok.png\0.jpg" => 'nieprawidłowa',
            '' => 'nieprawidłowa',
        ];

        foreach ($bad as $path => $pattern) {
            try {
                $this->loader()->load((string) $path, $this->base, $this->scratch);
                $this->fail("accepted: {$path}");
            } catch (ImageFetchException $e) {
                $this->assertMatchesRegularExpression("/{$pattern}/u", $e->getMessage(), (string) $path);
            }
        }
    }

    public function test_rejects_a_symlink_that_points_outside_the_directory(): void
    {
        if (! @symlink($this->root.'/secret.png', $this->base.'/images/link.png')) {
            $this->markTestSkipped('symlinks not available');
        }

        $this->expectException(ImageFetchException::class);
        $this->expectExceptionMessage('poza katalog');

        $this->loader()->load('images/link.png', $this->base, $this->scratch);
    }

    public function test_content_is_validated_not_the_extension(): void
    {
        $this->expectException(ImageFetchException::class);
        $this->expectExceptionMessage('niedozwolony typ');

        $this->loader()->load('images/fake.jpg', $this->base, $this->scratch);
    }
}
