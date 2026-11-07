<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\RevertCatalogImport;
use App\Actions\Catalog\RevertOptions;
use App\Actions\Catalog\RevertRefusedException;
use App\Models\Appointment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CatalogImportBatch;
use App\Models\CatalogImportItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtensionRequest;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The revert may delete ONLY what the batch's ledger says it created. The survival matrix
 * (test_revert_returns_every_table_to_its_pre_import_state_for_both_organizations) is the
 * proof: it dumps the four affected tables — raw rows, all organizations — before the
 * import, imports, reverts, and requires BYTE-FOR-BYTE equality, plus the same for the
 * files on disk.
 */
class CatalogImportRevertTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private const REVERT_CONFIRM = 'To NIEODWRACALNE. Cofnąć import?';

    private const TABLES = ['services', 'rental_categories', 'service_units', 'service_location_stocks'];

    private Organization $a;

    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->fakeImageHost();
        $this->a = $this->rentalTenant(['siedziba']);
        $this->b = $this->rentalTenant(['siedziba']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * EVERY table (via sqlite_master) except the ledger itself and audit_logs — the two places
     * an import/revert legitimately writes to. Anything else that differs after import+revert is
     * a side effect nobody asked for. Rows are sorted by their JSON so tables without an id
     * (pivots) compare too.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function dumpAll(): array
    {
        $out = [];

        $tables = collect(DB::select("select name from sqlite_master where type = 'table' order by name"))
            ->pluck('name')
            ->reject(fn (string $t) => str_starts_with($t, 'sqlite_') || str_starts_with($t, 'catalog_import_') || $t === 'audit_logs');

        foreach ($tables as $t) {
            $rows = DB::table($t)->get()->map(fn ($r) => (array) $r)->all();
            usort($rows, fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));
            $out[$t] = $rows;
        }

        return $out;
    }

    /** The four tables the revert deletes from, ordered by id. @return array<string, array<int, array<string, mixed>>> */
    private function dump(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    /** @return array<int, string> */
    private function files(): array
    {
        $files = Storage::disk('public')->allFiles();
        sort($files);

        return $files;
    }

    private function location(Organization $org): Location
    {
        return Location::withoutGlobalScope('organization')->where('organization_id', $org->id)->firstOrFail();
    }

    private function putFile(string $dir, string $char): string
    {
        $path = $dir.'/'.str_repeat($char, 40).'.png';
        Storage::disk('public')->put($path, $this->png(70, 70));

        return $path;
    }

    /** Pre-existing data of BOTH organizations, including look-alikes of what the import will create. */
    private function seedPreExisting(): void
    {
        foreach ([$this->a, $this->b] as $org) {
            $location = $this->location($org);
            $reused = RentalCategory::factory()->create(['organization_id' => $org->id, 'name' => 'Elektronarzędzia', 'slug' => 'elektronarzedzia']);
            $same = RentalCategory::factory()->create(['organization_id' => $org->id, 'name' => 'Sprzęt budowlany', 'slug' => 'sprzet-budowlany']);
            $other = RentalCategory::factory()->create(['organization_id' => $org->id, 'name' => 'Stara', 'slug' => 'stara']);

            // the look-alike: same name+slug as the first imported product, but another organization's/pre-existing
            $own = Service::factory()->itemRental()->create([
                'organization_id' => $org->id, 'rental_category_id' => $same->id, 'name' => $org->is($this->b) ? 'Młot A' : 'Stary produkt',
                'slug' => $org->is($this->b) ? 'mlot-a' : 'stary-produkt',
                'featured_image' => $this->putFile('services/featured', $org->is($this->b) ? 'b' : 'a'), 'quantity_total' => 2,
            ]);
            ServiceUnit::create(['organization_id' => $org->id, 'service_id' => $own->id, 'location_id' => $location->id, 'identifier' => 'PRE-'.$org->id]);
            ServiceUnit::create(['organization_id' => $org->id, 'service_id' => $own->id, 'location_id' => $location->id, 'identifier' => 'PRE-'.$org->id.'-2']);

            // foreign dependents of the pre-existing product: every FK child of `services`
            CartItem::factory()->create(['service_id' => $own->id]);
            OrderItem::factory()->create(['service_id' => $own->id, 'order_id' => Order::factory()->create(['organization_id' => $org->id])->id]);
            Rental::factory()->create(['service_id' => $own->id, 'organization_id' => $org->id]);
            Appointment::withoutEvents(fn () => Appointment::factory()->create([
                'service_id' => $own->id, 'organization_id' => $org->id, 'staff_id' => User::factory()->create()->id,
                'vehicle_type_id' => VehicleType::query()->first()?->id ?? VehicleType::factory()->create()->id,
            ]));
            $own->staff()->attach(User::factory()->create()->id);

            Service::factory()->itemRental()->create(['organization_id' => $org->id, 'rental_category_id' => $reused->id, 'name' => 'W reused '.$org->id, 'slug' => 'w-reused-'.$org->id]);
            Service::factory()->itemRental()->create(['organization_id' => $org->id, 'rental_category_id' => $other->id, 'name' => 'W starej '.$org->id, 'slug' => 'w-starej-'.$org->id]);
        }

        // decoys in the very directories the importer writes to, referenced by nothing
        $this->putFile('services/featured', 'd');
        $this->putFile('services/images', 'd');
    }

    /** @return array<int, array<string, mixed>> */
    private function products(): array
    {
        return [
            $this->product(['external_id' => 'p1', 'name' => 'Młot A', 'category' => ['name' => 'Elektronarzędzia']]),
            $this->product(['external_id' => 'p2', 'name' => 'Szlifierka B', 'category' => ['name' => 'Sprzęt budowlany']]),
            $this->product(['external_id' => 'p3', 'name' => 'Zagęszczarka C', 'category' => ['name' => 'Nowa kategoria'], 'stock' => null, 'units' => [['identifier' => 'IMP-1'], ['identifier' => 'IMP-2']]]),
        ];
    }

    private function runImport(?Organization $org = null, ?array $products = null): CatalogImportBatch
    {
        $this->artisan('catalog:import', [
            'organization' => (string) ($org ?? $this->a)->id,
            'file' => $this->catalogFile($products ?? $this->products()),
            '--image-delay' => '0',
        ])->assertExitCode(0);

        return CatalogImportBatch::orderByDesc('id')->firstOrFail();
    }

    private function revert(CatalogImportBatch $batch, array $flags = [], int $exit = 0): void
    {
        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id] + $flags)
            ->expectsConfirmation(self::REVERT_CONFIRM, 'yes')
            ->assertExitCode($exit);
    }

    /** @return array<int, int> */
    private function batchServiceIds(CatalogImportBatch $batch): array
    {
        return $batch->items()->where('entity_type', 'service')->pluck('entity_id')->map(fn ($i) => (int) $i)->all();
    }

    private function serviceOf(CatalogImportBatch $batch, string $externalKey): Service
    {
        $id = $batch->items()->where('entity_type', 'service')->where('external_key', $externalKey)->value('entity_id');

        return Service::withoutGlobalScope('organization')->findOrFail($id);
    }

    // ------------------------------------------------------------------ the survival matrix

    public function test_revert_returns_every_table_to_its_pre_import_state_for_both_organizations(): void
    {
        $this->seedPreExisting();
        // Time must pass between seed, import and revert: a revert that merely touched
        // `updated_at` of a pre-existing row (or re-saved it) would be invisible if everything
        // happened inside one second.
        $this->travel(10)->minutes();
        $before = $this->dump();
        $beforeAll = $this->dumpAll();
        $beforeAudit = DB::table('audit_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $filesBefore = $this->files();

        $this->assertNotEmpty($beforeAudit, 'fixture must contain audit rows (units/stock/locations are Auditable)');
        $this->assertGreaterThan(0, count($beforeAll['cart_items']), 'fixture must contain foreign FK children');
        $this->assertGreaterThan(0, count($beforeAll['service_staff']));
        $this->assertGreaterThan(0, count($beforeAll['order_items']));
        $this->assertGreaterThan(0, count($beforeAll['appointments']));
        $this->assertGreaterThan(0, count($beforeAll['rentals']));

        $batch = $this->runImport();

        $after = $this->dump();
        $ids = $this->batchServiceIds($batch);
        $this->assertCount(3, $ids);
        $this->assertNotSame($before['services'], $after['services'], 'the import must have added rows');
        $this->assertGreaterThan(count($filesBefore), count($this->files()));

        // the import itself touched nothing pre-existing
        foreach (['services', 'rental_categories', 'service_units', 'service_location_stocks'] as $t) {
            $createdIds = $batch->items()->where('entity_type', match ($t) {
                'services' => 'service', 'rental_categories' => 'rental_category', 'service_units' => 'service_unit', default => 'service_location_stock',
            })->where('origin', 'created')->pluck('entity_id')->all();

            $survivors = array_values(array_filter($after[$t], fn ($r) => ! in_array($r['id'], $createdIds, true)));
            $this->assertSame($before[$t], $survivors, "{$t}: the import itself modified pre-existing rows");
        }

        $this->travel(10)->minutes();
        $this->revert($batch);

        $this->assertSame($before, $this->dump(), 'revert must restore all four tables exactly, both organizations');
        $auditAfter = DB::table('audit_logs')->whereIn('id', array_column($beforeAudit, 'id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($beforeAudit, $auditAfter, 'audit rows that existed before the import are untouched by import AND revert');
        $this->assertSame($beforeAll, $this->dumpAll(), 'EVERY other table (cart_items, order_items, rentals, appointments, service_staff, …) must be identical too');
        $this->assertSame($filesBefore, $this->files(), 'revert must delete exactly the files the batch stored — decoys and pre-existing files survive');

        $batch->refresh();
        $this->assertSame('reverted', $batch->status);
        $this->assertNotNull($batch->reverted_at);
        $this->assertSame(0, $batch->items()->whereNull('reverted_at')->count());
    }

    public function test_specific_survivors_are_untouched_and_the_other_organizations_look_alikes_too(): void
    {
        $this->seedPreExisting();
        $batch = $this->runImport();

        $bMlot = Service::withoutGlobalScope('organization')->where('organization_id', $this->b->id)->where('slug', 'mlot-a')->firstOrFail();
        $bFile = $bMlot->featured_image;
        $bSnapshot = $bMlot->getAttributes();
        $bUnits = ServiceUnit::withoutGlobalScope('organization')->where('organization_id', $this->b->id)->count();

        $this->revert($batch);

        // org A's own "Młot A" (batch) is gone, org B's "Młot A" (same name AND slug) is intact
        $this->assertSame(0, Service::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->where('slug', 'mlot-a')->count());
        $this->assertSame($bSnapshot, $bMlot->fresh()->getAttributes());
        Storage::disk('public')->assertExists($bFile);
        $this->assertSame($bUnits, ServiceUnit::withoutGlobalScope('organization')->where('organization_id', $this->b->id)->count());

        // same-org pre-existing: product, its units, its stock, both categories and the reused one
        $stary = Service::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->where('slug', 'stary-produkt')->firstOrFail();
        $this->assertSame(2, ServiceUnit::withoutGlobalScope('organization')->where('service_id', $stary->id)->count());
        $this->assertDatabaseHas('service_location_stocks', ['service_id' => $stary->id, 'quantity' => 2]);
        Storage::disk('public')->assertExists($stary->featured_image);

        foreach (['elektronarzedzia', 'sprzet-budowlany', 'stara'] as $slug) {
            $this->assertSame(1, RentalCategory::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->where('slug', $slug)->count(), $slug);
            $this->assertSame(1, RentalCategory::withoutGlobalScope('organization')->where('organization_id', $this->b->id)->where('slug', $slug)->count(), $slug);
        }
        // the one category the batch created is gone
        $this->assertSame(0, RentalCategory::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->where('slug', 'nowa-kategoria')->count());
    }

    public function test_a_reused_category_is_never_deleted_even_when_the_revert_leaves_it_empty(): void
    {
        $cat = RentalCategory::factory()->create(['organization_id' => $this->a->id, 'name' => 'Elektronarzędzia', 'slug' => 'elektronarzedzia']);
        $batch = $this->runImport(products: [$this->product(['name' => 'Jedyny', 'category' => ['name' => 'Elektronarzędzia']])]);

        $this->revert($batch);

        $this->assertDatabaseHas('rental_categories', ['id' => $cat->id]);
        $this->assertSame(0, Service::withoutGlobalScope('organization')->where('rental_category_id', $cat->id)->count());
    }

    public function test_a_batch_created_category_is_kept_when_a_foreign_product_uses_it(): void
    {
        $batch = $this->runImport(products: [$this->product(['name' => 'Mój', 'category' => ['name' => 'Nowa']])]);
        $category = RentalCategory::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->where('slug', 'nowa')->firstOrFail();
        $foreign = Service::factory()->itemRental()->create(['organization_id' => $this->a->id, 'rental_category_id' => $category->id]);

        $this->revert($batch);

        $this->assertDatabaseHas('rental_categories', ['id' => $category->id]);
        $this->assertSame($category->id, $foreign->fresh()->rental_category_id, 'the foreign product must not be un-categorised (FK is SET NULL)');
        $this->assertSame(0, $batch->items()->where('entity_type', 'service')->whereNull('reverted_at')->count());
    }

    public function test_a_category_edited_after_import_is_kept(): void
    {
        $batch = $this->runImport(products: [$this->product(['category' => ['name' => 'Nowa']])]);
        $this->travel(5)->minutes();
        $category = RentalCategory::withoutGlobalScope('organization')->where('slug', 'nowa')->firstOrFail();
        $category->update(['description' => 'Redakcja admina']);

        $this->revert($batch);

        $this->assertDatabaseHas('rental_categories', ['id' => $category->id]);
    }

    public function test_files_referenced_by_a_row_outside_the_batch_or_changed_since_are_kept(): void
    {
        $batch = $this->runImport(products: [
            $this->product(['external_id' => 'x', 'name' => 'X']),
            $this->product(['external_id' => 'y', 'name' => 'Y']),
        ]);

        $x = $this->serviceOf($batch, 'x');
        $y = $this->serviceOf($batch, 'y');
        $sharedByForeign = $x->featured_image;
        $tampered = $y->featured_image;

        Service::factory()->itemRental()->create(['organization_id' => $this->b->id, 'featured_image' => $sharedByForeign]);
        Storage::disk('public')->put($tampered, 'different bytes than the ledger recorded');

        $this->revert($batch);

        Storage::disk('public')->assertExists($sharedByForeign);
        Storage::disk('public')->assertExists($tampered);
        // …while the batch's other files are gone
        $this->assertSame(0, count(array_filter($this->files(), fn ($f) => str_starts_with($f, 'services/images/'))));
    }

    // ------------------------------------------------------------------ gate behaviour

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedPreExisting();
        $batch = $this->runImport();
        $dump = $this->dump();
        $files = $this->files();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0);

        $this->assertSame($dump, $this->dump());
        $this->assertSame($files, $this->files());
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_declining_the_confirmation_deletes_nothing(): void
    {
        $batch = $this->runImport();
        $dump = $this->dump();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])
            ->expectsConfirmation(self::REVERT_CONFIRM, 'no')
            ->assertExitCode(1);

        $this->assertSame($dump, $this->dump());
    }

    public function test_non_interactive_runs_without_a_prompt_and_the_audit_log_says_so(): void
    {
        $batch = $this->runImport();
        Log::spy();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--no-interaction' => true, '--force' => true])->assertExitCode(0);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'catalog:import-revert before purge'
            && ($context['interactive'] ?? null) === false
            && $context['batch_id'] === $batch->id)->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === 'catalog:import-revert completed');
        $this->assertSame(0, Service::withoutGlobalScope('organization')->count());
    }

    public function test_reverting_twice_or_an_unknown_batch_is_refused(): void
    {
        $batch = $this->runImport();
        $this->revert($batch);

        // a reverted batch is not fatal any more: only the idempotent clean-up runs (nothing left here)
        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])
            ->expectsOutputToContain('mapa strony odświeżona')
            ->assertExitCode(0);
        $this->artisan('catalog:import-revert', ['batch' => '99999'])->assertExitCode(1);
        $this->artisan('catalog:import-revert', ['batch' => 'abc'])->assertExitCode(1);
    }

    // ------------------------------------------------------------------ fatal identity checks

    public function test_a_ledger_row_pointing_at_another_organizations_row_aborts_the_whole_revert(): void
    {
        $this->seedPreExisting();
        $batch = $this->runImport();
        $victim = $this->serviceOf($batch, 'p3'); // not p1: org B owns a "Młot A" and the unique index would stop the tamper
        $dump = $this->dump();

        // simulate id reuse: the batch's product row now belongs to organization B
        DB::table('services')->where('id', $victim->id)->update(['organization_id' => $this->b->id]);
        $dumpAfterTamper = $this->dump();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--partial' => true, '--include-modified' => true])->assertExitCode(1);

        $this->assertSame($dumpAfterTamper, $this->dump(), 'nothing — not even the other two products — may be deleted');
        $this->assertNotSame($dump, $dumpAfterTamper);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_a_product_renamed_in_the_panel_is_a_soft_block_overridable_by_include_modified(): void
    {
        $batch = $this->runImport();
        $this->travel(10)->minutes();
        $p2 = $this->serviceOf($batch, 'p2');
        // exactly what an admin does in the panel: new name AND new slug
        DB::table('services')->where('id', $p2->id)->update(['name' => 'Przemianowana', 'slug' => 'przemianowana']);
        $count = Service::withoutGlobalScope('organization')->count();

        // blocked by default, with the reason named
        $plan = app(RevertCatalogImport::class)->analyse($batch, new RevertOptions);
        $this->assertSame([], $plan->fatal, 'a rename is NOT an identity failure');
        $this->assertContains('nazwa/slug zmienione po imporcie', $plan->blocked[$p2->id]['soft']);
        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])->assertExitCode(1);
        $this->assertSame($count, Service::withoutGlobalScope('organization')->count());

        // the rename alone (updated_at untouched by the raw update) is enough — and --include-modified clears it
        $this->revert($batch, ['--include-modified' => true]);
        $this->assertSame(0, Service::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->count());
    }

    public function test_delete_and_recreate_with_the_same_name_and_slug_on_a_recycled_id_is_fatal(): void
    {
        $this->seedPreExisting();
        $batch = $this->runImport();
        $victim = $this->serviceOf($batch, 'p2');
        $victimId = $victim->id;
        $name = $victim->name;
        $slug = $victim->slug;

        // an admin deletes the product and later something re-creates the SAME name+slug and,
        // after an AUTO_INCREMENT reset, even the SAME id
        DB::table('services')->where('id', $victimId)->delete();
        $this->travel(30)->minutes();
        DB::table('services')->insert([
            'id' => $victimId, 'organization_id' => $this->a->id, 'name' => $name, 'slug' => $slug,
            'service_type' => 'item_rental', 'price' => 1, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dump = $this->dumpAll();

        $plan = app(RevertCatalogImport::class)->analyse($batch, new RevertOptions(partial: true, includeModified: true, discardCartItems: true));
        $this->assertNotEmpty($plan->fatal);
        $this->assertFalse($plan->canExecute());

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--partial' => true, '--include-modified' => true, '--discard-cart-items' => true])
            ->assertExitCode(1);

        $this->assertSame($dump, $this->dumpAll(), 'nothing — including the other two batch products — may be deleted');
    }

    public function test_units_and_stock_rows_created_after_import_under_a_recycled_id_are_fatal_too(): void
    {
        $batch = $this->runImport();
        $unitItem = $batch->items()->where('entity_type', 'service_unit')->firstOrFail();
        $this->travel(30)->minutes();

        // same id, same service, but born after the import finished
        DB::table('service_units')->where('id', $unitItem->entity_id)->update(['created_at' => now()]);
        $count = Service::withoutGlobalScope('organization')->count();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--include-modified' => true])->assertExitCode(1);

        $this->assertSame($count, Service::withoutGlobalScope('organization')->count());
    }

    public function test_a_ledger_row_whose_organization_differs_from_the_batch_aborts(): void
    {
        $batch = $this->runImport();
        CatalogImportItem::where('batch_id', $batch->id)->where('entity_type', 'service')->first()->update(['organization_id' => $this->b->id]);
        $count = Service::withoutGlobalScope('organization')->count();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])->assertExitCode(1);

        $this->assertSame($count, Service::withoutGlobalScope('organization')->count());
    }

    public function test_a_product_an_admin_already_deleted_is_tolerated(): void
    {
        $batch = $this->runImport();
        $p1 = $this->serviceOf($batch, 'p1');
        $p1->delete();

        $this->revert($batch);

        $this->assertSame(0, Service::withoutGlobalScope('organization')->count());
        $this->assertSame('reverted', $batch->fresh()->status);
    }

    // ------------------------------------------------------------------ dependents (hard)

    public function test_orders_rentals_and_appointments_refuse_the_revert_and_no_flag_overrides_it(): void
    {
        foreach (['order', 'rental', 'appointment'] as $kind) {
            $batch = $this->runImport(products: [
                $this->product(['name' => "Z {$kind}", 'external_id' => "dep-{$kind}"]),
                $this->product(['name' => "Czysty {$kind}", 'external_id' => "clean-{$kind}"]),
            ]);
            $service = $this->serviceOf($batch, "dep-{$kind}");

            match ($kind) {
                'order' => OrderItem::factory()->create(['service_id' => $service->id, 'order_id' => Order::factory()->create(['organization_id' => $this->a->id])->id]),
                'rental' => Rental::factory()->create(['service_id' => $service->id, 'organization_id' => $this->a->id]),
                'appointment' => Appointment::withoutEvents(fn () => Appointment::factory()->create(['service_id' => $service->id, 'organization_id' => $this->a->id, 'staff_id' => \App\Models\User::factory()->create()->id])),
            };

            $count = Service::withoutGlobalScope('organization')->count();

            $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--include-modified' => true, '--discard-cart-items' => true])->assertExitCode(1);
            $this->assertSame($count, Service::withoutGlobalScope('organization')->count(), "{$kind}: default must abort the whole revert");

            // --partial: reverts the clean one, keeps the blocked one (and what it depends on)
            $this->revert($batch, ['--partial' => true, '--include-modified' => true]);
            $this->assertDatabaseHas('services', ['id' => $service->id]);
            $this->assertDatabaseMissing('services', ['name' => "Czysty {$kind}"]);
            $this->assertSame('partially_reverted', $batch->fresh()->status);
            $this->assertSame(1, $batch->items()->where('entity_type', 'service')->whereNull('reverted_at')->count());
        }
    }

    public function test_cart_items_block_by_default_and_discard_cart_items_removes_only_those_of_the_batch(): void
    {
        $batch = $this->runImport();
        $service = $this->serviceOf($batch, 'p1');
        $foreignService = Service::factory()->itemRental()->create(['organization_id' => $this->b->id]);

        CartItem::factory()->create(['service_id' => $service->id]);
        $foreignCartItem = CartItem::factory()->create(['service_id' => $foreignService->id]);

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])->assertExitCode(1);
        $this->assertDatabaseHas('services', ['id' => $service->id]);

        $this->revert($batch, ['--discard-cart-items' => true]);

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
        $this->assertDatabaseMissing('cart_items', ['service_id' => $service->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $foreignCartItem->id]);
        $this->assertDatabaseHas('services', ['id' => $foreignService->id]);
        $this->assertSame(1, CartItem::count(), 'exactly the foreign product\'s cart item is left');
    }

    // ------------------------------------------------------------------ "touched after import" (soft)

    public function test_a_product_edited_after_import_blocks_unless_include_modified(): void
    {
        $batch = $this->runImport();
        $this->travel(10)->minutes();
        $this->serviceOf($batch, 'p1')->update(['excerpt' => 'Admin zmienił opis']);
        $count = Service::withoutGlobalScope('organization')->count();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])->assertExitCode(1);
        $this->assertSame($count, Service::withoutGlobalScope('organization')->count());

        $this->revert($batch, ['--include-modified' => true]);
        $this->assertSame(0, Service::withoutGlobalScope('organization')->count());
    }

    public function test_units_stock_and_photos_added_or_changed_later_block_and_include_modified_takes_them_along(): void
    {
        $cases = [
            'added unit' => function (Service $s): void {
                ServiceUnit::create(['organization_id' => $s->organization_id, 'service_id' => $s->id, 'location_id' => $this->location($this->a)->id, 'identifier' => 'ADMIN-1']);
            },
            'stock quantity changed' => function (Service $s): void {
                DB::table('service_location_stocks')->where('service_id', $s->id)->update(['quantity' => 99, 'updated_at' => now()]);
            },
            'photo replaced' => function (Service $s): void {
                DB::table('services')->where('id', $s->id)->update(['featured_image' => $this->putFile('services/featured', 'z')]);
            },
        ];

        foreach ($cases as $label => $touch) {
            $batch = $this->runImport(products: [$this->product(['name' => "Dotknięty {$label}"])]);
            $service = $this->serviceOf($batch, (string) $batch->items()->where('entity_type', 'service')->value('external_key'));
            $this->travel(10)->minutes();
            $touch($service);

            $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])->assertExitCode(1);
            $this->assertDatabaseHas('services', ['id' => $service->id]);

            $this->revert($batch, ['--include-modified' => true]);
            $this->assertDatabaseMissing('services', ['id' => $service->id]);
            $this->assertSame(0, DB::table('service_units')->where('service_id', $service->id)->count(), $label);
            $this->assertSame(0, DB::table('service_location_stocks')->where('service_id', $service->id)->count(), $label);
            $this->travelBack();
        }
    }

    public function test_a_zero_quantity_stock_row_created_by_a_new_location_is_not_a_modification(): void
    {
        $batch = $this->runImport();
        $this->travel(10)->minutes();
        Location::factory()->for($this->a, 'organization')->create(['slug' => 'nowy-oddzial']); // observer adds 0-rows for every item_rental service

        $this->assertSame(2, DB::table('service_location_stocks')->where('service_id', $this->serviceOf($batch, 'p1')->id)->count());

        $this->revert($batch);

        $this->assertSame(0, Service::withoutGlobalScope('organization')->count());
        $this->assertSame(0, DB::table('service_location_stocks')->count());
    }

    // ------------------------------------------------------------------ AUTO_INCREMENT

    public function test_reset_auto_increment_is_never_attempted_on_sqlite_and_says_why(): void
    {
        $batch = $this->runImport();
        $statements = [];
        DB::listen(function ($q) use (&$statements): void {
            $statements[] = $q->sql;
        });

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--reset-auto-increment' => true])
            ->expectsConfirmation(self::REVERT_CONFIRM, 'yes')
            ->expectsOutputToContain('AUTO_INCREMENT pominięty: services')
            ->assertExitCode(0);

        $this->assertEmpty(array_filter($statements, fn (string $s) => stripos($s, 'alter table') !== false));
    }

    // ------------------------------------------------------------------ concurrency guards

    public function test_revert_takes_the_import_lock_and_fails_fast_when_it_is_held(): void
    {
        $batch = $this->runImport();
        $dump = $this->dumpAll();

        $held = Cache::lock("catalog-import:{$this->a->id}", 60);
        $this->assertTrue($held->get());

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])
            ->expectsConfirmation(self::REVERT_CONFIRM, 'yes')
            ->expectsOutputToContain('Inny import lub cofnięcie tej organizacji jest w toku')
            ->assertExitCode(1);

        $this->assertSame($dump, $this->dumpAll());

        // another organization's lock is a different key: not blocked
        $other = Cache::lock("catalog-import:{$this->b->id}", 60);
        $this->assertTrue($other->get());
        $other->release();

        $held->release();
        $this->revert($batch); // and once free, it goes through
    }

    public function test_revert_releases_the_lock_even_when_it_refuses(): void
    {
        $batch = $this->runImport();
        CartItem::factory()->create(['service_id' => $this->serviceOf($batch, 'p1')->id]);

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])->assertExitCode(1);

        $lock = Cache::lock("catalog-import:{$this->a->id}", 5);
        $this->assertTrue($lock->get(), 'the lock must not leak after a refusal');
        $lock->release();
    }

    public function test_import_refuses_while_a_revert_holds_the_lock(): void
    {
        $lock = Cache::lock("catalog-import:{$this->a->id}", 60);
        $lock->get();

        $this->artisan('catalog:import', ['organization' => (string) $this->a->id, 'file' => $this->catalogFile([$this->product()]), '--image-delay' => '0'])
            ->assertExitCode(1);

        $this->assertSame(0, Service::withoutGlobalScope('organization')->where('organization_id', $this->a->id)->count());
        $lock->release();
    }

    /**
     * The state changes AFTER the caller's analyse() (stale view) and BEFORE the transaction:
     * execute() must re-read with locks and refuse instead of cascade-deleting the newcomer.
     */
    public function test_state_that_changes_between_analyse_and_execute_is_refused_not_cascaded(): void
    {
        $kinds = [
            'appointment' => fn (Service $s) => Appointment::withoutEvents(fn () => Appointment::factory()->create([
                'service_id' => $s->id, 'organization_id' => $this->a->id, 'staff_id' => User::factory()->create()->id,
                'vehicle_type_id' => VehicleType::query()->first()?->id ?? VehicleType::factory()->create()->id,
            ])),
            'cart item' => fn (Service $s) => CartItem::factory()->create(['service_id' => $s->id]),
            'order item' => fn (Service $s) => OrderItem::factory()->create(['service_id' => $s->id, 'order_id' => Order::factory()->create(['organization_id' => $this->a->id])->id]),
            'foreign unit' => function (Service $s): void {
                $this->travel(5)->minutes();
                ServiceUnit::create(['organization_id' => $this->a->id, 'service_id' => $s->id, 'location_id' => $this->location($this->a)->id, 'identifier' => 'LATE-'.$s->id]);
            },
        ];

        foreach ($kinds as $label => $change) {
            $batch = $this->runImport(products: [$this->product(['name' => "Wyścig {$label}"])]);
            $service = $this->serviceOf($batch, (string) $batch->items()->where('entity_type', 'service')->value('external_key'));

            $revert = app(RevertCatalogImport::class);
            $options = new RevertOptions;
            $plan = $revert->analyse($batch, $options);
            $this->assertTrue($plan->canExecute(), "{$label}: precondition — the stale analysis says go");

            $change($service);
            $dump = $this->dumpAll();

            try {
                $revert->execute($batch, $options);
                $this->fail("{$label}: execute() went through on a changed state");
            } catch (RevertRefusedException $e) {
                $this->assertFalse($e->plan->canExecute());
            }

            $this->assertSame($dump, $this->dumpAll(), "{$label}: refusal must leave EVERYTHING in place");
            $this->assertSame('completed', $batch->fresh()->status);
            $this->assertNotNull(Cache::lock("catalog-import:{$this->a->id}", 5)->get(), 'lock released');
            Cache::lock("catalog-import:{$this->a->id}")->forceRelease();
            $this->travelBack();
        }
    }

    public function test_the_command_reports_a_changed_state_between_analysis_and_execution(): void
    {
        $batch = $this->runImport();
        $service = $this->serviceOf($batch, 'p1');

        // a revert action that "loses the race": a cart item appears right before the transaction
        $this->app->bind(RevertCatalogImport::class, fn () => new class($service) extends RevertCatalogImport
        {
            public function __construct(private Service $service) {}

            public function execute(CatalogImportBatch $batch, RevertOptions $options): \App\Actions\Catalog\RevertResult
            {
                CartItem::factory()->create(['service_id' => $this->service->id]);

                return parent::execute($batch, $options);
            }
        });
        $count = Service::withoutGlobalScope('organization')->count();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])
            ->expectsConfirmation(self::REVERT_CONFIRM, 'yes')
            ->expectsOutputToContain('stan zmienił się od analizy')
            ->assertExitCode(1);

        $this->assertSame($count, Service::withoutGlobalScope('organization')->count());
    }

    public function test_an_extension_request_hard_blocks_and_is_named_in_the_reasons(): void
    {
        $batch = $this->runImport();
        $service = $this->serviceOf($batch, 'p1');
        $order = Order::factory()->create(['organization_id' => $this->a->id]);
        $item = OrderItem::factory()->create(['service_id' => $service->id, 'order_id' => $order->id]);
        OrderItemExtensionRequest::create([
            'organization_id' => $this->a->id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'status' => 'pending',
            'original_end_date' => now()->addDays(3)->toDateString(), 'requested_end_date' => now()->addDays(5)->toDateString(),
            'additional_days' => 2, 'additional_amount' => 100,
        ]);

        $plan = app(RevertCatalogImport::class)->analyse($batch, new RevertOptions(includeModified: true, discardCartItems: true, partial: true));

        $hard = implode(' | ', $plan->blocked[$service->id]['hard']);
        $this->assertStringContainsString('wnioski o przedłużenie: 1', $hard);
        $this->assertStringContainsString('zamówienia: 1', $hard);
        $this->assertNotContains($service->id, $plan->serviceIds);
    }

    public function test_without_the_flag_auto_increment_is_not_even_evaluated(): void
    {
        $batch = $this->runImport();

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id])
            ->expectsConfirmation(self::REVERT_CONFIRM, 'yes')
            ->doesntExpectOutputToContain('AUTO_INCREMENT')
            ->assertExitCode(0);
    }
}
