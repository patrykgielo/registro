<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\DesignHub;
use App\Support\Media\SvgLogoSanitizer;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The shared SVG logo sanitiser itself, plus the DesignHub "goes through it" pin.
 *
 * Library-level stripping behaviour is covered end-to-end in SvgSanitizationTest (DesignHub) and
 * SystemSettingsLogoSvgSanitizationTest (header/footer); this file pins the class's own contract:
 * fail closed, delete on reject, honour the disk argument.
 */
class SvgLogoSanitizerTest extends TestCase
{
    private const CLEAN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_clean_svg_is_rewritten_in_place(): void
    {
        Storage::disk('public')->put('settings/logos/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><path d="M0 0h10v10H0z"/></svg>');

        (new SvgLogoSanitizer)->sanitizeStoredFile('settings/logos/a.svg');

        $stored = Storage::disk('public')->get('settings/logos/a.svg');
        $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
        $this->assertStringContainsString('d="M0 0h10v10H0z"', $stored);
    }

    /** @return array<string, array{string}> */
    public static function rejectedContent(): array
    {
        return [
            'not xml' => ['this is not xml at all <<<'],
            'dtd entity' => ['<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'],
            'whitespace only' => ["  \n\t "],
            // sanitize('') returns '' rather than false; rejecting it is the one deliberate
            // tightening versus the pre-refactor inline copies.
            'empty file' => [''],
        ];
    }

    #[DataProvider('rejectedContent')]
    public function test_rejected_content_deletes_the_file_and_throws(string $content): void
    {
        Storage::disk('public')->put('settings/logos/bad.svg', $content);

        try {
            (new SvgLogoSanitizer)->sanitizeStoredFile('settings/logos/bad.svg');
            $this->fail('Expected the SVG to be rejected.');
        } catch (\Exception $e) {
            $this->assertSame('SVG contains dangerous content', $e->getMessage());
        }

        Storage::disk('public')->assertMissing('settings/logos/bad.svg');
    }

    public function test_it_works_on_the_disk_it_is_given(): void
    {
        Storage::fake('other');
        Storage::disk('other')->put('x.svg', self::CLEAN_SVG);
        Storage::disk('other')->put('bad.svg', 'not xml <<<');

        $sanitizer = new SvgLogoSanitizer;
        $sanitizer->sanitizeStoredFile('x.svg', 'other');
        $this->assertStringContainsString('<path', Storage::disk('other')->get('x.svg'));

        try {
            $sanitizer->sanitizeStoredFile('bad.svg', 'other');
            $this->fail('Expected the SVG to be rejected.');
        } catch (\Exception) {
            Storage::disk('other')->assertMissing('bad.svg');
        }
    }

    public function test_designhub_logo_upload_goes_through_the_shared_sanitizer(): void
    {
        $this->mock(SvgLogoSanitizer::class, function ($mock): void {
            $mock->shouldReceive('sanitizeStoredFile')
                ->once()
                ->with(Mockery::pattern('#^settings/logos/[^/]+\.svg$#'));
        });

        // Same real-upload construction as SvgSanitizationTest::svgUpload() (Livewire's
        // `-mimeType=...-` test hook forces the MIME the handler branches on).
        $name = 'logo-mimeType=image_svg+xml-.svg';
        FileUploadConfiguration::storage()->put(FileUploadConfiguration::directory().'/'.$name, self::CLEAN_SVG);
        $file = new TemporaryUploadedFile($name, FileUploadConfiguration::disk());

        (new ReflectionMethod(DesignHub::class, 'processLogoUpload'))->invoke(new DesignHub, $file);
    }
}
