<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Route registration and Filament panel registration both happen once, at
 * application boot, driven by config('app.tenant_slug') (see routes/web.php and
 * bootstrap/providers.php). Toggling config() from inside a test method is too
 * late for either -- the routes/providers for that test's application instance
 * are already fixed by the time the test body runs. TENANT_SLUG has to be present
 * in the process environment BEFORE Laravel boots, so it's set here via putenv()
 * in setUp() before parent::setUp() triggers that boot. Dotenv (which loads
 * .env.testing) never overwrites an already-set environment variable, so this
 * sticks. tearDown() unsets it so later tests in the same run see the normal,
 * ungated state again.
 */
class TenantSlugGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('TENANT_SLUG=acme-rentals');
        $_ENV['TENANT_SLUG'] = 'acme-rentals';
        $_SERVER['TENANT_SLUG'] = 'acme-rentals';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('TENANT_SLUG');
        unset($_ENV['TENANT_SLUG'], $_SERVER['TENANT_SLUG']);

        parent::tearDown();
    }

    public function test_tenant_slug_is_resolved_into_config(): void
    {
        $this->assertSame('acme-rentals', config('app.tenant_slug'));
    }

    public function test_business_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/register/step/2')->assertNotFound();
        $this->get('/get-started')->assertNotFound();
    }

    public function test_business_registration_route_names_are_not_registered(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.step2.store'));
    }

    public function test_platform_panel_is_not_registered(): void
    {
        $this->get('/platform/login')->assertNotFound();

        $this->assertArrayNotHasKey('platform', Filament::getPanels());
    }

    public function test_admin_panel_remains_registered(): void
    {
        $this->assertArrayHasKey('admin', Filament::getPanels());
    }
}
