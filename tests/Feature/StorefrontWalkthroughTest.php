<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Onboarding\ProvisionTenantOrganization;
use App\Actions\Onboarding\Seeders\SeedEquipmentRental;
use App\Enums\Industry;
use App\Models\Category;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Layer 2 of the integration-test architecture (see
 * tests/Feature/Filament/PanelWalkthroughTest.php for Layer 1 / the admin panel):
 * a data-driven walk of the PUBLIC customer-facing site, over real HTTP, through the REAL
 * `ResolveTenant` middleware and a real Host header -- not a bound test double. Layer 1 proved
 * "does an existing admin panel record survive a no-op edit"; this layer proves "does an ordinary
 * storefront visit still work, and does tenant A's content ever leak onto tenant B's subdomain".
 * Nobody was asking that second question before this file -- the panel's own tenant isolation is
 * exercised implicitly every time an admin logs into their own tenant, but the storefront's
 * cross-tenant boundary (BelongsToOrganization's global scope, resolved fresh per request from
 * the Host header) has no equivalent everyday exercise.
 *
 * Two tenants, provisioned through the REAL onboarding building blocks
 * (ProvisionTenantOrganization + SeedEquipmentRental) and seeded IDENTICALLY -- same fixed
 * 7-category/13-item catalog, same CMS titles (so the SAME auto-generated slug exists on both
 * tenants) -- for the same reason Layer 1 does this: it is the COLLISION between two tenants
 * sharing a slug that is the interesting, load-bearing case (Faza 5 backfill migration gave every
 * tenant's primary location the literal slug "siedziba-glowna" in production). A single tenant
 * with random Faker data would never reproduce that collision. Where the two tenants' rows share a
 * slug, each row's `body`/`excerpt`/`description` carries a tenant-specific MARKER string invisible
 * to a human skimming two identical-looking demo catalogs but exact for an assertion -- this is
 * what makes "the correct tenant's OWN row rendered" distinguishable from "the correct tenant's
 * row happened to look right by coincidence" or "tenant A's row leaked and nobody noticed because
 * the catalog is identical anyway".
 *
 * Assertions deliberately do NOT use assertSee()/assertDontSee()/assertOk() -- their failure
 * messages embed the ENTIRE response body (a full HTML page can be 1000+ lines), which is fine for
 * a single assertion but unusable inside an accumulate-and-report-at-the-end harness: the first
 * failure's page dump would drown out every other violation in the final report. assertStatus()/
 * assertContains()/assertNotContains() below produce one short line each instead.
 *
 * FINDING (see the report for the full writeup): the cross-tenant checks below caught a real,
 * previously-undiscovered leak. `SubstituteBindings::class` (implicit `{model:slug}` route model
 * binding) ships baked into Laravel's default `web` middleware GROUP (vendor/laravel/framework/
 * .../Configuration/Middleware.php, getMiddlewareGroups()) -- which always runs before ANY
 * route-SPECIFIC middleware, including `ResolveTenant`/`RequireTenant` declared first in a route's
 * own `Route::middleware([...])` array. For every route binding a `BelongsToOrganization` model by
 * slug (`{service:slug}`, `{category:slug}`, ...), the bound row is therefore resolved BEFORE the
 * current request's tenant is known: `BelongsToOrganization`'s global scope falls through to
 * `TenantFeature::currentTenant()`'s branch 3 (`session('tenant_id')`, written by ResolveTenant on
 * every PRIOR request) instead of the correct, current-request tenant -- and on the very first
 * request of a session (nothing in session yet, `tenant_resolution_attempted` also not yet set for
 * THIS request), the scope is a complete no-op, so `first()` returns whatever row matching that
 * slug happens to sort first, from ANY tenant. Reproduced directly (see report for the SQL
 * evidence): a request to host B for a slug that also exists on tenant A returned tenant A's row.
 * This is a real gap in VULN-003, orthogonal to its documented Layers 1-6: `RequireTenant`'s own
 * check (on `request->attributes->get('tenant')`) still passes, because ResolveTenant DOES
 * eventually run and sets that attribute correctly -- just one middleware-pipeline step too late
 * to protect the binding that already happened.
 *
 * Scope, and what is deliberately NOT here (see the report for the full list):
 * - GET routes only, matching the brief's own 49-route inventory. `POST/DELETE/PATCH` mutation
 *   endpoints (cart.add, cart.remove, cart.update, checkout.submit) are exercised only where a
 *   GET-only walkthrough cannot otherwise reach real content (cart.add, once, to seed a real
 *   CartItem for cart.show/checkout.show's own content assertions -- not a general POST audit).
 * - `checkout.submit` itself (full order creation, Przelewy24 registration) is NOT walked --
 *   SubmitCheckoutRequest's B2C/B2B branching is a large, already-covered surface
 *   (CheckoutFlowTest) and out of a walkthrough's "does the ordinary path still work" remit.
 * - Booking-wizard routes (`/booking/*`, `/services/{service}/book`, `/my-appointments`) are
 *   excluded on purpose: both tenants here are Industry::EquipmentRental (item_rental only,
 *   CheckBookingEnabled's own supportsAppointments() is false for this booking_type) --
 *   the same "tylko wypożyczalnie sprzętu" business-focus decision this project has already made
 *   elsewhere (test-engineer memory: project_business_focus.md). They are also simply not in the
 *   brief's own 49-route catalog.
 * - `/moje-konto/pojazd` (ProfileController::vehicle) is walked far enough to confirm it 404s --
 *   NOT a bug. `TenantFeature::active('vehicles')` is false by default for EquipmentRental
 *   (Industry::defaultFeatures()), so a 404 here on an equipment-rental tenant is the intended
 *   behaviour, matching this project's own decision to keep the vehicle subsystem off for
 *   non-auto-detailing tenants (models.md: "VehicleType/CarBrand/CarModel -> read-only... subsystem
 *   being removed, do not promote").
 */
class StorefrontWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenantA;

    private Organization $tenantB;

    private Service $serviceA;

    private Service $serviceB;

    private RentalCategory $categoryA;

    private RentalCategory $categoryB;

    private Page $pageA;

    private Page $pageOnlyOnA;

    private Post $postA;

    private Category $postCategoryA;

    private Category $postCategoryB;

    private Promotion $promotionA;

    private PortfolioItem $portfolioItemA;

    private Category $portfolioCategoryA;

    private Category $portfolioCategoryB;

    private User $customerA;

    private Order $orderA;

    private string $hostA;

    private string $hostB;

    private const ROOT = 'http://registro.local';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        Cache::flush();
        $this->withoutMiddleware([ThrottleRequests::class]);

        foreach (['super-admin', 'admin', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $provision = app(ProvisionTenantOrganization::class);
        $seedVertical = app(SeedEquipmentRental::class);

        $this->tenantA = $provision->execute(
            slug: 'storefront-tenant-a',
            name: 'Wypożyczalnia Testowa Front',
            industry: Industry::EquipmentRental,
            ownerEmail: 'owner-a@storefront-walkthrough.test',
            ownerFirstName: 'Ada',
            ownerLastName: 'Frontowa',
        )['organization'];

        $this->tenantB = $provision->execute(
            slug: 'storefront-tenant-b',
            name: 'Wypożyczalnia Testowa Front', // deliberately IDENTICAL name to tenant A
            industry: Industry::EquipmentRental,
            ownerEmail: 'owner-b@storefront-walkthrough.test',
            ownerFirstName: 'Bob',
            ownerLastName: 'Frontowy',
        )['organization'];

        $seedVertical->seed($this->tenantA);
        $seedVertical->seed($this->tenantB);

        $this->hostA = "http://{$this->tenantA->slug}.registro.local";
        $this->hostB = "http://{$this->tenantB->slug}.registro.local";

        // One representative service/category pair per tenant, pulled out of the otherwise
        // IDENTICAL seeded catalog (same name, same slug on both tenants) and given a
        // tenant-specific marker in a field the public views actually render (excerpt/
        // description) -- see class docblock for why this, not the name, is the isolation signal.
        $this->serviceA = Service::where('organization_id', $this->tenantA->id)->where('name', 'Wiertarka udarowa')->firstOrFail();
        $this->serviceA->update(['excerpt' => 'MARKER-SERVICE-TENANT-A tylko dla wypożyczalni A']);
        $this->serviceB = Service::where('organization_id', $this->tenantB->id)->where('name', 'Wiertarka udarowa')->firstOrFail();
        $this->serviceB->update(['excerpt' => 'MARKER-SERVICE-TENANT-B tylko dla wypożyczalni B']);

        $this->categoryA = RentalCategory::where('organization_id', $this->tenantA->id)->where('name', 'Elektronarzędzia')->firstOrFail();
        $this->categoryA->update(['description' => 'MARKER-CATEGORY-TENANT-A tylko dla wypożyczalni A']);
        $this->categoryB = RentalCategory::where('organization_id', $this->tenantB->id)->where('name', 'Elektronarzędzia')->firstOrFail();
        $this->categoryB->update(['description' => 'MARKER-CATEGORY-TENANT-B tylko dla wypożyczalni B']);

        // CMS content categories -- identical name/slug on both tenants, same collision shape.
        $this->postCategoryA = Category::create(['organization_id' => $this->tenantA->id, 'name' => 'Aktualności Walkthrough', 'type' => 'post']);
        $this->postCategoryB = Category::create(['organization_id' => $this->tenantB->id, 'name' => 'Aktualności Walkthrough', 'type' => 'post']);
        $this->portfolioCategoryA = Category::create(['organization_id' => $this->tenantA->id, 'name' => 'Realizacje Walkthrough', 'type' => 'portfolio']);
        $this->portfolioCategoryB = Category::create(['organization_id' => $this->tenantB->id, 'name' => 'Realizacje Walkthrough', 'type' => 'portfolio']);

        // CMS content -- identical title (=> identical auto-slug) on both tenants, marker in
        // `body` (rendered on the detail page AND truncated into listing cards -- see
        // resources/views/components/cms/card.blade.php -- one marker covers both checks).
        $this->pageA = Page::create([
            'organization_id' => $this->tenantA->id,
            'title' => 'Strona Testowa Walkthrough',
            'body' => '<p>MARKER-PAGE-TENANT-A tylko dla wypożyczalni A</p>',
            'published_at' => now()->subDay(),
        ]);
        Page::create([
            'organization_id' => $this->tenantB->id,
            'title' => 'Strona Testowa Walkthrough',
            'body' => '<p>MARKER-PAGE-TENANT-B tylko dla wypożyczalni B</p>',
            'published_at' => now()->subDay(),
        ]);
        // No tenant B counterpart at all -- the OTHER interesting case from the brief ("record
        // exists on A only" -> requesting it from host B must 404, not fall through to anything).
        $this->pageOnlyOnA = Page::create([
            'organization_id' => $this->tenantA->id,
            'title' => 'Strona Tylko Na Tenancie A',
            'body' => '<p>MARKER-PAGE-ONLY-ON-A</p>',
            'published_at' => now()->subDay(),
        ]);

        $this->postA = Post::create([
            'organization_id' => $this->tenantA->id,
            'category_id' => $this->postCategoryA->id,
            'title' => 'Wpis Testowy Walkthrough',
            'body' => '<p>MARKER-POST-TENANT-A tylko dla wypożyczalni A</p>',
            'published_at' => now()->subDay(),
        ]);
        Post::create([
            'organization_id' => $this->tenantB->id,
            'category_id' => $this->postCategoryB->id,
            'title' => 'Wpis Testowy Walkthrough',
            'body' => '<p>MARKER-POST-TENANT-B tylko dla wypożyczalni B</p>',
            'published_at' => now()->subDay(),
        ]);

        $this->promotionA = Promotion::create([
            'organization_id' => $this->tenantA->id,
            'title' => 'Promocja Testowa Walkthrough',
            'body' => '<p>MARKER-PROMOTION-TENANT-A tylko dla wypożyczalni A</p>',
            'active' => true,
        ]);
        Promotion::create([
            'organization_id' => $this->tenantB->id,
            'title' => 'Promocja Testowa Walkthrough',
            'body' => '<p>MARKER-PROMOTION-TENANT-B tylko dla wypożyczalni B</p>',
            'active' => true,
        ]);

        $this->portfolioItemA = PortfolioItem::create([
            'organization_id' => $this->tenantA->id,
            'category_id' => $this->portfolioCategoryA->id,
            'title' => 'Realizacja Testowa Walkthrough',
            'body' => '<p>MARKER-PORTFOLIO-TENANT-A tylko dla wypożyczalni A</p>',
            'published_at' => now()->subDay(),
        ]);
        PortfolioItem::create([
            'organization_id' => $this->tenantB->id,
            'category_id' => $this->portfolioCategoryB->id,
            'title' => 'Realizacja Testowa Walkthrough',
            'body' => '<p>MARKER-PORTFOLIO-TENANT-B tylko dla wypożyczalni B</p>',
            'published_at' => now()->subDay(),
        ]);

        $this->customerA = User::factory()->create([
            'first_name' => 'Klara',
            'last_name' => 'Klientka',
        ]);
        $this->customerA->assignRole('customer');

        $this->orderA = Order::factory()->create([
            'organization_id' => $this->tenantA->id,
            'user_id' => $this->customerA->id,
        ]);
    }

    public function test_storefront_walkthrough_covers_public_content_isolation_and_auth(): void
    {
        $violations = [];

        $this->walkContentAndExistence($violations);
        $this->walkCrossTenantIsolation($violations);
        $this->walkListingIsolation($violations);
        $this->walkGuestVsAuthenticated($violations);
        $this->walkRootDomain($violations);

        if ($violations !== []) {
            $this->fail(
                count($violations)." problem(s) found across the public storefront:\n\n"
                .implode("\n\n", $violations)
            );
        }

        $this->assertTrue(true, 'Walkthrough completed with zero violations.');
    }

    /**
     * @param  list<string>  $violations
     */
    private function check(string $label, callable $fn, array &$violations): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $violations[] = "{$label}: ".$e::class.': '.$e->getMessage();
        }
    }

    /**
     * Compact status assertion -- deliberately NOT TestResponse::assertOk()/assertNotFound(),
     * whose failure message embeds the full response body. See class docblock.
     */
    private function expectStatus(TestResponse $response, int $expected, string $context): TestResponse
    {
        $actual = $response->getStatusCode();
        if ($actual !== $expected) {
            throw new \RuntimeException("{$context} -- expected HTTP {$expected}, got HTTP {$actual}.");
        }

        return $response;
    }

    /**
     * Compact content assertion -- deliberately NOT TestResponse::assertSee(), whose failure
     * message embeds the full response body. See class docblock.
     */
    private function expectContains(TestResponse $response, string $needle, string $context): TestResponse
    {
        if (! str_contains($response->getContent(), $needle)) {
            throw new \RuntimeException("{$context} -- expected response body to contain '{$needle}', it did not (HTTP {$response->getStatusCode()}).");
        }

        return $response;
    }

    /**
     * Compact negative content assertion -- deliberately NOT TestResponse::assertDontSee().
     */
    private function expectNotContains(TestResponse $response, string $needle, string $context): TestResponse
    {
        if (str_contains($response->getContent(), $needle)) {
            throw new \RuntimeException("{$context} -- expected response body to NOT contain '{$needle}', but it did.");
        }

        return $response;
    }

    // -------------------------------------------------------------------------------------
    // 1) Every parameterized/listing route, with a REAL param, checked for status AND content
    // -------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $violations
     */
    private function walkContentAndExistence(array &$violations): void
    {
        $this->check('home (tenant A)', function () {
            $this->expectStatus($this->get($this->hostA.'/'), 200, 'home');
        }, $violations);

        $this->check('services.index (tenant A)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/uslugi'), 200, 'services.index');
            $this->expectContains($r, $this->serviceA->name, 'services.index');
        }, $violations);

        $this->check('service.show (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/uslugi/'.$this->serviceA->slug), 200, 'service.show');
            $this->expectContains($r, $this->serviceA->name, 'service.show');
            $this->expectContains($r, 'MARKER-SERVICE-TENANT-A', 'service.show');
        }, $violations);

        $this->check('rental.index (tenant A)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/wypozyczalnia'), 200, 'rental.index');
            $this->expectContains($r, $this->categoryA->name, 'rental.index');
        }, $violations);

        $this->check('rental.category (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/wypozyczalnia/'.$this->categoryA->slug), 200, 'rental.category');
            $this->expectContains($r, $this->categoryA->name, 'rental.category');
            $this->expectContains($r, 'MARKER-CATEGORY-TENANT-A', 'rental.category');
        }, $violations);

        $this->check('page.show catch-all (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/'.$this->pageA->slug), 200, 'page.show');
            $this->expectContains($r, $this->pageA->title, 'page.show');
            $this->expectContains($r, 'MARKER-PAGE-TENANT-A', 'page.show');
        }, $violations);

        $this->check('page.legacy /strona/{slug} redirects to page.show (tenant A, real slug)', function () {
            $this->get($this->hostA.'/strona/'.$this->pageA->slug)
                ->assertRedirect($this->hostA.'/'.$this->pageA->slug);
        }, $violations);

        $this->check('post.show (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/aktualnosci/'.$this->postA->slug), 200, 'post.show');
            $this->expectContains($r, $this->postA->title, 'post.show');
            $this->expectContains($r, 'MARKER-POST-TENANT-A', 'post.show');
        }, $violations);

        $this->check('post.category (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/aktualnosci/kategoria/'.$this->postCategoryA->slug), 200, 'post.category');
            $this->expectContains($r, $this->postCategoryA->name, 'post.category');
            $this->expectContains($r, 'MARKER-POST-TENANT-A', 'post.category');
        }, $violations);

        $this->check('promotion.show (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/promocje/'.$this->promotionA->slug), 200, 'promotion.show');
            $this->expectContains($r, $this->promotionA->title, 'promotion.show');
            $this->expectContains($r, 'MARKER-PROMOTION-TENANT-A', 'promotion.show');
        }, $violations);

        $this->check('portfolio.show (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/portfolio/'.$this->portfolioItemA->slug), 200, 'portfolio.show');
            $this->expectContains($r, $this->portfolioItemA->title, 'portfolio.show');
            $this->expectContains($r, 'MARKER-PORTFOLIO-TENANT-A', 'portfolio.show');
        }, $violations);

        $this->check('portfolio.category (tenant A, real slug)', function () {
            $r = $this->expectStatus($this->get($this->hostA.'/portfolio/kategoria/'.$this->portfolioCategoryA->slug), 200, 'portfolio.category');
            $this->expectContains($r, $this->portfolioCategoryA->name, 'portfolio.category');
            $this->expectContains($r, 'MARKER-PORTFOLIO-TENANT-A', 'portfolio.category');
        }, $violations);

        $this->check('login form (guest, tenant A)', function () {
            $this->expectStatus($this->get($this->hostA.'/login'), 200, 'login');
        }, $violations);

        $this->check('customer.register form (guest, tenant A)', function () {
            $this->expectStatus($this->get($this->hostA.'/customer/register'), 200, 'customer.register');
        }, $violations);
    }

    // -------------------------------------------------------------------------------------
    // 2) Cross-tenant isolation on DETAIL routes: A's slug from host B, and vice versa
    // -------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $violations
     */
    private function walkCrossTenantIsolation(array &$violations): void
    {
        // Same slug exists on BOTH tenants (identical seeded catalog / identical CMS titles) --
        // the interesting case per the brief: requesting it from the OTHER host must resolve
        // to that host's OWN row, never tenant A's.
        $this->check("service.show (tenant A slug, requested from host B) resolves to B's own row", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/uslugi/'.$this->serviceA->slug), 200, 'service.show cross-tenant');
            $this->expectContains($r, 'MARKER-SERVICE-TENANT-B', 'service.show cross-tenant');
            $this->expectNotContains($r, 'MARKER-SERVICE-TENANT-A', 'service.show cross-tenant');
        }, $violations);

        $this->check("rental.category (tenant A slug, requested from host B) resolves to B's own row", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/wypozyczalnia/'.$this->categoryA->slug), 200, 'rental.category cross-tenant');
            $this->expectContains($r, 'MARKER-CATEGORY-TENANT-B', 'rental.category cross-tenant');
            $this->expectNotContains($r, 'MARKER-CATEGORY-TENANT-A', 'rental.category cross-tenant');
        }, $violations);

        $this->check("page.show (tenant A slug, requested from host B) resolves to B's own row", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/'.$this->pageA->slug), 200, 'page.show cross-tenant');
            $this->expectContains($r, 'MARKER-PAGE-TENANT-B', 'page.show cross-tenant');
            $this->expectNotContains($r, 'MARKER-PAGE-TENANT-A', 'page.show cross-tenant');
        }, $violations);

        $this->check("post.show (tenant A slug, requested from host B) resolves to B's own row", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/aktualnosci/'.$this->postA->slug), 200, 'post.show cross-tenant');
            $this->expectContains($r, 'MARKER-POST-TENANT-B', 'post.show cross-tenant');
            $this->expectNotContains($r, 'MARKER-POST-TENANT-A', 'post.show cross-tenant');
        }, $violations);

        $this->check("post.category (tenant A slug, requested from host B) resolves to B's own category", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/aktualnosci/kategoria/'.$this->postCategoryA->slug), 200, 'post.category cross-tenant');
            $this->expectContains($r, 'MARKER-POST-TENANT-B', 'post.category cross-tenant');
            $this->expectNotContains($r, 'MARKER-POST-TENANT-A', 'post.category cross-tenant');
        }, $violations);

        $this->check("promotion.show (tenant A slug, requested from host B) resolves to B's own row", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/promocje/'.$this->promotionA->slug), 200, 'promotion.show cross-tenant');
            $this->expectContains($r, 'MARKER-PROMOTION-TENANT-B', 'promotion.show cross-tenant');
            $this->expectNotContains($r, 'MARKER-PROMOTION-TENANT-A', 'promotion.show cross-tenant');
        }, $violations);

        $this->check("portfolio.show (tenant A slug, requested from host B) resolves to B's own row", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/portfolio/'.$this->portfolioItemA->slug), 200, 'portfolio.show cross-tenant');
            $this->expectContains($r, 'MARKER-PORTFOLIO-TENANT-B', 'portfolio.show cross-tenant');
            $this->expectNotContains($r, 'MARKER-PORTFOLIO-TENANT-A', 'portfolio.show cross-tenant');
        }, $violations);

        $this->check("portfolio.category (tenant A slug, requested from host B) resolves to B's own category", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/portfolio/kategoria/'.$this->portfolioCategoryA->slug), 200, 'portfolio.category cross-tenant');
            $this->expectContains($r, 'MARKER-PORTFOLIO-TENANT-B', 'portfolio.category cross-tenant');
            $this->expectNotContains($r, 'MARKER-PORTFOLIO-TENANT-A', 'portfolio.category cross-tenant');
        }, $violations);

        // No tenant B counterpart at all -- must 404, not fall through to anything.
        $this->check('page.show (tenant-A-only slug, requested from host B) 404s', function () {
            $this->expectStatus($this->get($this->hostB.'/'.$this->pageOnlyOnA->slug), 404, 'page.show tenant-A-only slug from host B');
        }, $violations);

        // Reverse direction, spot-checked once (the mechanism -- BelongsToOrganization's
        // per-request global scope -- is symmetric; a second full pass would be redundant with
        // the eight checks above, but zero reverse coverage would leave the direction untested).
        $this->check("service.show (tenant B slug, requested from host A) resolves to A's own row", function () {
            $r = $this->expectStatus($this->get($this->hostA.'/uslugi/'.$this->serviceB->slug), 200, 'service.show reverse cross-tenant');
            $this->expectContains($r, 'MARKER-SERVICE-TENANT-A', 'service.show reverse cross-tenant');
            $this->expectNotContains($r, 'MARKER-SERVICE-TENANT-B', 'service.show reverse cross-tenant');
        }, $violations);
    }

    // -------------------------------------------------------------------------------------
    // 3) Listing routes must not leak tenant A's rows onto tenant B's listing (or vice versa)
    // -------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $violations
     */
    private function walkListingIsolation(array &$violations): void
    {
        // Names/counts are IDENTICAL between tenants by construction (same seeded catalog) --
        // a scope leak would double these counts (14/26), not just add an unfamiliar name, so
        // an exact-count assertion is the correct falsifiable signal here, not a content
        // assertion against content that legitimately looks the same on both tenants.
        $this->check('services.index (host B) sees exactly its own 13 seeded services, not 26', function () {
            $response = $this->expectStatus($this->get($this->hostB.'/uslugi'), 200, 'services.index count');
            $total = $response->viewData('services')->total();
            if ($total !== 13) {
                throw new \RuntimeException("services.index count -- expected 13 services scoped to tenant B, got {$total}.");
            }
        }, $violations);

        $this->check('rental.index (host B) sees exactly its own 7 seeded categories, not 14', function () {
            $response = $this->expectStatus($this->get($this->hostB.'/wypozyczalnia'), 200, 'rental.index count');
            $count = $response->viewData('categories')->count();
            if ($count !== 7) {
                throw new \RuntimeException("rental.index count -- expected 7 categories scoped to tenant B, got {$count}.");
            }
        }, $violations);

        // Marker-based leak checks where the two tenants' rows ARE distinguishable by content.
        $this->check("services.index (host B) does not show tenant A's excerpt marker", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/uslugi'), 200, 'services.index leak');
            $this->expectNotContains($r, 'MARKER-SERVICE-TENANT-A', 'services.index leak');
        }, $violations);

        $this->check("post.category listing (host B) shows only its own 1 post, not tenant A's", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/aktualnosci/kategoria/'.$this->postCategoryB->slug), 200, 'post.category listing leak');
            $this->expectContains($r, 'MARKER-POST-TENANT-B', 'post.category listing leak');
            $this->expectNotContains($r, 'MARKER-POST-TENANT-A', 'post.category listing leak');
        }, $violations);

        $this->check("portfolio.category listing (host B) shows only its own 1 item, not tenant A's", function () {
            $r = $this->expectStatus($this->get($this->hostB.'/portfolio/kategoria/'.$this->portfolioCategoryB->slug), 200, 'portfolio.category listing leak');
            $this->expectContains($r, 'MARKER-PORTFOLIO-TENANT-B', 'portfolio.category listing leak');
            $this->expectNotContains($r, 'MARKER-PORTFOLIO-TENANT-A', 'portfolio.category listing leak');
        }, $violations);
    }

    // -------------------------------------------------------------------------------------
    // 4) Guest vs authenticated -- guest-vs-authenticated.md: "nie ma zakupów jako gość"
    // -------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $violations
     */
    private function walkGuestVsAuthenticated(array &$violations): void
    {
        $protectedGetRoutes = [
            'cart.show' => '/koszyk',
            'checkout.show' => '/koszyk/zamowienie',
            'checkout.return' => '/koszyk/powrot',
            'orders.index' => '/moje-zamowienia',
            'profile.index' => '/moje-konto',
            'profile.personal' => '/moje-konto/dane-osobowe',
            'profile.address' => '/moje-konto/adres',
            'profile.notifications' => '/moje-konto/powiadomienia',
            'profile.security' => '/moje-konto/bezpieczenstwo',
        ];

        foreach ($protectedGetRoutes as $name => $path) {
            $this->check("{$name}: guest is redirected to login, not 200 or 500", function () use ($path, $name) {
                $response = $this->get($this->hostA.$path);
                $status = $response->getStatusCode();
                if ($status < 300 || $status >= 400) {
                    throw new \RuntimeException("{$name} -- expected a redirect for a guest, got HTTP {$status}.");
                }
                $location = (string) $response->headers->get('Location');
                if (! str_contains($location, 'login')) {
                    throw new \RuntimeException("{$name} -- guest redirect target was '{$location}', expected it to point at login.");
                }
            }, $violations);
        }

        // guest-vs-authenticated.md's matrix row: "Dodanie do koszyka | Przekierowanie do /login".
        $this->check('cart.add: guest POST is redirected to login, cart stays empty', function () {
            $response = $this->post($this->hostA.'/koszyk/dodaj', [
                'service_id' => $this->serviceA->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'quantity' => 1,
            ]);
            $location = (string) $response->headers->get('Location');
            if (! str_contains($location, 'login')) {
                throw new \RuntimeException("cart.add guest -- redirect target was '{$location}', expected it to point at login.");
            }
            $this->assertDatabaseMissing('cart_items', ['service_id' => $this->serviceA->id]);
        }, $violations);

        // Authenticated: the SAME routes must render real content, not merely 200.
        $this->check('cart.add (authenticated): adds a real item, visible on cart.show', function () {
            $this->actingAs($this->customerA)->post($this->hostA.'/koszyk/dodaj', [
                'service_id' => $this->serviceA->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'quantity' => 1,
            ]);
            $this->assertDatabaseHas('cart_items', ['service_id' => $this->serviceA->id]);

            $r = $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/koszyk'), 200, 'cart.show');
            $this->expectContains($r, $this->serviceA->name, 'cart.show');
        }, $violations);

        $this->check('checkout.show (authenticated, non-empty cart): renders the cart item', function () {
            $r = $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/koszyk/zamowienie'), 200, 'checkout.show');
            $this->expectContains($r, $this->serviceA->name, 'checkout.show');
        }, $violations);

        $this->check('checkout.return (authenticated, no order/session query param): still 200', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/koszyk/powrot'), 200, 'checkout.return');
        }, $violations);

        $this->check("orders.index (authenticated): shows the customer's own order", function () {
            $r = $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/moje-zamowienia'), 200, 'orders.index');
            $this->expectContains($r, $this->orderA->order_number, 'orders.index');
        }, $violations);

        // Cross-tenant, via the customer's OWN order: tenant A's order must not appear on
        // tenant B's order list even though the SAME user (browser session boundaries aside --
        // actingAs() bypasses the cookie/session layer entirely, see report) is "logged in".
        $this->check("orders.index (host B, same authenticated customer): does not show tenant A's order", function () {
            $r = $this->expectStatus($this->actingAs($this->customerA)->get($this->hostB.'/moje-zamowienia'), 200, 'orders.index cross-tenant');
            $this->expectNotContains($r, $this->orderA->order_number, 'orders.index cross-tenant');
        }, $violations);

        $this->check("profile.personal (authenticated): renders the customer's own name", function () {
            $r = $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/moje-konto/dane-osobowe'), 200, 'profile.personal');
            $this->expectContains($r, 'Klara', 'profile.personal');
        }, $violations);

        $this->check('profile.address (authenticated): 200', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/moje-konto/adres'), 200, 'profile.address');
        }, $violations);

        $this->check('profile.notifications (authenticated): 200', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/moje-konto/powiadomienia'), 200, 'profile.notifications');
        }, $violations);

        $this->check('profile.security (authenticated): 200', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/moje-konto/bezpieczenstwo'), 200, 'profile.security');
        }, $violations);

        // profile.vehicle is DELIBERATELY not walked as a 200 -- see class docblock: 404 here is
        // correct for an EquipmentRental tenant (`vehicles` feature off by default), not a gap.
        $this->check('profile.vehicle (authenticated, EquipmentRental tenant): still guarded, 404 not 500', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get($this->hostA.'/moje-konto/pojazd'), 404, 'profile.vehicle');
        }, $violations);

        // login/customer.register carry `guest` middleware -- authenticated must NOT see the form.
        $this->check('login (already authenticated): guest middleware redirects away, no 200', function () {
            $status = $this->actingAs($this->customerA)->get($this->hostA.'/login')->getStatusCode();
            if ($status < 300 || $status >= 400) {
                throw new \RuntimeException("login (authenticated) -- expected a redirect, got HTTP {$status}.");
            }
        }, $violations);
    }

    // -------------------------------------------------------------------------------------
    // 5) Root domain (no subdomain, no tenant) -- tenant-scoped routes fail closed, not 500
    // -------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $violations
     */
    private function walkRootDomain(array &$violations): void
    {
        $this->check('home on root domain: 200, fallback view (no tenant to render)', function () {
            $this->expectStatus($this->get(self::ROOT.'/'), 200, 'home root domain');
        }, $violations);

        $this->check('services.index on root domain: 404, not 500 or an unscoped cross-tenant list', function () {
            $this->expectStatus($this->get(self::ROOT.'/uslugi'), 404, 'services.index root domain');
        }, $violations);

        $this->check('rental.index on root domain: 404', function () {
            $this->expectStatus($this->get(self::ROOT.'/wypozyczalnia'), 404, 'rental.index root domain');
        }, $violations);

        $this->check('page.show catch-all on root domain: 404', function () {
            $this->expectStatus($this->get(self::ROOT.'/'.$this->pageA->slug), 404, 'page.show root domain');
        }, $violations);

        $this->check('cart.show on root domain (authenticated): 404, not a cross-tenant cart', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get(self::ROOT.'/koszyk'), 404, 'cart.show root domain');
        }, $violations);

        $this->check('orders.index on root domain (authenticated): 404, not an unscoped order list', function () {
            $this->expectStatus($this->actingAs($this->customerA)->get(self::ROOT.'/moje-zamowienia'), 404, 'orders.index root domain');
        }, $violations);
    }
}
