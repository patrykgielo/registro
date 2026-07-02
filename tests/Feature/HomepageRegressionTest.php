<?php

namespace Tests\Feature;

use App\Enums\PageLayout;
use App\Models\Organization;
use App\Models\Page;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTenant(Organization $org): static
    {
        config(['app.domain' => 'registro.local']);

        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    public function test_homepage_renders_when_cms_homepage_configured(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $page = Page::create([
            'organization_id' => $org->id,
            'title' => 'Strona główna',
            'slug' => 'strona-glowna',
            'body' => 'Body',
            'content' => [],
            'layout' => PageLayout::DEFAULT,
            'published_at' => now()->subDay(),
        ]);

        app(SettingsManager::class)->set('cms.homepage_page_id', $page->id);

        $this->actingAsTenant($org)
            ->get('http://test-org.registro.local/')
            ->assertOk()
            ->assertSee('Strona główna');
    }

    public function test_homepage_falls_back_when_no_cms_homepage_configured(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'Test Org 2',
            'slug' => 'test-org-2',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->actingAsTenant($org)
            ->get('http://test-org-2.registro.local/')
            ->assertOk()
            ->assertSee('Homepage Not Configured');
    }
}
