<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\RevertCatalogImport;
use App\Actions\Catalog\RevertOptions;
use App\Models\CartItem;
use App\Models\CatalogImportBatch;
use App\Models\CatalogImportItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Second review round, revert side: --force gate, post-commit failures + the retry path,
 * the bounded AUTO_INCREMENT ALTER (decision logic, via seams — the real thing is in the
 * MySQL-gated file), catalog:import-unlock and service_staff accounting.
 */
class CatalogImportRevertPostCommitTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private const CONFIRM = 'To NIEODWRACALNE. Cofnąć import?';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->fakeImageHost();
        $this->org = $this->rentalTenant(['siedziba']);
    }

    private function batch(int $products = 2): CatalogImportBatch
    {
        $list = [];
        foreach (range(1, $products) as $i) {
            $list[] = $this->product(['external_id' => "q{$i}", 'name' => "Produkt {$i}"]);
        }

        $this->artisan('catalog:import', ['organization' => (string) $this->org->id, 'file' => $this->catalogFile($list), '--image-delay' => '0'])->assertExitCode(0);

        return CatalogImportBatch::orderByDesc('id')->firstOrFail();
    }

    /** @return array{0: int, 1: string} */
    private function cli(array $args): array
    {
        $code = Artisan::call('catalog:import-revert', $args);

        return [$code, Artisan::output()];
    }

    private function services()
    {
        return Service::withoutGlobalScope('organization')->where('organization_id', $this->org->id);
    }

    /**
     * @param  array<string, Closure>  $hooks  forgetSitemap(int), removeFile(string): bool, driver(): string,
     *                                         executeAlter(string, int), readCounter(string): ?int,
     *                                         sessionLockWait(): ?int, setSessionLockWait(?int)
     */
    private function bindRevert(array $hooks): \ArrayObject
    {
        $calls = new \ArrayObject;
        $action = new class($hooks, $calls) extends RevertCatalogImport
        {
            public function __construct(private array $hooks, private \ArrayObject $calls) {}

            private function hook(string $name, array $args, Closure $fallback): mixed
            {
                $this->calls[] = $name;

                return isset($this->hooks[$name]) ? ($this->hooks[$name])(...$args) : $fallback();
            }

            protected function forgetSitemap(int $organizationId): void
            {
                $this->hook('forgetSitemap', [$organizationId], fn () => parent::forgetSitemap($organizationId));
            }

            protected function removeFile(string $path): bool
            {
                return $this->hook('removeFile', [$path], fn () => parent::removeFile($path));
            }

            protected function driver(): string
            {
                return $this->hook('driver', [], fn () => parent::driver());
            }

            protected function executeAlter(string $table, int $to): void
            {
                $this->hook('executeAlter', [$table, $to], fn () => parent::executeAlter($table, $to));
            }

            protected function readCounter(string $table): ?int
            {
                return $this->hook('readCounter', [$table], fn () => parent::readCounter($table));
            }

            protected function sessionLockWait(): ?int
            {
                return $this->hook('sessionLockWait', [], fn () => parent::sessionLockWait());
            }

            protected function setSessionLockWait(?int $seconds): void
            {
                $this->calls[] = "setSessionLockWait({$seconds})";
                if (isset($this->hooks['setSessionLockWait'])) {
                    ($this->hooks['setSessionLockWait'])($seconds);
                } else {
                    parent::setSessionLockWait($seconds);
                }
            }
        };

        $this->app->bind(RevertCatalogImport::class, fn () => $action);

        return $calls;
    }

    // ================================================================== 2. --force

    public function test_non_interactive_without_force_is_refused_and_deletes_nothing(): void
    {
        $batch = $this->batch();

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Tryb nieinteraktywny: dodaj --force', $out);
        $this->assertSame(2, $this->services()->count());
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_force_skips_the_prompt_in_both_modes(): void
    {
        $batch = $this->batch(1);
        // interactive + --force: PendingCommand fails on any unexpected question
        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true])->assertExitCode(0);
        $this->assertSame(0, $this->services()->count());

        $batch2 = $this->batch(1);
        [$code] = $this->cli(['batch' => (string) $batch2->id, '--no-interaction' => true, '--force' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(0, $this->services()->count());
    }

    public function test_dry_run_never_needs_force(): void
    {
        $batch = $this->batch();

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true, '--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[DRY-RUN]', $out);
        $this->assertSame(2, $this->services()->count());
    }

    public function test_the_help_text_documents_force(): void
    {
        $definition = Artisan::all()['catalog:import-revert']->getDefinition();

        $this->assertTrue($definition->hasOption('force'));
        $this->assertStringContainsString('nieinterakt', mb_strtolower($definition->getOption('force')->getDescription()));
        $this->assertStringContainsString('nie wymaga --force', $definition->getOption('dry-run')->getDescription());
    }

    // ================================================================== 1. post-commit failures + retry

    public function test_a_failing_post_commit_step_is_a_warning_the_data_is_gone_and_nothing_is_called_a_rollback(): void
    {
        $batch = $this->batch();
        $this->bindRevert(['forgetSitemap' => fn () => throw new RuntimeException('cache down')]);
        Log::spy();

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame(0, $this->services()->count(), 'the DB work is committed');
        $this->assertSame('reverted', $batch->fresh()->status);
        $this->assertStringContainsString('USUNIĘTE', $out);
        $this->assertStringContainsString('mapa strony', mb_strtolower($out));
        $this->assertStringContainsString("php artisan catalog:import-revert {$batch->id}", $out);
        $this->assertStringNotContainsString('rollback', mb_strtolower($out));
        $this->assertStringNotContainsString('nie powiodło', mb_strtolower($out));
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => str_contains($m, 'post-commit') && ($c['step'] ?? null) === 'sitemap' && $c['batch_id'] === $batch->id)->once();
    }

    public function test_files_that_could_not_be_deleted_are_finished_by_the_retry_and_a_further_retry_has_nothing_to_do(): void
    {
        $batch = $this->batch();
        $files = Storage::disk('public')->allFiles();
        $this->assertNotEmpty($files);
        $this->bindRevert(['removeFile' => fn () => throw new RuntimeException('disk read-only')]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('USUNIĘTE', $out);
        $this->assertStringContainsString('pliki', mb_strtolower($out));
        $this->assertCount(count($files), Storage::disk('public')->allFiles(), 'files still on disk');
        $this->assertSame('reverted', $batch->fresh()->status);

        // retry, with the disk healthy again
        $this->app->forgetInstance(RevertCatalogImport::class);
        $this->app->bind(RevertCatalogImport::class, fn () => new RevertCatalogImport);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Paczka #{$batch->id} już cofnięta — dokańczam sprzątanie", $out);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // and once more: nothing left
        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('mapa strony odświeżona', $out);
        $this->assertStringContainsString('usunięto plików 0', $out);
    }

    public function test_kept_files_stay_discoverable_and_the_retry_is_not_fatal(): void
    {
        $batch = $this->batch(1);
        $service = Service::withoutGlobalScope('organization')->firstOrFail();
        $featured = $service->featured_image;
        // a foreign row references the file: the first revert must keep it
        Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'featured_image' => $featured]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);
        $this->assertSame(0, $code, $out);
        Storage::disk('public')->assertExists($featured);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true]);
        $this->assertSame(0, $code, 'retrying a reverted batch must not be fatal');
        $this->assertStringContainsString('już cofnięta', $out);
        Storage::disk('public')->assertExists($featured);
    }

    public function test_the_retry_never_runs_the_deleting_part_even_when_ids_were_recycled(): void
    {
        $batch = $this->batch(1);
        $oldId = (int) $batch->items()->where('entity_type', 'service')->value('entity_id');
        $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->travel(30)->minutes();
        DB::table('services')->insert([
            'id' => $oldId, 'organization_id' => $this->org->id, 'name' => 'Nowy lokator', 'slug' => 'nowy-lokator',
            'service_type' => 'item_rental', 'price' => 1, 'is_active' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertDatabaseHas('services', ['id' => $oldId, 'name' => 'Nowy lokator']);
    }

    public function test_the_retry_can_run_the_auto_increment_step_and_forgets_the_sitemap(): void
    {
        $batch = $this->batch(1);
        $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);
        Cache::put("sitemap:{$this->org->id}", 'stale');

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $lowestId = (int) $batch->items()->where('entity_type', 'service')->min('entity_id');
        $alters = [];

        if (! $isSqlite) {
            // On MySQL the planner's decision runs for real, but the DDL itself is replaced: `ALTER TABLE
            // ... AUTO_INCREMENT` implicitly COMMITs, which RefreshDatabase's wrapping transaction cannot
            // undo -- measured: the organization, location, user and batch of this test stayed in the
            // database for every later test. The real ALTER is covered where it can run safely, by
            // CatalogImportAutoIncrementMysqlTest (DatabaseTruncation, throwaway database).
            $this->bindRevert([
                'executeAlter' => function (string $table, int $to) use (&$alters): void {
                    $alters[] = [$table, $to];
                },
                'readCounter' => fn () => $lowestId,
            ]);
        }

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--reset-auto-increment' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        if ($isSqlite) {
            $this->assertStringContainsString('AUTO_INCREMENT pominięty', $out); // never attempted on SQLite
        } else {
            // the output alone would also read "cofnięty" if the ALTER were never issued (readCounter is stubbed)
            $this->assertContains(['services', $lowestId], $alters, 'executeAlter() was called for services with the lowest id of the batch');
            $this->assertStringContainsString("AUTO_INCREMENT cofnięty: services — licznik = {$lowestId}", $out);
        }
        $this->assertNull(Cache::get("sitemap:{$this->org->id}"));
    }

    public function test_a_partially_reverted_batch_is_not_fatal_on_rerun_and_still_reports_the_blocked_product(): void
    {
        $batch = $this->batch(2);
        $blocked = Service::withoutGlobalScope('organization')->where('name', 'Produkt 1')->firstOrFail();
        CartItem::factory()->create(['service_id' => $blocked->id]);
        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--partial' => true, '--force' => true])->assertExitCode(0);
        $this->assertSame('partially_reverted', $batch->fresh()->status);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true, '--force' => true]);

        $this->assertSame(1, $code, 'the product is still blocked');
        $this->assertStringContainsString('Zablokowane produkty', $out);
        $this->assertDatabaseHas('services', ['id' => $blocked->id]);

        // …and once the blocker is gone the rest of the batch can still be reverted
        CartItem::query()->delete();
        [$code] = $this->cli(['batch' => (string) $batch->id, '--no-interaction' => true, '--force' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(0, $this->services()->count());
        $this->assertSame('reverted', $batch->fresh()->status);
    }

    // ================================================================== 3. bounded ALTER (decision logic via seams)

    public function test_the_alter_runs_under_a_bounded_lock_wait_that_is_restored_afterwards(): void
    {
        config(['catalog_import.auto_increment_lock_wait_seconds' => 7]);
        $batch = $this->batch(1);
        $minServiceId = (int) $batch->items()->where('entity_type', 'service')->min('entity_id');
        $calls = $this->bindRevert([
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 31536000,
            'setSessionLockWait' => fn () => null,
            'executeAlter' => fn () => null,
            'readCounter' => fn (string $table) => $table === 'services' ? $minServiceId : null,
        ]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertSame(0, $code, $out);
        $order = array_values(array_filter($calls->getArrayCopy(), fn ($c) => str_starts_with($c, 'setSessionLockWait') || $c === 'executeAlter' || $c === 'sessionLockWait'));
        $this->assertSame('sessionLockWait', $order[0], 'the previous value is read first');
        $this->assertSame('setSessionLockWait(7)', $order[1]);
        $this->assertSame('executeAlter', $order[2]);
        $this->assertSame('setSessionLockWait(31536000)', $order[3], 'and restored right after the ALTER');
    }

    public function test_a_lock_wait_timeout_is_a_refusal_with_a_retry_hint_not_an_exception(): void
    {
        $batch = $this->batch(1);
        $this->bindRevert([
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 50,
            'setSessionLockWait' => fn () => null,
            'executeAlter' => function (string $table): void {
                $pdo = new \PDOException('Lock wait timeout exceeded; try restarting transaction');
                $pdo->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
                throw new QueryException('mysql', "ALTER TABLE `{$table}`", [], $pdo);
            },
        ]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("tabela zajęta — ponów: catalog:import-revert {$batch->id} --cleanup-only --reset-auto-increment", $out);
        $this->assertSame(0, $this->services()->count(), 'the revert itself is done');
    }

    public function test_the_reported_counter_is_the_real_one_and_a_clamp_is_not_passed_off_as_success(): void
    {
        $batch = $this->batch(1);
        $this->bindRevert([
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 50,
            'setSessionLockWait' => fn () => null,
            'executeAlter' => fn () => null,
            'readCounter' => fn () => 9999, // MySQL clamped to MAX(id)+1
        ]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('9999', $out);
        $this->assertStringContainsString('przycięt', mb_strtolower($out));
        $this->assertStringNotContainsString('AUTO_INCREMENT cofnięty: services — ustawiono', $out);
    }

    public function test_a_non_timeout_alter_failure_is_a_step_warning_never_a_post_commit_throw(): void
    {
        $batch = $this->batch(1);
        $this->bindRevert([
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 50,
            'setSessionLockWait' => fn () => null,
            'executeAlter' => fn () => throw new RuntimeException('connection gone'),
        ]);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('USUNIĘTE', $out);
        $this->assertStringContainsString('auto_increment', mb_strtolower($out));
        $this->assertSame('reverted', $batch->fresh()->status);
    }

    // ================================================================== 5. catalog:import-unlock

    public function test_unlock_force_releases_the_lock_and_logs_a_warning(): void
    {
        Log::spy();
        $held = Cache::lock("catalog-import:{$this->org->id}", 600);
        $this->assertTrue($held->get());

        [$code, $out] = $this->unlock(['organization' => (string) $this->org->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('równoległe', mb_strtolower($out));
        $again = Cache::lock("catalog-import:{$this->org->id}", 5);
        $this->assertTrue($again->get(), 'the lock is free');
        $again->release();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => str_contains($m, 'catalog:import-unlock') && ($c['org_id'] ?? null) === $this->org->id)->once();
    }

    public function test_unlock_non_interactive_without_force_is_refused(): void
    {
        $held = Cache::lock("catalog-import:{$this->org->id}", 600);
        $held->get();

        [$code, $out] = $this->unlock(['organization' => (string) $this->org->id, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Tryb nieinteraktywny: dodaj --force', $out);
        $this->assertFalse(Cache::lock("catalog-import:{$this->org->id}", 5)->get(), 'still held');
        $held->release();
    }

    public function test_unlock_interactive_asks_first(): void
    {
        Cache::lock("catalog-import:{$this->org->id}", 600)->get();

        $this->artisan('catalog:import-unlock', ['organization' => (string) $this->org->id])
            ->expectsConfirmation('Zwolnić blokadę? Jeśli import lub cofnięcie tej organizacji nadal trwa, zaczną się równolegle.', 'no')
            ->assertExitCode(1);
        $this->assertFalse(Cache::lock("catalog-import:{$this->org->id}", 5)->get());

        $this->artisan('catalog:import-unlock', ['organization' => (string) $this->org->id])
            ->expectsConfirmation('Zwolnić blokadę? Jeśli import lub cofnięcie tej organizacji nadal trwa, zaczną się równolegle.', 'yes')
            ->assertExitCode(0);
        $free = Cache::lock("catalog-import:{$this->org->id}", 5);
        $this->assertTrue($free->get());
        $free->release();
    }

    public function test_unlock_only_touches_the_named_organization(): void
    {
        $other = $this->rentalTenant(['x']);
        $mine = Cache::lock("catalog-import:{$this->org->id}", 600);
        $theirs = Cache::lock("catalog-import:{$other->id}", 600);
        $mine->get();
        $theirs->get();

        $this->unlock(['organization' => (string) $this->org->id, '--force' => true, '--no-interaction' => true]);

        $this->assertFalse(Cache::lock("catalog-import:{$other->id}", 5)->get(), 'the other organization stays locked');
        $theirs->release();
    }

    /** @return array{0: int, 1: string} */
    private function unlock(array $args): array
    {
        $code = Artisan::call('catalog:import-unlock', $args);

        return [$code, Artisan::output()];
    }

    // ================================================================== 9. service_staff

    public function test_staff_assignments_of_batch_products_are_counted_shown_and_removed_but_never_block(): void
    {
        $batch = $this->batch(1);
        $mine = Service::withoutGlobalScope('organization')->where('name', 'Produkt 1')->firstOrFail();
        $foreign = Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'name' => 'Cudzy', 'slug' => 'cudzy']);
        $mine->staff()->attach([User::factory()->create()->id, User::factory()->create()->id]);
        $foreign->staff()->attach(User::factory()->create()->id);

        $plan = app(RevertCatalogImport::class)->analyse($batch, new RevertOptions);
        $this->assertSame(2, $plan->staffAssignments);
        $this->assertTrue($plan->canExecute(), 'staff assignments are product data, not a block');

        [, $dry] = $this->cli(['batch' => (string) $batch->id, '--dry-run' => true]);
        $this->assertStringContainsString('przypisania pracowników: 2', $dry);

        [$code, $out] = $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('przypisania pracowników: 2', $out);
        $this->assertSame(0, DB::table('service_staff')->where('service_id', $mine->id)->count());
        $this->assertSame(1, DB::table('service_staff')->where('service_id', $foreign->id)->count(), 'a non-batch product keeps its assignment');
    }

    public function test_product_names_in_the_revert_plan_cannot_inject_console_markup(): void
    {
        $batch = $this->batch(1);
        $service = Service::withoutGlobalScope('organization')->firstOrFail();
        CartItem::factory()->create(['service_id' => $service->id]); // makes it appear in the blocked table
        DB::table('services')->where('id', $service->id)->update(['name' => "<error>X</error>\e[31m"]);

        [, $out] = $this->cli(['batch' => (string) $batch->id, '--dry-run' => true]);

        $this->assertStringNotContainsString("\e", $out);
        $this->assertStringContainsString('<error>X</error>', $out);
    }

    public function test_leftover_file_items_carry_enough_to_be_found_again(): void
    {
        $batch = $this->batch(1);
        $this->bindRevert(['removeFile' => fn () => false]);

        $this->cli(['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $left = CatalogImportItem::where('batch_id', $batch->id)->where('entity_type', 'file')->get()
            ->filter(fn ($f) => empty($f->snapshot['file_removed_at'] ?? null));
        $this->assertCount(2, $left, 'a file that could not be removed is not marked as removed');
    }
}
