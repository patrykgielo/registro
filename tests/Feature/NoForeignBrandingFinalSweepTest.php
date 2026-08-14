<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Pins the third dead-but-fixed ios/ component found by the final
 * resources/views/ sweep at the end of feature/remove-foreign-branding —
 * see the "Final sweep result" section of
 * app/docs/features/tenant-branding.md. (The other finding from that sweep,
 * booking/create.blade.php's hardcoded description fallback, is inside a
 * view with enough undefined-variable dependencies from other unrelated
 * settings that isolating it in a test isn't worth the complexity for code
 * that is itself entirely unreachable — see that file's own history in this
 * document. Fixed there without a dedicated regression test.)
 */
class NoForeignBrandingFinalSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_banner_component_has_no_hardcoded_detailing_defaults(): void
    {
        $html = Blade::render('<x-ios.hero-banner />');

        $this->assertStringNotContainsString('Detailing', $html);
        $this->assertStringNotContainsString('detailingowe', $html);
    }

    public function test_hero_banner_component_still_renders_a_passed_title(): void
    {
        $html = Blade::render('<x-ios.hero-banner title="Wynajem sprzętu" subtitle="Zarezerwuj online" />');

        $this->assertStringContainsString('Wynajem sprzętu', $html);
        $this->assertStringContainsString('Zarezerwuj online', $html);
    }
}
