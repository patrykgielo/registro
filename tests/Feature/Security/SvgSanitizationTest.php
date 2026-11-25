<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\DesignHub;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pins the SVG upload sanitisation path (enshrined/svg-sanitize) used by the tenant logo uploads.
 *
 * Written when the library moved 0.22.0 -> 1.1.1 (advisories: DTD/entity stored XSS, mixed-case
 * xlink:href <use> DoS, DTD attribute-declaration crash). Nothing exercised the sanitizer before,
 * so a downgrade or a removed sanitize() call would have gone unnoticed.
 *
 * What catches what: a removed sanitize() call fails the stripping / javascript: / remote-ref /
 * DTD tests. A downgrade to <=0.22 fails the DTD-entity rejection test (0.22 strips the entity and
 * returns a string). The ATTLIST and mixed-case <use> tests are smoke tests of 1.x behaviour only:
 * their payloads do not crash 0.22 either, so they do NOT reproduce those advisories.
 *
 * REAL path: DesignHub::processLogoUpload() (private) is driven via reflection with a genuine
 * Livewire TemporaryUploadedFile, writing to a faked `public` disk.
 *
 * The SystemSettings header/footer uploads share the same code (App\Support\Media\SvgLogoSanitizer)
 * and are pinned through the real page in Tests\Feature\Filament\SystemSettingsLogoSvgSanitizationTest;
 * the class contract (incl. garbage/empty input -> reject) lives in SvgLogoSanitizerTest.
 */
class SvgSanitizationTest extends TestCase
{
    private const CLEAN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Build a real TemporaryUploadedFile whose content is $svg.
     *
     * The `-mimeType=...-` filename segment is Livewire's own test hook (see
     * TemporaryUploadedFile::getMimeType) that forces the MIME the app branches on, so the test
     * does not depend on libmagic's guess for documents that carry a DOCTYPE.
     */
    private function svgUpload(string $svg): TemporaryUploadedFile
    {
        $name = 'logo-mimeType=image_svg+xml-.svg';

        FileUploadConfiguration::storage()->put(FileUploadConfiguration::directory().'/'.$name, $svg);

        $file = new TemporaryUploadedFile($name, FileUploadConfiguration::disk());

        $this->assertSame('image/svg+xml', $file->getMimeType());

        return $file;
    }

    /** Runs the real DesignHub upload handler; returns the stored path. */
    private function processLogoUpload(TemporaryUploadedFile $file): string
    {
        $method = new ReflectionMethod(DesignHub::class, 'processLogoUpload');

        return $method->invoke(new DesignHub, $file);
    }

    private function storedContent(string $path): string
    {
        Storage::disk('public')->assertExists($path);

        return Storage::disk('public')->get($path);
    }

    public function test_clean_svg_logo_is_stored_and_keeps_its_path(): void
    {
        $path = $this->processLogoUpload($this->svgUpload(self::CLEAN_SVG));

        $this->assertStringStartsWith('settings/logos/', $path);

        $stored = $this->storedContent($path);
        $this->assertStringContainsString('<svg', $stored);
        $this->assertStringContainsString('<path', $stored);
        $this->assertStringContainsString('d="M0 0h10v10H0z"', $stored);
    }

    public function test_script_element_and_event_handler_attributes_are_stripped(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
            .'<script>alert(2)</script>'
            .'<path d="M0 0h10v10H0z" onclick="alert(3)"/>'
            .'</svg>';

        $stored = $this->storedContent($this->processLogoUpload($this->svgUpload($dirty)));

        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsString('alert(', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $stored);
        // The harmless geometry survives.
        $this->assertStringContainsString('d="M0 0h10v10H0z"', $stored);
    }

    public function test_javascript_urls_are_removed_from_href_and_xlink_href(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<a xlink:href="javascript:alert(1)"><path d="M0 0h10v10H0z"/></a>'
            .'<a href="javascript:alert(2)"><rect width="5" height="5"/></a>'
            .'</svg>';

        $stored = $this->storedContent($this->processLogoUpload($this->svgUpload($dirty)));

        $this->assertStringNotContainsStringIgnoringCase('javascript:', $stored);
        $this->assertStringNotContainsString('alert(', $stored);
    }

    public function test_remote_references_are_removed(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<image href="https://evil.example/x.png" width="5" height="5"/>'
            .'<image xlink:href="https://evil.example/y.png" width="5" height="5"/>'
            .'<path d="M0 0h10v10H0z"/>'
            .'</svg>';

        $stored = $this->storedContent($this->processLogoUpload($this->svgUpload($dirty)));

        $this->assertStringNotContainsString('evil.example', $stored);
        $this->assertStringContainsString('<path', $stored);
    }

    public function test_svg_with_dtd_entity_declaration_is_rejected_and_nothing_is_stored(): void
    {
        $dirty = '<?xml version="1.0"?>'
            .'<!DOCTYPE svg [<!ENTITY x "<script>alert(1)</script>">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text><path d="M0 0h10v10H0z"/></svg>';

        // Observed with enshrined/svg-sanitize 1.1.1: sanitize() returns false for an entity
        // declaration, which our handler turns into delete + throw (no stripped copy is kept).
        $rejected = null;

        try {
            $this->processLogoUpload($this->svgUpload($dirty));
        } catch (\Exception $e) {
            $rejected = $e;
        }

        $this->assertNotNull($rejected, 'An SVG declaring an entity in its DTD must be rejected.');
        $this->assertSame('SVG contains dangerous content', $rejected->getMessage());
        $this->assertSame([], Storage::disk('public')->allFiles('settings/logos'));
    }

    public function test_svg_with_dtd_attribute_declaration_does_not_crash_and_is_stored_without_doctype(): void
    {
        // Smoke test (not the advisory PoC): 1.1.1 strips a DTD without entities and carries on.
        $dirty = '<!DOCTYPE svg [<!ELEMENT svg ANY><!ATTLIST svg foo CDATA #IMPLIED>]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10H0z"/></svg>';

        $stored = $this->storedContent($this->processLogoUpload($this->svgUpload($dirty)));

        $this->assertStringNotContainsStringIgnoringCase('<!DOCTYPE', $stored);
        $this->assertStringNotContainsStringIgnoringCase('<!ATTLIST', $stored);
        $this->assertStringContainsString('<path', $stored);
    }

    public function test_mixed_case_xlink_href_on_use_does_not_crash_and_is_not_kept(): void
    {
        // Smoke test (not the advisory PoC): mixed-case / data: hrefs on <use> are not kept.
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<use XLINK:HREF="#a"/><use xlink:href="data:image/svg+xml;base64,PHN2Zy8+"/>'
            .'<path d="M0 0h10v10H0z"/>'
            .'</svg>';

        $stored = $this->storedContent($this->processLogoUpload($this->svgUpload($dirty)));

        $this->assertStringNotContainsStringIgnoringCase('xlink:href', $stored);
        $this->assertStringNotContainsStringIgnoringCase('data:', $stored);
        $this->assertStringContainsString('<path', $stored);
    }
}
