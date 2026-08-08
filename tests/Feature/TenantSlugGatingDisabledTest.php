<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Control group for TenantSlugGatingTest: with TENANT_SLUG unset (the default for
 * every other test in the suite, and for the shared legacy stack / dev), the
 * platform panel that class gates must remain reachable.
 *
 * Used to also cover the public business-registration wizard, which was
 * gated the same way (`if (! config('app.tenant_slug'))` in routes/web.php).
 * That wizard is gone entirely now -- not just re-gated -- so there is no
 * "route exists without TENANT_SLUG" case left to assert; see
 * TenantSlugGatingTest::test_business_registration_route_names_are_not_registered()
 * for the route staying absent regardless of TENANT_SLUG.
 */
class TenantSlugGatingDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_panel_is_registered_without_tenant_slug(): void
    {
        $this->assertArrayHasKey('platform', Filament::getPanels());
    }
}
