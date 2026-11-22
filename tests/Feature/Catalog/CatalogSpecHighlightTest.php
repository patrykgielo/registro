<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\Catalog\CatalogProductValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stage C: a spec row may carry `highlight: true` ("show on the product tile"), at most 4 per product. The
 * importer takes the same rows the panel form writes: the key only when true, and the same cap.
 */
class CatalogSpecHighlightTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->fakeImageHost();
        $this->org = $this->rentalTenant();
    }

    /** @return array<int, string> "<index>:<field>" of every validation error */
    private function errorFields(array $product): array
    {
        $report = (new CatalogProductValidator)->validate([$product], $this->org);

        return array_map(fn (array $e) => ($e['index'] ?? '-').':'.$e['field'], $report->errors());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(int $count, int $starred): array
    {
        $rows = [];
        foreach (range(1, $count) as $n) {
            $rows[] = ['label' => "P{$n}", 'value' => (string) $n, 'unit' => null] + ($n <= $starred ? ['highlight' => true] : []);
        }

        return $rows;
    }

    public function test_the_validator_accepts_highlight_true_false_and_null(): void
    {
        foreach ([true, false, null] as $highlight) {
            $this->assertSame(
                [],
                $this->errorFields($this->product(['specs' => [['label' => 'Moc', 'value' => 800, 'unit' => 'W', 'highlight' => $highlight]]])),
                'highlight: '.json_encode($highlight),
            );
        }
    }

    public function test_the_validator_accepts_exactly_four_starred_rows_and_rejects_five(): void
    {
        $this->assertSame([], $this->errorFields($this->product(['specs' => $this->rows(6, 4)])));

        $errors = $this->errorFields($this->product(['specs' => $this->rows(6, 5)]));
        $this->assertContains('0:specs', $errors, 'got: '.json_encode($errors));

        $report = (new CatalogProductValidator)->validate([$this->product(['specs' => $this->rows(6, 5)])], $this->org);
        $this->assertStringContainsString('highlight: true', json_encode($report->errors(), JSON_UNESCAPED_UNICODE));
    }

    public function test_the_validator_rejects_a_highlight_that_is_not_a_json_boolean(): void
    {
        foreach (['yes', 'true', 1, 0, ['x']] as $bad) {
            $errors = $this->errorFields($this->product(['specs' => [['label' => 'Moc', 'value' => 800, 'unit' => 'W', 'highlight' => $bad]]]));
            $this->assertContains('0:specs.0.highlight', $errors, 'highlight: '.json_encode($bad).' got: '.json_encode($errors));
        }
    }

    public function test_an_unknown_key_in_a_spec_row_is_still_an_error(): void
    {
        $errors = $this->errorFields($this->product(['specs' => [['label' => 'Moc', 'value' => 800, 'unit' => 'W', 'star' => true]]]));

        $this->assertContains('0:specs.0.star', $errors);
    }

    public function test_the_import_keeps_highlight_true_and_writes_no_key_for_the_other_rows(): void
    {
        $file = $this->catalogFile([$this->product(['specs' => [
            ['label' => 'Moc', 'value' => 800, 'unit' => 'W', 'highlight' => true],
            ['label' => 'Waga', 'value' => '2,4', 'unit' => 'kg', 'highlight' => false],
            ['label' => 'Klasa', 'value' => 'A', 'unit' => null, 'highlight' => null],
            ['label' => 'Napięcie', 'value' => 230, 'unit' => 'V'],
        ]])]);

        $this->artisan('catalog:import', ['organization' => (string) $this->org->id, 'file' => $file, '--image-delay' => '0'])->assertExitCode(0);

        $service = Service::withoutGlobalScope('organization')->where('organization_id', $this->org->id)->firstOrFail();
        $specs = $service->metadata['specs'];

        $this->assertCount(4, $specs);
        $this->assertTrue($specs[0]['highlight']);
        foreach ([1, 2, 3] as $i) {
            $this->assertArrayNotHasKey('highlight', $specs[$i], "row {$i}: no key, not false, not null");
        }
        $this->assertStringNotContainsString('"highlight":false', (string) $service->getRawOriginal('metadata'));
        $this->assertStringNotContainsString('"highlight":null', (string) $service->getRawOriginal('metadata'));
        $this->assertSame(['Moc: 800 W'], array_slice($service->tilePoints(), 0, 1), 'and the tile shows it');
    }

    public function test_the_import_refuses_a_file_with_five_starred_rows_and_writes_nothing(): void
    {
        $file = $this->catalogFile([$this->product(['specs' => $this->rows(6, 5)])]);

        $this->artisan('catalog:import', ['organization' => (string) $this->org->id, 'file' => $file, '--image-delay' => '0'])->assertFailed();

        $this->assertSame(0, Service::withoutGlobalScope('organization')->where('organization_id', $this->org->id)->count());
    }

    public function test_the_model_drops_a_false_highlight_whatever_wrote_the_row(): void
    {
        // The seeders / console / API reach the model directly, not through the importer or the form.
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'metadata' => ['specs' => [
                ['label' => 'Moc', 'value' => '800', 'unit' => 'W', 'highlight' => true],
                ['label' => 'Waga', 'value' => '2', 'unit' => 'kg', 'highlight' => false],
            ]],
        ]);

        $specs = $service->fresh()->metadata['specs'];
        $this->assertTrue($specs[0]['highlight']);
        $this->assertArrayNotHasKey('highlight', $specs[1]);
    }

    /**
     * Form <-> importer parity (CatalogImportFormParityTest's contract): the panel form refuses a fifth star
     * and so does the importer.
     */
    public function test_the_panel_form_and_the_importer_agree_on_the_cap(): void
    {
        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        session(['tenant_id' => $this->org->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($this->org->id, ['role' => 'admin']);
        $this->actingAs($admin);

        foreach ([4 => true, 5 => false] as $starred => $accepted) {
            $product = $this->product(['specs' => $this->rows(6, $starred)]);

            $form = Livewire::test(CreateService::class)->fillForm([
                'service_type' => 'item_rental',
                'name' => "Wiertarka {$starred}",
                'slug' => "wiertarka-{$starred}",
                'price' => 100,
                'price_per_day' => 100,
                'quantity_total' => 1,
                'is_active' => true,
                'sort_order' => 0,
                'metadata' => ['specs' => $product['specs']],
            ])->call('create');

            $this->assertSame($accepted, $this->errorFields($product) === [], "importer, {$starred} stars");
            $this->assertSame($accepted, ! collect($form->errors()->keys())->contains(fn (string $k): bool => str_starts_with($k, 'data.metadata.specs')), "form, {$starred} stars");
        }
    }
}
