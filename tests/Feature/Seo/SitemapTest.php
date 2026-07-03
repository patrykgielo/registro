<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * Feature tests for GET /sitemap.xml.
 *
 * Also serves as a regression test for VULN-003 (see
 * app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md):
 * the sitemap route queries tenant-owned content models, so it MUST 404 on
 * the bare root domain via RequireTenant, exactly like every other content
 * route in routes/web.php.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Cache::flush();
    }

    private function createTenantWithContent(string $slugPrefix): Organization
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => ucfirst($slugPrefix).' Salon',
            'slug' => $slugPrefix,
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
        ]);

        Page::create([
            'organization_id' => $org->id,
            'title' => "{$slugPrefix} page",
            'slug' => "{$slugPrefix}-page",
            'published_at' => now()->subDay(),
        ]);

        $postCategory = Category::create([
            'organization_id' => $org->id,
            'name' => "{$slugPrefix} post category",
            'slug' => "{$slugPrefix}-post-category",
            'type' => 'post',
        ]);

        Post::create([
            'organization_id' => $org->id,
            'title' => "{$slugPrefix} post",
            'slug' => "{$slugPrefix}-post",
            'category_id' => $postCategory->id,
            'published_at' => now()->subDay(),
        ]);

        $portfolioCategory = Category::create([
            'organization_id' => $org->id,
            'name' => "{$slugPrefix} portfolio category",
            'slug' => "{$slugPrefix}-portfolio-category",
            'type' => 'portfolio',
        ]);

        PortfolioItem::create([
            'organization_id' => $org->id,
            'title' => "{$slugPrefix} portfolio item",
            'slug' => "{$slugPrefix}-portfolio-item",
            'category_id' => $portfolioCategory->id,
            'published_at' => now()->subDay(),
        ]);

        Service::factory()->create([
            'organization_id' => $org->id,
            'slug' => "{$slugPrefix}-service",
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return $org;
    }

    /**
     * Parses the sitemap XML and returns the `<loc>` values as strings,
     * asserting the document is well-formed along the way. Namespace
     * registration is required because `<urlset>` declares a default
     * xmlns — an unprefixed `//url/loc` xpath silently matches nothing.
     *
     * @return array<int, string>
     */
    private function extractLocs(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent);
        $this->assertNotFalse($xml, 'Sitemap response is not well-formed XML');
        $this->assertSame('urlset', $xml->getName());

        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return array_map(
            fn (SimpleXMLElement $loc) => (string) $loc,
            $xml->xpath('//s:url/s:loc')
        );
    }

    public function test_sitemap_returns_valid_xml_with_tenant_content_urls_on_subdomain(): void
    {
        $org = $this->createTenantWithContent('acme');

        $response = $this->get("http://{$org->slug}.registro.local/sitemap.xml");

        $response->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $locs = $this->extractLocs($response->getContent());

        $this->assertContains("http://{$org->slug}.registro.local/acme-page", $locs);
        $this->assertContains("http://{$org->slug}.registro.local/aktualnosci/acme-post", $locs);
        $this->assertContains("http://{$org->slug}.registro.local/aktualnosci/kategoria/acme-post-category", $locs);
        $this->assertContains("http://{$org->slug}.registro.local/portfolio/acme-portfolio-item", $locs);
        $this->assertContains("http://{$org->slug}.registro.local/portfolio/kategoria/acme-portfolio-category", $locs);
        $this->assertContains("http://{$org->slug}.registro.local/uslugi/acme-service", $locs);
    }

    public function test_sitemap_returns_404_on_root_domain(): void
    {
        $this->get('http://registro.local/sitemap.xml')
            ->assertNotFound();
    }

    public function test_sitemaps_do_not_leak_urls_between_tenants(): void
    {
        $orgA = $this->createTenantWithContent('tenanta');
        $orgB = $this->createTenantWithContent('tenantb');

        $locsA = $this->extractLocs(
            $this->get("http://{$orgA->slug}.registro.local/sitemap.xml")->getContent()
        );

        $locsB = $this->extractLocs(
            $this->get("http://{$orgB->slug}.registro.local/sitemap.xml")->getContent()
        );

        $this->assertNotEmpty($locsA);
        $this->assertNotEmpty($locsB);

        foreach ($locsA as $loc) {
            $this->assertStringNotContainsString('tenantb', $loc);
        }

        foreach ($locsB as $loc) {
            $this->assertStringNotContainsString('tenanta', $loc);
        }

        $this->assertEmpty(array_intersect($locsA, $locsB));
    }
}
