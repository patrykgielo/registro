<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins the fix for fix/tenant-scoped-storage-url: the "public" disk's URL was
 * baked from APP_URL at config-load time (config/filesystems.php:44) and never
 * touched again. On the shared stack, a tenant's admin panel lives on its own
 * subdomain — a different origin than APP_URL's root domain — so any code path
 * that fetch()es a Storage::url() (FilePond's preview loader in the panel; see
 * ResolveTenant::forceTenantOriginUrls()'s docblock) hit CORS and hung on
 * "Loading" forever, even though the file itself served fine over plain <img>.
 *
 * Uses the REAL ResolveTenant, not a bind override (TenantBrandNameRegressionTest's
 * pattern) — the fix lives inside ResolveTenant itself, so swapping the class out
 * would never exercise it.
 */
class TenantScopedStorageUrlTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_HOST = 'http://storage-tenant.registro.local';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
    }

    private function makeTenant(): Organization
    {
        $owner = User::factory()->create();

        return Organization::create([
            'name' => 'Storage Tenant',
            'slug' => 'storage-tenant',
            'booking_type' => 'item_rental',
            'owner_id' => $owner->id,
        ]);
    }

    public function test_storage_url_uses_the_tenants_own_host_on_a_tenant_request(): void
    {
        $this->makeTenant();

        $this->get(self::TENANT_HOST.'/')->assertOk();

        $url = Storage::disk('public')->url('locations/test.webp');

        $this->assertStringStartsWith(self::TENANT_HOST.'/storage/', $url);
    }

    public function test_storage_url_still_uses_the_root_domain_on_a_root_domain_request(): void
    {
        $this->get('http://registro.local/')->assertOk();

        $url = Storage::disk('public')->url('logos/test.webp');

        $this->assertStringStartsWith(config('app.url').'/storage/', $url);
    }

    public function test_storage_url_falls_back_to_app_url_outside_any_request(): void
    {
        // No HTTP request made at all in this test — simulates queue/CLI context,
        // where ResolveTenant never runs, so the disk's 'url' config is never
        // touched and Storage::url() falls back to config/filesystems.php's
        // APP_URL-derived default. This does NOT prove config isolation between
        // requests (TestCase::refreshApplication() rebuilds a fresh container
        // per test method regardless, so cross-request leakage can't manifest
        // in this harness either way) — see test_storage_url_uses_the_tenants_
        // own_host_even_when_the_disk_was_resolved_before_the_request() below
        // for the case that actually exercises re-resolution.
        $url = Storage::disk('public')->url('logos/test.webp');

        $this->assertStringStartsWith(config('app.url').'/storage/', $url);
    }

    /**
     * Guards ResolveTenant::forceTenantOriginUrls()'s Storage::forgetDisk('public')
     * call. FilesystemManager caches the built adapter on first resolution, and
     * FilesystemAdapter::url() reads the 'url' it was constructed with, not
     * config() live — so a config(['filesystems.disks.public.url' => ...]) write
     * alone is a silent no-op if ANYTHING resolved the "public" disk before this
     * middleware ran. This test resolves it first, on purpose, before the tenant
     * request — pinning that the middleware still wins.
     */
    public function test_storage_url_uses_the_tenants_own_host_even_when_the_disk_was_resolved_before_the_request(): void
    {
        $this->makeTenant();

        // Pre-resolve and cache the "public" disk adapter BEFORE the tenant
        // request — reproduces the exact staleness the forgetDisk() call guards.
        Storage::disk('public')->url('logos/pre-resolved.webp');

        $this->get(self::TENANT_HOST.'/')->assertOk();

        $url = Storage::disk('public')->url('locations/test.webp');

        $this->assertStringStartsWith(self::TENANT_HOST.'/storage/', $url);
    }
}
