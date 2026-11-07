<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\Catalog\CatalogProductValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DRIFT GUARD. The importer re-states the Filament form's rules (ServiceResource.php) in
 * CatalogProductValidator. This suite feeds the SAME product to both — the real
 * CreateService Livewire page and the importer's validator — and requires the same verdict.
 * If someone tightens or loosens a rule on the form without the importer (or vice versa),
 * a case here turns red.
 *
 * Deliberately NOT compared: rules that exist only on one side by design (see the
 * validator's class docblock — negative prices, unknown keys, name uniqueness the form
 * leaves to the DB).
 */
class CatalogImportFormParityTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->org = $this->rentalTenant();
        session(['tenant_id' => $this->org->id]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->org->id, ['role' => 'admin']);
        $this->actingAs($admin);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function formData(array $p): array
    {
        $p = CatalogProductValidator::normalize($p);

        return array_filter([
            'service_type' => 'item_rental',
            'name' => $p['name'] ?? null,
            'slug' => $p['slug'] ?? null,
            'excerpt' => $p['excerpt'] ?? null,
            'brand' => $p['brand'] ?? null,
            'icon' => $p['icon'] ?? null,
            'price' => $p['price_per_day'] ?? null,
            'price_per_day' => $p['price_per_day'] ?? null,
            'price_per_hour' => $p['price_per_hour'] ?? null,
            'price_per_week' => $p['price_per_week'] ?? null,
            'price_per_day_long' => $p['price_per_day_long'] ?? null,
            'price_threshold_days' => $p['price_threshold_days'] ?? null,
            'deposit_amount' => $p['deposit_amount'] ?? null,
            'quantity_total' => $p['stock']['quantity'] ?? null,
            'meta_title' => $p['seo']['meta_title'] ?? null,
            'meta_description' => $p['seo']['meta_description'] ?? null,
            // simple() Repeater: the raw form state is [['feature' => 'x']]; it dehydrates to a list of strings
            'features' => isset($p['features']) ? array_map(fn ($f) => ['feature' => $f], $p['features']) : null,
            'metadata' => isset($p['specs']) ? ['specs' => $p['specs']] : null,
            'is_active' => true,
            'sort_order' => 0,
        ], fn ($v) => $v !== null);
    }

    /** @return array<int, string> */
    private function formErrors(array $product): array
    {
        $component = Livewire::test(CreateService::class)
            ->fillForm($this->formData($product))
            ->call('create');

        return $component->errors()->keys();
    }

    private function formAccepts(array $product): bool
    {
        return $this->formErrors($product) === [];
    }

    private function importerAccepts(array $product): bool
    {
        return ! (new CatalogProductValidator)->validate([$product], $this->org)->hasErrors();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function rejectedByBoth(): array
    {
        return [
            'name over 255' => [['name' => str_repeat('a', 256)], 'data.name'],
            'excerpt over 500' => [['excerpt' => str_repeat('a', 501)], 'data.excerpt'],
            'brand over 255' => [['brand' => str_repeat('a', 256)], 'data.brand'],
            'meta_title over 60' => [['seo' => ['meta_title' => str_repeat('a', 61)]], 'data.meta_title'],
            'meta_description over 160' => [['seo' => ['meta_description' => str_repeat('a', 161)]], 'data.meta_description'],
            'price_per_day missing' => [['price_per_day' => null], 'data.price_per_day'],
            'price_per_day not numeric' => [['price_per_day' => 'abc'], 'data.price_per_day'],
            'long rate above the daily rate' => [['price_per_day' => 100, 'price_per_day_long' => 120, 'price_threshold_days' => 3], 'data.price_per_day_long'],
            'long rate without a threshold' => [['price_per_day_long' => 80, 'price_threshold_days' => null], 'data.price_'],
            'threshold without a long rate' => [['price_per_day_long' => null, 'price_threshold_days' => 3], 'data.price_per_day_long'],
            'threshold of 0' => [['price_threshold_days' => 0], 'data.price_threshold_days'],
            'threshold over 365' => [['price_threshold_days' => 366], 'data.price_threshold_days'],
            'more than 4 features' => [['features' => ['a', 'b', 'c', 'd', 'e']], 'data.features'],
            'a feature over 100' => [['features' => [str_repeat('a', 101)]], 'data.features'],
            'spec label over 100' => [['specs' => [['label' => str_repeat('a', 101), 'value' => '1', 'unit' => null]]], 'data.metadata.specs'],
            'spec unit over 20' => [['specs' => [['label' => 'Moc', 'value' => '1', 'unit' => str_repeat('a', 21)]]], 'data.metadata.specs'],
            'quantity of 0' => [['stock' => ['quantity' => 0]], 'data.quantity_total'],
            'slug already taken in the organization' => [['name' => 'Inna nazwa', 'slug' => 'zajety'], 'data.slug'],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function acceptedByBoth(): array
    {
        return [
            'the baseline product' => [[]],
            'boundary lengths' => [['seo' => ['meta_title' => str_repeat('a', 60), 'meta_description' => str_repeat('a', 160)], 'excerpt' => str_repeat('a', 500)]],
            'exactly 4 features' => [['features' => ['a', 'b', 'c', 'd']]],
            'threshold at the bounds' => [['price_per_day_long' => 100, 'price_per_day' => 100, 'price_threshold_days' => 365]],
            'no optional prices' => [['price_per_week' => null, 'price_per_day_long' => null, 'price_threshold_days' => null, 'deposit_amount' => null]],
            'no specs and no features' => [['specs' => [], 'features' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function build(array $overrides): array
    {
        return $this->product(array_replace([
            'name' => 'Młot parytetowy',
            'slug' => 'mlot-parytetowy',
            'featured_image' => null,
            'gallery' => [],
        ], $overrides));
    }

    #[DataProvider('acceptedByBoth')]
    public function test_a_valid_product_is_accepted_by_the_form_and_the_importer(array $overrides): void
    {
        $product = $this->build($overrides);

        $this->assertTrue($this->importerAccepts($product), 'importer rejected: '.json_encode($this->importerErrors($product)));
        $this->assertTrue($this->formAccepts($product), 'the real CreateService form rejected a product the importer accepts: '.json_encode($this->formErrors($product)));
    }

    #[DataProvider('rejectedByBoth')]
    public function test_an_invalid_product_is_rejected_by_the_form_and_the_importer(array $overrides, string $formErrorKey): void
    {
        Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'name' => 'Zajęty', 'slug' => 'zajety']);
        $product = $this->build($overrides);

        $this->assertFalse($this->importerAccepts($product), 'importer accepted a product the form rejects');

        // Not merely "the form said no": it said no ABOUT THE FIELD under test — a baseline
        // the form rejects for an unrelated reason would make every case here vacuously green.
        $formErrors = $this->formErrors($product);
        $this->assertNotEmpty(
            array_filter($formErrors, fn (string $key) => str_starts_with($key, $formErrorKey)),
            "the form did not reject {$formErrorKey}; it said: ".json_encode($formErrors)
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function importerErrors(array $product): array
    {
        return (new CatalogProductValidator)->validate([$product], $this->org)->errors();
    }

    public function test_icon_options_match_the_admin_forms_icon_list(): void
    {
        $method = new \ReflectionMethod(BaseResource::class, 'getHeroiconOptions');
        $method->setAccessible(true);
        $formIcons = array_keys($method->invoke(null));

        $importerIcons = CatalogProductValidator::heroiconNames();

        // BaseResource::getHeroiconOptions() strips the "s-" file prefix with str_replace(),
        // which also eats every INNER "s-" ('arrows-pointing-in' becomes 'arrowpointing-in'
        // — a name that renders a broken icon). The importer uses the real names (the public
        // site renders heroicon-m-<name>), so the two lists differ EXACTLY by that mangling,
        // and nothing else.
        $this->assertContains('wrench', $formIcons);
        $this->assertContains('wrench', $importerIcons);
        $this->assertEqualsCanonicalizing(
            array_map(fn (string $real) => str_replace('s-', '', 's-'.$real), $importerIcons),
            $formIcons
        );
        $this->assertNotContains('arrowpointing-in', $importerIcons);
        $this->assertContains('arrows-pointing-in', $importerIcons);
    }

    public function test_the_intentional_deviations_are_exactly_these(): void
    {
        // negative prices: the form allows them, the importer must not
        $this->assertFalse($this->importerAccepts($this->build(['price_per_day' => -1])));
        $this->assertFalse($this->importerAccepts($this->build(['deposit_amount' => -1])));
        $this->assertFalse($this->importerAccepts($this->build(['price_per_day' => 100000000])));
        $this->assertFalse($this->importerAccepts($this->build(['price_per_day' => 10.123])));
        // a typo'd key is an error here, silently ignored by the form
        $this->assertFalse($this->importerAccepts($this->build(['price_per_dya' => 5])));
        // a duplicate NAME: no form rule (DB constraint only) — the importer reports it cleanly
        Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'name' => 'Zajęty', 'slug' => 'zajety']);
        $this->assertFalse($this->importerAccepts($this->build(['name' => 'zajety', 'slug' => 'inny-slug'])));
    }
}
