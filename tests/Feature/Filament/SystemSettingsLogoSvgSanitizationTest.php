<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SystemSettings;
use App\Models\Organization;
use App\Models\User;
use App\Support\Media\SvgLogoSanitizer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pins that BOTH SystemSettings logo uploads (header + footer, two separate
 * `saveUploadedFileUsing` closures in the appearance tab) go through SvgLogoSanitizer.
 *
 * Driven through the real page: `saveAppearanceSettings()` -> HasGroupedSettings::saveSettingsGroup()
 * -> callBeforeStateDehydrated() -> Filament BaseFileUpload::saveUploadedFiles() -> our closure.
 * Each field runs as its own data-provider case, so dropping the call from either closure fails
 * that field's cases (the dirty-SVG one, the rejection one and the spy one).
 *
 * Setup follows SystemSettingsCheckoutTabSaveTest (Filament::setTenant, not a request attribute —
 * Livewire::test() swaps the request object; see that test's docblock).
 */
class SystemSettingsLogoSvgSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private const CLEAN_PATH = 'd="M0 0h10v10H0z"';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, array{string}> */
    public static function logoFields(): array
    {
        return [
            'header logo' => ['header_logo'],
            'footer logo' => ['footer_logo'],
        ];
    }

    private function openPage(): Testable
    {
        $org = Organization::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        $this->actingAs($admin);
        Filament::setTenant($org);

        return Livewire::test(SystemSettings::class);
    }

    private function svgFile(string $svg): UploadedFile
    {
        $file = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $this->assertSame('image/svg+xml', $file->getMimeType());

        return $file;
    }

    /** @return list<string> */
    private function storedLogos(): array
    {
        return Storage::disk('public')->allFiles('settings/logos');
    }

    #[DataProvider('logoFields')]
    public function test_dirty_svg_is_stored_sanitised(string $field): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
            .'<script>alert(2)</script>'
            .'<a xlink:href="javascript:alert(3)"><path '.self::CLEAN_PATH.'/></a>'
            .'<image href="https://evil.example/x.png" width="5" height="5"/>'
            .'</svg>';

        $this->openPage()
            ->set("data.appearance.{$field}", $this->svgFile($dirty))
            ->call('saveAppearanceSettings')
            ->assertHasNoErrors();

        $files = $this->storedLogos();
        $this->assertCount(1, $files);

        $stored = Storage::disk('public')->get($files[0]);
        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $stored);
        $this->assertStringNotContainsString('alert(', $stored);
        $this->assertStringNotContainsString('evil.example', $stored);
        $this->assertStringContainsString(self::CLEAN_PATH, $stored);
    }

    #[DataProvider('logoFields')]
    public function test_svg_with_dtd_entity_is_rejected_and_nothing_is_stored(string $field): void
    {
        $dirty = '<?xml version="1.0"?>'
            .'<!DOCTYPE svg [<!ENTITY x "<script>alert(1)</script>">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text><path '.self::CLEAN_PATH.'/></svg>';

        $page = $this->openPage()->set("data.appearance.{$field}", $this->svgFile($dirty));

        $rejected = null;

        try {
            $page->call('saveAppearanceSettings');
        } catch (\Exception $e) {
            $rejected = $e;
        }

        $this->assertNotNull($rejected, "The {$field} upload must reject an SVG declaring a DTD entity.");
        $this->assertSame('SVG contains dangerous content', $rejected->getMessage());
        $this->assertSame([], $this->storedLogos());
    }

    #[DataProvider('logoFields')]
    public function test_upload_goes_through_the_shared_sanitizer(string $field): void
    {
        $this->mock(SvgLogoSanitizer::class, function ($mock): void {
            $mock->shouldReceive('sanitizeStoredFile')
                ->once()
                ->with(Mockery::pattern('#^settings/logos/[^/]+\.svg$#'));
        });

        $this->openPage()
            ->set("data.appearance.{$field}", $this->svgFile('<svg xmlns="http://www.w3.org/2000/svg"><path '.self::CLEAN_PATH.'/></svg>'))
            ->call('saveAppearanceSettings')
            ->assertHasNoErrors();
    }
}
