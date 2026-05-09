<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RentalCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->equipmentRental()->create();
    }

    private function actingAsTenant(Organization $org): static
    {
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

    // -------------------------------------------------------------------------
    // /wypozyczalnia — index
    // -------------------------------------------------------------------------

    public function test_index_returns_200(): void
    {
        $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia')
            ->assertOk();
    }

    public function test_index_shows_active_categories(): void
    {
        $active = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $inactive = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => false,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia')
            ->assertOk();

        $response->assertSee($active->name);
        $response->assertDontSee($inactive->name);
    }

    public function test_index_shows_featured_rental_services(): void
    {
        $category = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'rental_category_id' => $category->id,
        ]);

        $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia')
            ->assertOk()
            ->assertSee($service->name);
    }

    public function test_index_is_empty_state_when_no_categories(): void
    {
        $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia')
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // /wypozyczalnia/{category:slug} — category page
    // -------------------------------------------------------------------------

    public function test_category_page_returns_200_for_active_category(): void
    {
        $category = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $this->actingAsTenant($this->org)
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();
    }

    public function test_category_page_returns_404_for_inactive_category(): void
    {
        $category = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => false,
        ]);

        $this->actingAsTenant($this->org)
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertNotFound();
    }

    public function test_category_page_returns_404_for_unknown_slug(): void
    {
        $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia/nie-istnieje')
            ->assertNotFound();
    }

    public function test_category_page_shows_only_services_in_category(): void
    {
        $category = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $otherCategory = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $inCategory = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'rental_category_id' => $category->id,
        ]);

        $outOfCategory = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'rental_category_id' => $otherCategory->id,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->get("/wypozyczalnia/{$category->slug}")
            ->assertOk();

        $response->assertSee($inCategory->name);
        $response->assertDontSee($outOfCategory->name);
    }

    public function test_category_page_does_not_show_other_tenant_services(): void
    {
        $otherOrg = Organization::factory()->equipmentRental()->create();

        $ownCategory = RentalCategory::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        $ownService = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'rental_category_id' => $ownCategory->id,
        ]);

        $otherService = Service::factory()->itemRental()->create([
            'organization_id' => $otherOrg->id,
            'rental_category_id' => null,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->get("/wypozyczalnia/{$ownCategory->slug}")
            ->assertOk();

        $response->assertSee($ownService->name);
        $response->assertDontSee($otherService->name);
    }

    // -------------------------------------------------------------------------
    // Old deprecated URLs — must return 404 (routes removed, not 410)
    // -------------------------------------------------------------------------

    public function test_old_step1_url_returns_404(): void
    {
        $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia/jakis-slug/kontakt')
            ->assertNotFound();
    }

    public function test_old_step2_url_returns_404(): void
    {
        $this->actingAsTenant($this->org)
            ->get('/wypozyczalnia/jakis-slug/podsumowanie')
            ->assertNotFound();
    }

    public function test_old_post_step1_returns_405(): void
    {
        // GET /wypozyczalnia/{slug} exists (rental.category) — POST is not allowed
        $this->actingAsTenant($this->org)
            ->post('/wypozyczalnia/jakis-slug')
            ->assertMethodNotAllowed();
    }

    // -------------------------------------------------------------------------
    // API availability endpoints — must remain functional
    // -------------------------------------------------------------------------

    public function test_availability_api_is_not_affected(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->getJson(route('rental.availability', $service).'?start_date='.now()->addDay()->toDateString().'&end_date='.now()->addDays(3)->toDateString());

        $response->assertOk()
            ->assertJsonStructure(['available_quantity', 'total_quantity']);
    }
}
