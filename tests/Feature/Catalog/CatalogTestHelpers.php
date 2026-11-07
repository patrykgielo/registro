<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Location;
use App\Models\Organization;
use App\Support\Catalog\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Shared fixtures for the catalog:import test suite. Everything runs on SQLite, with
 * Storage::fake('public'), Http::fake() and a fake DNS resolver — no network, no real disk.
 */
trait CatalogTestHelpers
{
    protected FakeHostResolver $resolver;

    protected function setUpCatalog(): void
    {
        Storage::fake('public');
        $this->resolver = new FakeHostResolver;
        $this->app->instance(HostResolver::class, $this->resolver);
        Http::preventStrayRequests();
    }

    /** Binds a ResolveTenant double (same pattern as SeedVerticalDataMaterializesLocationStockTest). */
    protected function actingAsTenant(Organization $org): static
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

    protected function png(int $w = 64, int $h = 64, int $shade = 120): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $shade, 80, 40));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    protected function jpeg(int $w = 64, int $h = 64): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    /** Every https://img.example.com/<anything> answers with a distinct valid PNG. */
    protected function fakeImageHost(): void
    {
        $n = 0;
        Http::fake([
            'img.example.com/*' => function () use (&$n) {
                return Http::response($this->png(64, 64, 20 + (++$n % 200)), 200, ['Content-Type' => 'image/png']);
            },
        ]);
    }

    protected function rentalTenant(array $locationSlugs = ['siedziba']): Organization
    {
        $org = Organization::factory()->equipmentRental()->create();

        foreach ($locationSlugs as $slug) {
            Location::factory()->for($org, 'organization')->create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true]);
        }

        return $org;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function product(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_replace([
            'external_id' => "ext-{$n}",
            'name' => "Młot udarowy {$n}",
            'brand' => 'Bosch',
            'category' => ['name' => 'Elektronarzędzia', 'icon' => 'wrench'],
            'excerpt' => 'Lekki młot SDS-plus.',
            'body_html' => '<p>Opis produktu.</p>',
            'price_per_day' => 120,
            'price_per_week' => 600,
            'price_per_day_long' => 90,
            'price_threshold_days' => 3,
            'deposit_amount' => 500,
            'stock' => ['quantity' => 3],
            'specs' => [['label' => 'Moc', 'value' => 800, 'unit' => 'W']],
            'features' => ['Walizka w komplecie'],
            'seo' => ['meta_title' => 'Wynajem młota', 'meta_description' => 'Wypożycz młot udarowy.'],
            'featured_image' => ['url' => "https://img.example.com/p/{$n}-main.png", 'alt' => 'Młot'],
            'gallery' => [['url' => "https://img.example.com/p/{$n}-2.png", 'alt' => 'Walizka', 'caption' => 'Zestaw', 'credit' => 'Jan Kowalski', 'license' => 'CC BY-SA 4.0', 'source_page' => 'https://commons.example.org/File:x']],
        ], $overrides);
    }

    /**
     * Writes a catalog file into a fresh temp dir and returns its path.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function catalogFile(array $products, ?string $dir = null): string
    {
        $dir ??= sys_get_temp_dir().'/catalog-test-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0777, true);
        $path = $dir.'/catalog.json';
        file_put_contents($path, json_encode(['schema_version' => 1, 'products' => $products], JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
