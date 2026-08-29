<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\TemplateKey;
use PHPUnit\Framework\TestCase;

class TemplateKeyTest extends TestCase
{
    /**
     * Pins label()'s match() staying exhaustive against TemplateKey::cases().
     *
     * A missing case here is not a compile-time error (label() deliberately has
     * no `default` -- see the docblock on that decision), it's an
     * \UnhandledMatchError at RUNTIME the first time that specific case is
     * rendered. TENANT_WELCOME and TENANT_REGISTERED_OPERATOR were missing and
     * only surfaced through EmailSendResource/EmailTemplateResource's index
     * pages 500ing (PanelWalkthroughTest, 2026-08-30) -- looping every case
     * here catches the next one immediately, at the enum, before any Filament
     * page has to be the one to notice.
     */
    public function test_label_is_defined_for_every_case(): void
    {
        foreach (TemplateKey::cases() as $case) {
            $this->assertNotSame('', $case->label(), "TemplateKey::{$case->name}->label() must not be empty.");
        }
    }

    public function test_options_covers_every_case(): void
    {
        $this->assertCount(count(TemplateKey::cases()), TemplateKey::options());
    }

    public function test_tenant_onboarding_labels(): void
    {
        $this->assertSame('Powitanie nowego tenanta', TemplateKey::TENANT_WELCOME->label());
        $this->assertSame('Nowy tenant (operator platformy)', TemplateKey::TENANT_REGISTERED_OPERATOR->label());
    }
}
