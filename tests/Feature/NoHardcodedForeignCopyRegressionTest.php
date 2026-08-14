<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageLayout;
use App\Models\Organization;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the two hardcoded-text findings from the code review round of
 * feature/remove-foreign-branding — neither is a Setting row, so no amount
 * of sweeping the settings table (see app/docs/features/tenant-branding.md)
 * would ever have caught them. Same "no invented replacement copy" rule as
 * everything else in that document — both strings are removed outright, not
 * reworded.
 */
class NoHardcodedForeignCopyRegressionTest extends TestCase
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

    public function test_cms_default_layout_page_shows_no_hardcoded_detailing_cta(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'No Ad Org',
            'slug' => 'no-ad-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $page = Page::create([
            'organization_id' => $org->id,
            'title' => 'O nas',
            'slug' => 'o-nas',
            'body' => 'Treść strony.',
            'content' => [],
            'layout' => PageLayout::DEFAULT,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAsTenant($org)
            ->get('http://no-ad-org.registro.local/'.$page->slug)
            ->assertOk();

        $response->assertDontSee('Profesjonalny detailing', false);
        $response->assertDontSee('Umów wizytę już dziś', false);
    }

    public function test_booking_wizard_step_one_shows_no_hardcoded_detailing_subtitle(): void
    {
        $owner = User::factory()->create();

        $org = Organization::create([
            'name' => 'No Subtitle Org',
            'slug' => 'no-subtitle-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $customer = User::factory()->create();

        $response = $this->actingAsTenant($org)
            ->actingAs($customer)
            ->get(route('booking.step', 1))
            ->assertOk();

        $response->assertDontSee('detailingu dla Twojego pojazdu', false);
        $response->assertSee('Wybierz usługę', false);
    }
}
