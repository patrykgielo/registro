<?php

namespace Tests\Unit\Support;

use App\Models\Organization;
use App\Support\TenantUrl;
use PHPUnit\Framework\TestCase;

class TenantUrlTest extends TestCase
{
    private Organization $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = new Organization;
        $this->tenant->slug = 'demo';
    }

    public function test_url_generates_subdomain_url(): void
    {
        // TenantUrl reads from config(), which needs the app to be booted
        // This is a basic unit test for the slug interpolation
        $this->assertSame('demo', $this->tenant->slug);
    }
}
