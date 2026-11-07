<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Organization;
use App\Models\ServiceUnit;
use App\Support\Catalog\CatalogDocument;
use App\Support\Catalog\CatalogProductValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Importer-specific rules (the rules shared with the Filament form are in CatalogImportFormParityTest). */
class CatalogProductValidatorTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalog();
        $this->org = $this->rentalTenant(['siedziba']);
    }

    /** @return array<int, string> "<field>" of every error */
    private function errorFields(array $products, ?Organization $org = null, array $skip = []): array
    {
        $report = (new CatalogProductValidator)->validate($products, $org ?? $this->org, $skip);

        return array_map(fn (array $e) => ($e['index'] ?? '-').':'.$e['field'], $report->errors());
    }

    public function test_a_baseline_product_has_no_errors(): void
    {
        $this->assertSame([], $this->errorFields([$this->product()]));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> override => expected "index:field" */
    public static function invalid(): array
    {
        return [
            'unknown product key' => [['nazwa' => 'x'], '0:nazwa'],
            'unknown nested key (stock)' => [['stock' => ['quantity' => 1, 'foo' => 1]], '0:stock.foo'],
            'unknown nested key (image)' => [['featured_image' => ['url' => 'https://img.example.com/a.png', 'foo' => 'x']], '0:featured_image.foo'],
            'missing external_id' => [['external_id' => null], '0:external_id'],
            'missing name' => [['name' => null, 'slug' => null], '0:name'],
            'bad slug characters' => [['slug' => 'Zły Slug'], '0:slug'],
            'category missing' => [['category' => null], '0:category'],
            'category icon not a heroicon' => [['category' => ['name' => 'X', 'icon' => 'nie-ma-takiej']], '0:category.icon'],
            'icon not a heroicon' => [['icon' => 'nie-ma-takiej'], '0:icon'],
            'negative price' => [['price_per_day' => -1], '0:price_per_day'],
            'price over the decimal(10,2) range' => [['price_per_day' => 100000000], '0:price_per_day'],
            'three decimals' => [['price_per_day' => 9.999], '0:price_per_day'],
            'negative deposit' => [['deposit_amount' => -1], '0:deposit_amount'],
            'stock and units together' => [['units' => [['identifier' => 'A']]], '0:stock'],
            'neither stock nor units' => [['stock' => null], '0:stock'],
            'stock quantity and locations together' => [['stock' => ['quantity' => 1, 'locations' => ['siedziba' => 1]]], '0:stock'],
            'stock location unknown' => [['stock' => ['locations' => ['gdzies' => 2]]], '0:stock.locations.gdzies'],
            'stock locations sum zero' => [['stock' => ['locations' => ['siedziba' => 0]]], '0:stock.locations'],
            'stock quantity not an int' => [['stock' => ['quantity' => '3']], '0:stock.quantity'],
            'unit status invalid' => [['stock' => null, 'units' => [['identifier' => 'A', 'status' => 'borrowed']]], '0:units.0.status'],
            'unit location unknown' => [['stock' => null, 'units' => [['identifier' => 'A', 'location' => 'gdzies']]], '0:units.0.location'],
            'duplicate unit identifier in a product' => [['stock' => null, 'units' => [['identifier' => 'A'], ['identifier' => 'A']]], '0:units.1.identifier'],
            'image with neither url nor path' => [['featured_image' => ['alt' => 'x']], '0:featured_image'],
            'image with both url and path' => [['featured_image' => ['url' => 'https://img.example.com/a.png', 'path' => 'a.png']], '0:featured_image'],
            'image url not http(s)' => [['featured_image' => ['url' => 'ftp://img.example.com/a.png']], '0:featured_image.url'],
            'image source_page not a url' => [['featured_image' => ['url' => 'https://img.example.com/a.png', 'source_page' => 'nie url']], '0:featured_image.source_page'],
            'too many gallery images' => [['gallery' => array_fill(0, 11, ['url' => 'https://img.example.com/a.png'])], '0:gallery'],
            'spec value not scalar' => [['specs' => [['label' => 'Moc', 'value' => ['x'], 'unit' => 'W']]], '0:specs.0.value'],
            'spec value over 100 chars' => [['specs' => [['label' => 'Moc', 'value' => str_repeat('a', 101), 'unit' => 'W']]], '0:specs.0.value'],
            'published_at not a date' => [['published_at' => 'wczoraj-ish'], '0:published_at'],
            // round 2: shapes that used to reach prefetch/unit code as raw TypeErrors
            'gallery entry that is a string' => [['gallery' => ['abc']], '0:gallery.0'],
            'gallery entry that is null' => [['gallery' => [null]], '0:gallery.0'],
            'gallery entry that is an int' => [['gallery' => [5]], '0:gallery.0'],
            'unit location that is an array' => [['stock' => null, 'units' => [['identifier' => 'A', 'location' => ['x']]]], '0:units.0.location'],
            'unit location that is an object' => [['stock' => null, 'units' => [['identifier' => 'A', 'location' => ['slug' => 'siedziba']]]], '0:units.0.location'],
            'sort_order beyond a signed INT' => [['sort_order' => 2147483648], '0:sort_order'],
            'category sort_order beyond a signed INT' => [['category' => ['name' => 'X', 'sort_order' => 2147483648]], '0:category.sort_order'],
            'more units than the cap' => [['stock' => null, 'units' => array_map(fn ($i) => ['identifier' => "BULK-{$i}"], range(1, 501))], '0:units'],
            'more specs than the cap' => [['specs' => array_map(fn ($i) => ['label' => "P{$i}", 'value' => '1', 'unit' => null], range(1, 31))], '0:specs'],
            // TEXT columns hold 65535 BYTES: 40000 x "ż" is 40000 characters but 80000 bytes
            'description over 65535 bytes' => [['description' => str_repeat('ż', 40000)], '0:description'],
            'category description over 65535 bytes' => [['category' => ['name' => 'X', 'description' => str_repeat('ż', 40000)]], '0:category.description'],
            'body_html over 65535 bytes after purification' => [['body_html' => '<p>'.str_repeat('ż', 40000).'</p>'], '0:body_html'],
            'unit notes over 65535 bytes' => [['stock' => null, 'units' => [['identifier' => 'N1', 'notes' => str_repeat('ż', 40000)]]], '0:units.0.notes'],
            // string keys would collapse into one slot downstream
            'gallery as an object' => [['gallery' => ['a' => ['url' => 'https://img.example.com/a.png'], 'b' => ['url' => 'https://img.example.com/b.png']]], '0:gallery'],
            'features as an object' => [['features' => ['x' => 'Jedna', 'y' => 'Dwie']], '0:features'],
            'specs as an object' => [['specs' => ['moc' => ['label' => 'Moc', 'value' => '1', 'unit' => 'W']]], '0:specs'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalid')]
    public function test_importer_specific_rejections(array $overrides, string $expected): void
    {
        $fields = $this->errorFields([$this->product($overrides)]);

        $this->assertContains($expected, $fields, 'got: '.json_encode($fields));
    }

    public function test_strings_are_trimmed_and_empty_strings_become_null_like_the_panel_form_does(): void
    {
        $p = CatalogProductValidator::normalize($this->product([
            'name' => "  Młot udarowy \n",
            'slug' => "  mlot-udarowy\n",
            'excerpt' => '   ',
            'category' => ['name' => ' Narzędzia ', 'slug' => "narzedzia\n"],
            'specs' => [['label' => ' Moc ', 'value' => ' 800 ', 'unit' => '  ']],
        ]));

        $this->assertSame('Młot udarowy', $p['name']);
        $this->assertSame('mlot-udarowy', $p['slug'], 'a trailing newline must never reach the slug column');
        $this->assertNull($p['excerpt']);
        $this->assertSame('Narzędzia', $p['category']['name']);
        $this->assertSame('narzedzia', $p['category']['slug']);
        $this->assertSame([['label' => 'Moc', 'value' => '800', 'unit' => null]], $p['specs']);

        // …and a blank excerpt / trailing newline are not validation errors
        $this->assertSame([], $this->errorFields([$this->product(['excerpt' => '   ', 'slug' => "mlot-x\n"])]));
        // a whitespace-only required field is missing, not "present"
        $this->assertContains('0:name', $this->errorFields([$this->product(['name' => '   ', 'slug' => null])]));
    }

    public function test_collisions_are_case_and_whitespace_insensitive_like_the_ci_collation(): void
    {
        $a = $this->product(['external_id' => 'ABC', 'name' => 'Młot', 'slug' => 'mlot', 'stock' => null, 'units' => [['identifier' => 'AbC']]]);
        $b = $this->product(['external_id' => ' abc ', 'name' => '  mŁOT ', 'slug' => 'mlot', 'stock' => null, 'units' => [['identifier' => ' abc ']]]);

        $fields = $this->errorFields([$a, $b]);

        $this->assertContains('1:external_id', $fields);
        $this->assertContains('1:name', $fields);
        $this->assertContains('1:units.0.identifier', $fields);
    }

    public function test_existing_unit_identifiers_collide_regardless_of_case(): void
    {
        $service = \App\Models\Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $location = \App\Models\Location::withoutGlobalScope('organization')->where('organization_id', $this->org->id)->firstOrFail();
        ServiceUnit::withoutGlobalScope('organization')->create(['organization_id' => $this->org->id, 'service_id' => $service->id, 'location_id' => $location->id, 'identifier' => 'Taken-1']);

        $this->assertContains('0:units.0.identifier', $this->errorFields([$this->product(['stock' => null, 'units' => [['identifier' => ' taken-1']]])]));
    }

    public function test_text_just_under_the_byte_cap_is_accepted(): void
    {
        // 32000 x "ż" = 64000 bytes (< 65535) although 32000 characters
        $ok = $this->product(['description' => str_repeat('ż', 32000), 'body_html' => '<p>'.str_repeat('ż', 30000).'</p>', 'featured_image' => null, 'gallery' => []]);

        $this->assertSame([], $this->errorFields([$ok]));
    }

    public function test_stock_quantity_needs_exactly_one_active_location(): void
    {
        $multi = $this->rentalTenant(['a', 'b']);

        $this->assertContains('0:stock.quantity', $this->errorFields([$this->product(['stock' => ['quantity' => 2]])], $multi));
        $this->assertSame([], $this->errorFields([$this->product(['stock' => ['locations' => ['a' => 1, 'b' => 1]]])], $multi));
        $this->assertContains('0:units.0.location', $this->errorFields([$this->product(['stock' => null, 'units' => [['identifier' => 'A']]])], $multi), 'unit location is required with 2+ locations');
    }

    public function test_an_inactive_location_cannot_hold_stock(): void
    {
        \App\Models\Location::withoutGlobalScope('organization')->where('organization_id', $this->org->id)->update(['is_active' => false]);
        \App\Models\Location::factory()->for($this->org, 'organization')->create(['slug' => 'druga', 'is_active' => true]);

        $this->assertContains('0:stock.locations.siedziba', $this->errorFields([$this->product(['stock' => ['locations' => ['siedziba' => 2]]])]));
    }

    public function test_duplicates_inside_the_file(): void
    {
        $a = $this->product(['external_id' => 'x', 'name' => 'Młot', 'slug' => 'mlot']);
        $b = $this->product(['external_id' => 'x', 'name' => 'MLOT', 'slug' => 'mlot']);

        $fields = $this->errorFields([$a, $b]);

        $this->assertContains('1:external_id', $fields);
        $this->assertContains('1:name', $fields, 'case/diacritics-insensitive, like the utf8mb4_unicode_ci unique index');
        $this->assertContains('1:slug', $fields);
    }

    public function test_duplicate_unit_identifier_across_products_and_against_the_database(): void
    {
        $a = $this->product(['stock' => null, 'units' => [['identifier' => 'DUP']]]);
        $b = $this->product(['stock' => null, 'units' => [['identifier' => 'DUP']]]);

        $this->assertContains('1:units.0.identifier', $this->errorFields([$a, $b]));

        $service = \App\Models\Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $location = \App\Models\Location::withoutGlobalScope('organization')->where('organization_id', $this->org->id)->firstOrFail();
        ServiceUnit::withoutGlobalScope('organization')->create(['organization_id' => $this->org->id, 'service_id' => $service->id, 'location_id' => $location->id, 'identifier' => 'TAKEN']);

        $this->assertContains('0:units.0.identifier', $this->errorFields([$this->product(['stock' => null, 'units' => [['identifier' => 'TAKEN']]])]));
    }

    public function test_the_same_unit_identifier_in_another_organization_is_fine(): void
    {
        $other = $this->rentalTenant(['x']);
        $service = \App\Models\Service::factory()->itemRental()->create(['organization_id' => $other->id]);
        $loc = \App\Models\Location::withoutGlobalScope('organization')->where('organization_id', $other->id)->firstOrFail();
        ServiceUnit::withoutGlobalScope('organization')->create(['organization_id' => $other->id, 'service_id' => $service->id, 'location_id' => $loc->id, 'identifier' => 'SHARED']);

        $this->assertSame([], $this->errorFields([$this->product(['stock' => null, 'units' => [['identifier' => 'SHARED']]])]));
    }

    public function test_skipped_indexes_are_schema_checked_but_exempt_from_collisions(): void
    {
        \App\Models\Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'name' => 'Młot', 'slug' => 'mlot']);
        $product = $this->product(['name' => 'Młot', 'slug' => 'mlot']);

        $this->assertNotEmpty($this->errorFields([$product]));
        $this->assertSame([], $this->errorFields([$product], null, [0]));
        $this->assertContains('0:price_per_day', $this->errorFields([$this->product(['price_per_day' => -1])], null, [0]));
    }

    public function test_a_category_slug_used_for_two_different_names_is_a_warning(): void
    {
        $report = (new CatalogProductValidator)->validate([
            $this->product(['category' => ['name' => 'Narzędzia', 'slug' => 'tools']]),
            $this->product(['category' => ['name' => 'Tools', 'slug' => 'tools']]),
        ], $this->org);

        $this->assertFalse($report->hasErrors());
        $this->assertNotEmpty($report->warnings());
    }

    public function test_non_object_product_is_reported_not_crashed_on(): void
    {
        $this->assertContains('0:(produkt)', $this->errorFields(['just a string']));
        $this->assertContains('0:(produkt)', $this->errorFields([[1, 2, 3]]));
    }

    // ------------------------------------------------------------------ CatalogDocument

    public function test_document_loader_rejects_bad_files(): void
    {
        $dir = sys_get_temp_dir().'/doc-'.bin2hex(random_bytes(3));
        mkdir($dir);

        $cases = [
            'not json' => '{oops',
            'a list at the root' => '[1,2]',
            'wrong schema_version' => '{"schema_version":2,"products":[{}]}',
            'no products' => '{"schema_version":1,"products":[]}',
            'products is an object' => '{"schema_version":1,"products":{"a":1}}',
            'unknown root key' => '{"schema_version":1,"products":[{}],"extra":1}',
        ];

        foreach ($cases as $label => $content) {
            file_put_contents($dir.'/f.json', $content);
            try {
                CatalogDocument::load($dir.'/f.json');
                $this->fail("{$label} was accepted");
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(RuntimeException::class);
        CatalogDocument::load($dir.'/missing.json');
    }

    public function test_document_loader_enforces_the_size_and_count_caps(): void
    {
        $dir = sys_get_temp_dir().'/doc-'.bin2hex(random_bytes(3));
        mkdir($dir);

        file_put_contents($dir.'/many.json', json_encode(['schema_version' => 1, 'products' => array_fill(0, CatalogDocument::MAX_PRODUCTS + 1, ['a' => 1])]));
        try {
            CatalogDocument::load($dir.'/many.json');
            $this->fail('too many products accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Za dużo', $e->getMessage());
        }

        file_put_contents($dir.'/big.json', str_repeat(' ', CatalogDocument::MAX_BYTES + 1));
        $this->expectException(RuntimeException::class);
        CatalogDocument::load($dir.'/big.json');
    }

    public function test_document_loader_returns_sha_and_products(): void
    {
        $file = $this->catalogFile([$this->product()]);

        $doc = CatalogDocument::load($file);

        $this->assertCount(1, $doc['products']);
        $this->assertSame(hash_file('sha256', $file), $doc['sha256']);
    }
}
