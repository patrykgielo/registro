<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Regression test for the root-domain tenant isolation hotfix.
 *
 * Prior to RequireTenant, ResolveTenant let the bare root domain (no subdomain)
 * pass through with no `tenant` request attribute. Every route below queries a
 * BelongsToOrganization model — without a resolved tenant, the global scope was
 * a silent no-op and these routes served completely unscoped, cross-tenant data
 * to anonymous visitors. RequireTenant now hard-404s any of these routes when
 * no tenant is resolved.
 */
class RootDomainTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_home_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/')
            ->assertNotFound();
    }

    public function test_post_show_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/aktualnosci/jakis-post')
            ->assertNotFound();
    }

    public function test_service_index_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/uslugi')
            ->assertNotFound();
    }

    public function test_rental_index_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/wypozyczalnia')
            ->assertNotFound();
    }

    public function test_cms_page_catch_all_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/o-nas')
            ->assertNotFound();
    }

    public function test_admin_login_returns_404_on_root_domain(): void
    {
        // Admin panel shares ResolveTenant for tenant context (no native Filament
        // tenancy configured). On the root domain there is no tenant to resolve,
        // so RequireTenant must reject this before Filament's own auth check runs.
        $this->get('http://registro.local/admin/login')
            ->assertNotFound();
    }
}
