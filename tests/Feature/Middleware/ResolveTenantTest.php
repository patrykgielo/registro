<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    private ResolveTenant $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ResolveTenant;
    }

    public function test_root_domain_passes_through_without_tenant(): void
    {
        config(['app.domain' => 'registro.local']);

        $request = Request::create('https://registro.local/');
        $request->headers->set('HOST', 'registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNull($request->attributes->get('tenant'));
    }

    public function test_valid_subdomain_resolves_tenant(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Demo Salon',
            'slug' => 'demo',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $request = Request::create('https://demo.registro.local/');
        $request->headers->set('HOST', 'demo.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($request->attributes->get('tenant'));
        $this->assertEquals($org->id, $request->attributes->get('tenant')->id);
    }

    public function test_unknown_subdomain_redirects_to_root(): void
    {
        config(['app.domain' => 'registro.local']);

        $request = Request::create('https://unknown.registro.local/');
        $request->headers->set('HOST', 'unknown.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertTrue($response->isRedirection());
        $this->assertStringContains('registro.local', $response->headers->get('Location'));
    }

    public function test_inactive_tenant_redirects_to_root(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        Organization::factory()->inactive()->create([
            'name' => 'Inactive Salon',
            'slug' => 'inactive',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $request = Request::create('https://inactive.registro.local/');
        $request->headers->set('HOST', 'inactive.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertTrue($response->isRedirection());
    }

    public function test_suspended_lifecycle_tenant_redirects_to_root(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        Organization::factory()->inactive()->create([
            'name' => 'Suspended Salon',
            'slug' => 'suspended',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $request = Request::create('https://suspended.registro.local/');
        $request->headers->set('HOST', 'suspended.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        // Suspended lifecycle_state is not Active — public site must be blocked
        $this->assertTrue($response->isRedirection());
        $this->assertStringContains('registro.local', $response->headers->get('Location'));
    }

    public function test_closing_lifecycle_tenant_redirects_to_root(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        Organization::factory()->closing()->create([
            'name' => 'Closing Salon',
            'slug' => 'closingorg',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        $request = Request::create('https://closingorg.registro.local/');
        $request->headers->set('HOST', 'closingorg.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        // Closing state does not allow public site access
        $this->assertTrue($response->isRedirection());
    }

    public function test_active_lifecycle_tenant_resolves_successfully(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Active Salon',
            'slug' => 'activeslug',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            // lifecycle_state defaults to 'active' via DB default + observer
        ]);

        $request = Request::create('https://activeslug.registro.local/');
        $request->headers->set('HOST', 'activeslug.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($org->id, $request->attributes->get('tenant')->id);
    }

    public function test_invalid_slug_format_redirects_to_root(): void
    {
        config(['app.domain' => 'registro.local']);

        $request = Request::create('https://INVALID.registro.local/');
        $request->headers->set('HOST', 'INVALID.registro.local');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertTrue($response->isRedirection());
    }

    public function test_foreign_domain_redirects_to_root(): void
    {
        config(['app.domain' => 'registro.local']);

        $request = Request::create('https://evil.com/');
        $request->headers->set('HOST', 'evil.com');

        $response = $this->middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertTrue($response->isRedirection());
    }

    public function test_tenant_is_cached(): void
    {
        config(['app.domain' => 'registro.local']);

        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Cached Salon',
            'slug' => 'cached',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        // First request populates cache
        $request = Request::create('https://cached.registro.local/');
        $request->headers->set('HOST', 'cached.registro.local');

        $this->middleware->handle($request, fn () => response('ok'));

        $this->assertTrue(Cache::has('tenant:slug:cached'));
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertStringContainsString($needle, $haystack);
    }
}
