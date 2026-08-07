<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Control group for TenantSlugGatingTest: with TENANT_SLUG unset (the default for
 * every other test in the suite, and for the shared legacy stack / dev), both
 * surfaces that class gates must remain reachable.
 */
class TenantSlugGatingDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_registration_route_exists_without_tenant_slug(): void
    {
        $this->assertNull(config('app.tenant_slug'));
        $this->assertTrue(Route::has('register'));
    }

    public function test_platform_panel_is_registered_without_tenant_slug(): void
    {
        $this->assertArrayHasKey('platform', Filament::getPanels());
    }
}
