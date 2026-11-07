<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\AuditLog;
use App\Models\CatalogImportBatch;
use App\Models\CatalogImportItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CatalogImportCommandTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->fakeImageHost();
    }

    private function services(Organization $org)
    {
        return Service::withoutGlobalScope('organization')->where('organization_id', $org->id);
    }

    private function import(Organization $org, string $file, array $flags = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('catalog:import', ['organization' => (string) $org->id, 'file' => $file, '--image-delay' => '0'] + $flags);
    }

    // ------------------------------------------------------------------ happy path

    public function test_imports_products_categories_stock_images_and_ledger(): void
    {
        $org = $this->rentalTenant();
        $location = Location::withoutGlobalScope('organization')->where('organization_id', $org->id)->firstOrFail();
        $file = $this->catalogFile([
            $this->product(['external_id' => 'a', 'name' => 'Młot A']),
            $this->product(['external_id' => 'b', 'name' => 'Szlifierka B', 'category' => ['name' => 'Elektronarzędzia'], 'stock' => ['quantity' => 5]]),
            $this->product(['external_id' => 'c', 'name' => 'Zagęszczarka C', 'category' => 'Sprzęt budowlany', 'gallery' => [], 'featured_image' => null]),
        ]);

        $this->import($org, $file)->assertExitCode(0);

        $this->assertSame(3, $this->services($org)->count());
        $this->assertSame(2, RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count());

        $a = $this->services($org)->where('name', 'Młot A')->firstOrFail();
        $this->assertSame('mlot-a', $a->slug);
        $this->assertSame('item_rental', $a->service_type->value);
        $this->assertTrue($a->is_active);
        $this->assertNotNull($a->published_at, 'admin list must not say "Wersja robocza"');
        $this->assertEquals(120, $a->price_per_day);
        $this->assertEquals(120, $a->price, 'price mirrors the daily rate like SeedEquipmentRental');
        $this->assertEquals(500, $a->deposit_amount);
        $this->assertSame(90.0, (float) $a->price_per_day_long);
        $this->assertSame(3, $a->quantity_total);
        $this->assertNull($a->area_served);
        $this->assertSame('<p>Opis produktu.</p>', $a->body);
        $this->assertSame(['Walizka w komplecie'], $a->features);
        $this->assertSame('Wynajem młota', $a->meta_title);

        // stock anchor — bookable at the single location
        $this->assertDatabaseHas('service_location_stocks', ['service_id' => $a->id, 'location_id' => $location->id, 'quantity' => 3]);

        // images: first = featured, rest = `image` blocks in the same dirs the admin upload uses
        $this->assertMatchesRegularExpression('#^services/featured/[A-Za-z0-9]{40}\.png$#', $a->featured_image);
        Storage::disk('public')->assertExists($a->featured_image);
        $this->assertCount(1, $a->content);
        $this->assertSame('image', $a->content[0]['type']);
        $this->assertMatchesRegularExpression('#^services/images/[A-Za-z0-9]{40}\.png$#', $a->content[0]['data']['image']);
        $this->assertSame('Walizka', $a->content[0]['data']['alt']);
        Storage::disk('public')->assertExists($a->content[0]['data']['image']);

        // metadata: specs stay a list, image_sources recorded per stored path
        $this->assertSameJsonData([['label' => 'Moc', 'value' => 800, 'unit' => 'W']], $a->metadata['specs']);
        $this->assertSame($a->content[0]['data']['image'], $a->metadata['image_sources'][0]['path']);
        $this->assertSame('Jan Kowalski', $a->metadata['image_sources'][0]['credit']);
        $this->assertSame('CC BY-SA 4.0', $a->metadata['image_sources'][0]['license']);

        // no-image product
        $c = $this->services($org)->where('name', 'Zagęszczarka C')->firstOrFail();
        $this->assertNull($c->featured_image);
        $this->assertNull($c->content);

        // ledger
        $batch = CatalogImportBatch::firstOrFail();
        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->imported_at);
        $this->assertSame(3, $batch->items()->where('entity_type', 'service')->count());
        $this->assertSame(2, $batch->items()->where('entity_type', 'rental_category')->where('origin', 'created')->count());
        $this->assertSame(3, $batch->items()->where('entity_type', 'service_location_stock')->count());
        $this->assertSame(4, $batch->items()->where('entity_type', 'file')->count()); // a:2 b:2 c:0 ... = 4
        $this->assertTrue($batch->items()->where('organization_id', '!=', $org->id)->doesntExist());
    }

    public function test_imported_product_is_visible_on_the_public_site_and_can_be_added_to_the_cart(): void
    {
        $org = $this->rentalTenant();
        $this->import($org, $this->catalogFile([$this->product(['external_id' => 'a', 'name' => 'Młot A'])]))->assertExitCode(0);

        $service = $this->services($org)->firstOrFail();
        $this->assertTrue(Service::withoutGlobalScope('organization')->visibleOnSite()->whereKey($service->id)->exists());

        $this->actingAsTenant($org)->get(route('service.show', $service))->assertOk()->assertSee('Młot A');
        $this->actingAsTenant($org)->get(route('services.index'))->assertOk()->assertSee('Młot A');

        $customer = User::factory()->create();
        $this->actingAs($customer)->actingAsTenant($org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'quantity' => 1,
            ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('cart_items', ['service_id' => $service->id, 'quantity' => 1]);
    }

    public function test_an_admin_save_of_an_imported_product_does_not_corrupt_it(): void
    {
        $org = $this->rentalTenant();
        $this->import($org, $this->catalogFile([$this->product(['external_id' => 'a', 'name' => 'Młot A'])]))->assertExitCode(0);
        $service = $this->services($org)->firstOrFail();
        $before = $service->only(['content', 'features', 'body', 'featured_image', 'quantity_total']);

        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id, ['role' => 'admin']);
        $this->actingAs($admin);
        session(['tenant_id' => $org->id]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $service->fresh();
        $this->assertSameJsonData($before, $after->only(['content', 'features', 'body', 'featured_image', 'quantity_total']));
        $this->assertSameJsonData([['label' => 'Moc', 'value' => 800, 'unit' => 'W']], $after->metadata['specs']);
        // Known, documented: the admin form only binds metadata.specs, so a save drops
        // metadata.image_sources — the same data stays in the ledger (file items' snapshot).
        $this->assertArrayNotHasKey('image_sources', $after->metadata ?? []);
        $ledger = CatalogImportItem::where('entity_type', 'file')->where('service_id', $service->id)->get();
        $this->assertSame('Jan Kowalski', $ledger->firstWhere('snapshot.role', 'gallery')->snapshot['credit']);
    }

    public function test_audit_rows_written_by_the_import_carry_the_organization_id(): void
    {
        $org = $this->rentalTenant();
        $this->import($org, $this->catalogFile([$this->product(['units' => [['identifier' => 'U-1']], 'stock' => null])]))->assertExitCode(0);

        $rows = AuditLog::withoutGlobalScope('organization')->whereIn('auditable_type', [ServiceUnit::class, ServiceLocationStock::class])->get();

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn ($r) => (int) $r->organization_id === $org->id), 'tenant context must be set during the import');
        $this->assertFalse(app('request')->attributes->has('tenant'), 'tenant request attribute must be restored');
    }

    public function test_sitemap_cache_is_invalidated(): void
    {
        $org = $this->rentalTenant();
        Cache::put("sitemap:{$org->id}", 'stale');

        $this->import($org, $this->catalogFile([$this->product()]))->assertExitCode(0);

        $this->assertNull(Cache::get("sitemap:{$org->id}"));
    }

    // ------------------------------------------------------------------ stock shapes

    public function test_multi_location_stock_and_units(): void
    {
        $org = $this->rentalTenant(['gdansk', 'poznan']);
        $gdansk = Location::withoutGlobalScope('organization')->where('organization_id', $org->id)->where('slug', 'gdansk')->firstOrFail();
        $poznan = Location::withoutGlobalScope('organization')->where('organization_id', $org->id)->where('slug', 'poznan')->firstOrFail();

        $this->import($org, $this->catalogFile([
            $this->product(['external_id' => 'split', 'name' => 'Podzielony', 'stock' => ['locations' => ['gdansk' => 2, 'poznan' => 4]]]),
            $this->product(['external_id' => 'units', 'name' => 'Z egzemplarzami', 'stock' => null, 'units' => [
                ['identifier' => 'MD-1', 'location' => 'gdansk'],
                ['identifier' => 'MD-2', 'location' => 'gdansk', 'status' => 'maintenance'],
                ['identifier' => 'MD-3', 'location' => 'poznan', 'inventory_number' => 'INV-3', 'acquired_at' => '2025-05-01'],
            ]]),
        ]))->assertExitCode(0);

        $split = $this->services($org)->where('name', 'Podzielony')->firstOrFail();
        $this->assertSame(6, $split->quantity_total);
        $this->assertDatabaseHas('service_location_stocks', ['service_id' => $split->id, 'location_id' => $gdansk->id, 'quantity' => 2]);
        $this->assertDatabaseHas('service_location_stocks', ['service_id' => $split->id, 'location_id' => $poznan->id, 'quantity' => 4]);

        $withUnits = $this->services($org)->where('name', 'Z egzemplarzami')->firstOrFail();
        $this->assertSame(2, $withUnits->quantity_total, 'available units only: maintenance does not count');
        $this->assertDatabaseHas('service_location_stocks', ['service_id' => $withUnits->id, 'location_id' => $gdansk->id, 'quantity' => 1]);
        $this->assertDatabaseHas('service_location_stocks', ['service_id' => $withUnits->id, 'location_id' => $poznan->id, 'quantity' => 1]);
        $this->assertSame(3, ServiceUnit::withoutGlobalScope('organization')->where('service_id', $withUnits->id)->count());

        $batch = CatalogImportBatch::firstOrFail();
        $this->assertSame(3, $batch->items()->where('entity_type', 'service_unit')->count());
    }

    // ------------------------------------------------------------------ all-or-nothing

    public function test_one_invalid_product_among_many_writes_nothing(): void
    {
        $org = $this->rentalTenant();
        $products = [
            $this->product(['external_id' => 'ok-1']),
            $this->product(['external_id' => 'ok-2']),
            $this->product(['external_id' => 'bad', 'price_per_day' => -5]),
            $this->product(['external_id' => 'ok-3']),
        ];

        $this->import($org, $this->catalogFile($products))->assertExitCode(1);

        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame(0, RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count());
        $this->assertSame(0, ServiceLocationStock::withoutGlobalScope('organization')->count());
        $this->assertSame(0, CatalogImportBatch::count());
        $this->assertSame(0, CatalogImportItem::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_one_unfetchable_image_among_many_writes_nothing(): void
    {
        $org = $this->rentalTenant();
        $products = [
            $this->product(['external_id' => 'ok-1']),
            $this->product(['external_id' => 'broken-img', 'featured_image' => ['url' => 'https://img.example.com/x.png'], 'gallery' => [['url' => 'http://127.0.0.1/admin.png']]]),
        ];

        $this->import($org, $this->catalogFile($products))->assertExitCode(1);

        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, CatalogImportBatch::count());
    }

    public function test_a_failure_during_the_write_phase_rolls_back_rows_and_deletes_stored_files(): void
    {
        $org = $this->rentalTenant();
        $file = $this->catalogFile([$this->product(['external_id' => 'a']), $this->product(['external_id' => 'b'])]);

        // Make the SECOND product's service insert blow up, after the first product's files are stored.
        $calls = 0;
        Service::creating(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('boom');
            }
        });

        $this->import($org, $file)->assertExitCode(1);

        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame(0, RentalCategory::withoutGlobalScope('organization')->count());
        $this->assertSame(0, CatalogImportBatch::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_dry_run_validates_everything_including_images_and_writes_nothing(): void
    {
        $org = $this->rentalTenant();

        $this->import($org, $this->catalogFile([$this->product()]), ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // a bad image makes the DRY RUN fail too — that is its whole point
        $this->import($org, $this->catalogFile([$this->product(['featured_image' => ['url' => 'http://10.0.0.1/a.png']])]), ['--dry-run' => true])
            ->assertExitCode(1);
    }

    public function test_dry_run_with_no_image_check_does_not_download(): void
    {
        $org = $this->rentalTenant();

        $this->import($org, $this->catalogFile([$this->product()]), ['--dry-run' => true, '--no-image-check' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ image memoization

    public function test_one_url_used_by_two_products_is_fetched_once_but_stored_per_product_and_reverted_per_product(): void
    {
        $org = $this->rentalTenant();
        $shared = 'https://img.example.com/shared/hammer.png';
        $file = $this->catalogFile([
            $this->product(['external_id' => 'a', 'name' => 'Produkt A', 'featured_image' => ['url' => $shared, 'alt' => 'A'], 'gallery' => []]),
            // same URL, spelled with an upper-case host and a fragment, as a GALLERY image of another product
            $this->product(['external_id' => 'b', 'name' => 'Produkt B', 'featured_image' => null, 'gallery' => [['url' => 'https://IMG.example.com/shared/hammer.png#zoom', 'alt' => 'B']]]),
        ]);

        $this->import($org, $file)->assertExitCode(0);

        Http::assertSentCount(1);

        $a = $this->services($org)->where('name', 'Produkt A')->firstOrFail();
        $b = $this->services($org)->where('name', 'Produkt B')->firstOrFail();
        $pathA = $a->featured_image;
        $pathB = $b->content[0]['data']['image'];

        $this->assertNotSame($pathA, $pathB, 'every product gets its OWN stored copy');
        Storage::disk('public')->assertExists($pathA);
        Storage::disk('public')->assertExists($pathB);
        $this->assertSame(Storage::disk('public')->get($pathA), Storage::disk('public')->get($pathB));

        $rows = CatalogImportItem::where('entity_type', 'file')->get();
        $this->assertCount(2, $rows);
        $this->assertCount(2, $rows->pluck('path')->unique());
        $this->assertCount(1, $rows->pluck('file_sha256')->unique(), 'identical bytes, one hash');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rows->pluck('service_id')->all());

        $this->artisan('catalog:import-revert', ['batch' => (string) CatalogImportBatch::firstOrFail()->id])
            ->expectsConfirmation('To NIEODWRACALNE. Cofnąć import?', 'yes')
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing($pathA);
        Storage::disk('public')->assertMissing($pathB);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_failing_url_used_by_two_products_is_requested_once_and_reported_for_every_occurrence(): void
    {
        $org = $this->rentalTenant();
        Http::fake(['broken.example.net/*' => Http::response('gone', 404)]);

        $products = [
            $this->product(['external_id' => 'a', 'featured_image' => ['url' => 'https://broken.example.net/x.png'], 'gallery' => []]),
            $this->product(['external_id' => 'b', 'featured_image' => null, 'gallery' => [['url' => 'https://broken.example.net/x.png']]]),
        ];

        $result = app(\App\Actions\Catalog\ImportCatalog::class)->run($org, $this->catalogFile($products), new \App\Actions\Catalog\ImportOptions(imageDelayMs: 0));

        Http::assertSentCount(1);
        $errors = $result->report->errors();
        $this->assertCount(2, $errors);
        $this->assertSame([0, 1], array_column($errors, 'index'));
        $this->assertSame(['featured_image', 'gallery.0'], array_column($errors, 'field'));
        $this->assertSame($errors[0]['message'], $errors[1]['message']);
        $this->assertStringContainsString('HTTP 404', $errors[0]['message']);

        $this->assertFalse($result->ok());
        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, CatalogImportBatch::count());
    }

    public function test_a_failing_url_in_optional_mode_warns_for_every_occurrence_with_one_request(): void
    {
        $org = $this->rentalTenant();
        Http::fake(['broken.example.net/*' => Http::response('gone', 404)]);
        $broken = ['url' => 'https://broken.example.net/x.png'];

        $result = app(\App\Actions\Catalog\ImportCatalog::class)->run($org, $this->catalogFile([
            $this->product(['external_id' => 'a', 'featured_image' => $broken, 'gallery' => []]),
            $this->product(['external_id' => 'b', 'featured_image' => $broken, 'gallery' => []]),
        ]), new \App\Actions\Catalog\ImportOptions(images: 'optional', imageDelayMs: 0));

        Http::assertSentCount(1);
        $this->assertTrue($result->ok());
        $this->assertCount(2, $result->report->warnings());
        $this->assertSame(2, $this->services($org)->count());
    }

    public function test_the_same_local_file_for_two_products_is_read_once_and_stored_twice(): void
    {
        $org = $this->rentalTenant();
        $dir = sys_get_temp_dir().'/catalog-memo-'.bin2hex(random_bytes(4));
        mkdir($dir.'/images', 0777, true);
        file_put_contents($dir.'/images/own.png', $this->png(80, 80));

        // "images/own.png" and "./images/own.png" resolve to the same realpath
        $file = $this->catalogFile([
            $this->product(['external_id' => 'a', 'name' => 'L1', 'featured_image' => ['path' => 'images/own.png'], 'gallery' => []]),
            $this->product(['external_id' => 'b', 'name' => 'L2', 'featured_image' => ['path' => './images/own.png'], 'gallery' => []]),
        ], $dir);

        $this->import($org, $file)->assertExitCode(0);

        $paths = $this->services($org)->pluck('featured_image')->all();
        $this->assertCount(2, array_unique($paths), 'one read, two stored copies');
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_a_dot_dot_spelling_is_still_refused_after_a_clean_spelling_of_the_same_file_succeeded(): void
    {
        $org = $this->rentalTenant();
        $dir = sys_get_temp_dir().'/catalog-memo-'.bin2hex(random_bytes(4));
        mkdir($dir.'/images', 0777, true);
        file_put_contents($dir.'/images/own.png', $this->png(80, 80));

        $file = $this->catalogFile([
            $this->product(['external_id' => 'a', 'name' => 'L1', 'featured_image' => ['path' => 'images/own.png'], 'gallery' => []]),
            $this->product(['external_id' => 'b', 'name' => 'L2', 'featured_image' => ['path' => 'images/../images/own.png'], 'gallery' => []]),
        ], $dir);

        $this->import($org, $file)->assertExitCode(1);

        $this->assertSame(0, $this->services($org)->count());
    }

    // ------------------------------------------------------------------ images modes

    public function test_images_optional_imports_the_product_without_the_broken_image(): void
    {
        $org = $this->rentalTenant();
        $file = $this->catalogFile([$this->product(['name' => 'Bez zdjęcia', 'featured_image' => ['url' => 'http://127.0.0.1/a.png']])]);

        $this->import($org, $file, ['--images' => 'optional'])->assertExitCode(0);

        $service = $this->services($org)->firstOrFail();
        $this->assertNull($service->featured_image);
        $this->assertCount(1, $service->content);
    }

    public function test_images_skip_ignores_all_images_and_never_touches_the_network(): void
    {
        $org = $this->rentalTenant();

        $this->import($org, $this->catalogFile([$this->product()]), ['--images' => 'skip'])->assertExitCode(0);

        Http::assertNothingSent();
        $service = $this->services($org)->firstOrFail();
        $this->assertNull($service->featured_image);
        $this->assertNull($service->content);
    }

    public function test_local_path_images_are_resolved_relative_to_the_json_file(): void
    {
        $org = $this->rentalTenant();
        $dir = sys_get_temp_dir().'/catalog-local-'.bin2hex(random_bytes(4));
        mkdir($dir.'/images', 0777, true);
        file_put_contents($dir.'/images/own.png', $this->png(80, 80));

        $file = $this->catalogFile([$this->product(['featured_image' => ['path' => 'images/own.png', 'alt' => 'Własne'], 'gallery' => []])], $dir);
        $this->import($org, $file)->assertExitCode(0);

        Http::assertNothingSent();
        $service = $this->services($org)->firstOrFail();
        Storage::disk('public')->assertExists($service->featured_image);

        // escape attempt: the whole file is refused, nothing written
        $bad = $this->catalogFile([$this->product(['name' => 'Inny', 'featured_image' => ['path' => '../outside.png'], 'gallery' => []])], $dir.'/sub');
        file_put_contents(dirname($bad).'/../outside.png', $this->png());
        $this->import($org, $bad)->assertExitCode(1);
        $this->assertSame(1, $this->services($org)->count());
    }

    // ------------------------------------------------------------------ idempotency / collisions

    public function test_re_running_the_same_file_is_refused_by_default_and_skipped_with_skip_existing(): void
    {
        $org = $this->rentalTenant();
        $products = [$this->product(['external_id' => 'a', 'name' => 'Młot A'])];
        $file = $this->catalogFile($products);

        $this->import($org, $file)->assertExitCode(0);
        $this->import($org, $file)->assertExitCode(1);
        $this->assertSame(1, $this->services($org)->count());

        $this->import($org, $file, ['--skip-existing' => true])->assertExitCode(0);
        $this->assertSame(1, $this->services($org)->count());
        $this->assertSame(1, CatalogImportBatch::count(), 'a pure skip run creates no batch');

        // new product + the old one in one file: only the new one is added
        $file2 = $this->catalogFile([$products[0], $this->product(['external_id' => 'b', 'name' => 'Nowy B'])]);
        $this->import($org, $file2, ['--skip-existing' => true])->assertExitCode(0);
        $this->assertSame(2, $this->services($org)->count());
        $this->assertSame(1, $this->services($org)->where('name', 'Młot A')->count());
    }

    public function test_name_or_slug_collision_with_a_non_ledger_product_is_a_hard_error_even_with_skip_existing(): void
    {
        $org = $this->rentalTenant();
        Service::factory()->itemRental()->create(['organization_id' => $org->id, 'name' => 'Młot A', 'slug' => 'reczny']);

        $this->import($org, $this->catalogFile([$this->product(['external_id' => 'a', 'name' => 'MŁOT a'])]), ['--skip-existing' => true])
            ->assertExitCode(1);
        $this->import($org, $this->catalogFile([$this->product(['external_id' => 'b', 'name' => 'Inna', 'slug' => 'reczny'])]))
            ->assertExitCode(1);

        $this->assertSame(1, $this->services($org)->count());
    }

    public function test_the_same_name_in_another_organization_is_fine(): void
    {
        $org = $this->rentalTenant();
        $other = $this->rentalTenant(['x']);
        Service::factory()->itemRental()->create(['organization_id' => $other->id, 'name' => 'Młot A', 'slug' => 'mlot-a']);

        $this->import($org, $this->catalogFile([$this->product(['name' => 'Młot A'])]))->assertExitCode(0);

        $this->assertSame(1, $this->services($org)->count());
        $this->assertSame(1, $this->services($other)->count());
    }

    // ------------------------------------------------------------------ preconditions

    public function test_refuses_an_organization_without_an_active_location_or_without_rentals(): void
    {
        $noLocation = Organization::factory()->equipmentRental()->create();
        $this->import($noLocation, $this->catalogFile([$this->product()]))->assertExitCode(1);

        $detailing = Organization::factory()->autoDetailing()->create();
        Location::factory()->for($detailing, 'organization')->create();
        $this->import($detailing, $this->catalogFile([$this->product()]))->assertExitCode(1);

        $this->assertSame(0, Service::withoutGlobalScope('organization')->count());
    }

    public function test_unknown_organization_and_bad_file_and_bad_options(): void
    {
        $org = $this->rentalTenant();

        $this->artisan('catalog:import', ['organization' => '999999', 'file' => '/nope.json'])->assertExitCode(1);
        $this->artisan('catalog:import', ['organization' => (string) $org->id, 'file' => '/nope.json'])->assertExitCode(1);
        $this->import($org, $this->catalogFile([$this->product()]), ['--images' => 'bogus'])->assertExitCode(2);

        $bad = sys_get_temp_dir().'/bad-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($bad, '{not json');
        $this->import($org, $bad)->assertExitCode(1);
        file_put_contents($bad, json_encode(['schema_version' => 2, 'products' => [[]]]));
        $this->import($org, $bad)->assertExitCode(1);
    }

    public function test_status_command_lists_batches(): void
    {
        $org = $this->rentalTenant();
        $this->import($org, $this->catalogFile([$this->product()]))->assertExitCode(0);

        $this->artisan('catalog:import-status', ['organization' => (string) $org->id])->assertExitCode(0);
        $this->artisan('catalog:import-status', ['--batch' => (string) CatalogImportBatch::firstOrFail()->id])->assertExitCode(0);
    }
}
