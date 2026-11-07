<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ImportOptions;
use App\Actions\Catalog\RevertCatalogImport;
use App\Models\CartItem;
use App\Models\CatalogImportBatch;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Support\Catalog\CatalogProductValidator;
use Illuminate\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Third review round: lock release / commit failures can never mislabel a committed run,
 * --cleanup-only, heartbeat semantics, real lock expiry, strict location lookup, folding.
 */
class CatalogImportRound3Test extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private const CONFIRM = 'To NIEODWRACALNE. Cofnąć import?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCatalog();
        $this->fakeImageHost();
    }

    private function services(Organization $org)
    {
        return Service::withoutGlobalScope('organization')->where('organization_id', $org->id);
    }

    /** @return array{0: int, 1: string} */
    private function cli(string $command, array $args): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    /** @param  array<string, \Closure>  $hooks */
    private function bindImport(array $hooks): SeamedImport
    {
        $action = new SeamedImport(app(CatalogProductValidator::class), $hooks);
        $this->app->bind(\App\Actions\Catalog\ImportCatalog::class, fn () => $action);

        return $action;
    }

    /** @param  array<string, \Closure>  $hooks */
    private function bindRevert(array $hooks): SeamedRevert
    {
        $action = new SeamedRevert($hooks);
        $this->app->bind(RevertCatalogImport::class, fn () => $action);

        return $action;
    }

    private function importCmd(Organization $org, array $products): array
    {
        return $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile($products), '--image-delay' => '0']);
    }

    private function batch(Organization $org, int $n = 2): CatalogImportBatch
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $list[] = $this->product(['external_id' => "r{$i}", 'name' => "Produkt {$i}"]);
        }
        [$code] = $this->importCmd($org, $list);
        $this->assertSame(0, $code);

        return CatalogImportBatch::orderByDesc('id')->firstOrFail();
    }

    /** A lock delegating to a real one, but whose refresh() misbehaves. */
    private function lockWithRefresh(Lock $inner, \Closure $refresh): Lock
    {
        return new class($inner, $refresh) extends Lock
        {
            public function __construct(private Lock $inner, private \Closure $refresh)
            {
                parent::__construct('wrapped', 60);
            }

            public function acquire()
            {
                return $this->inner->get();
            }

            public function release()
            {
                return $this->inner->release();
            }

            protected function getCurrentOwner()
            {
                return $this->inner->owner();
            }

            public function forceRelease()
            {
                $this->inner->forceRelease();
            }

            public function refresh($seconds = null)
            {
                return ($this->refresh)($seconds);
            }
        };
    }

    // ================================================================== A. release / commit can never mislabel a committed run

    public function test_a_lock_release_that_throws_after_the_revert_committed_is_a_warning_not_a_rollback(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $this->bindRevert(['releaseLock' => fn () => throw new RuntimeException('MySQL server has gone away')]);
        Log::spy();

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame('reverted', $batch->fresh()->status);
        $this->assertStringContainsString('USUNIĘTE', $out);
        $this->assertStringContainsString('catalog:import-unlock', $out);
        $this->assertStringNotContainsString('rollback', mb_strtolower($out));
        $this->assertStringNotContainsString('nie usunięto', mb_strtolower($out));
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => str_contains($m, 'post-commit') && ($c['step'] ?? null) === 'lock')->once();
    }

    public function test_a_commit_that_fails_after_the_rows_were_deleted_is_reported_as_committed(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $this->bindRevert(['runRevertTransaction' => function (\Closure $cb, \Closure $real) {
            $real();                                          // the transaction really commits…
            throw new RuntimeException('connection lost');    // …and the connection dies on the way back
        }]);

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertSame(0, $this->services($org)->count());
        $this->assertStringContainsString('ZATWIERDZONE', $out);
        $this->assertStringContainsString("catalog:import-revert {$batch->id} --cleanup-only", $out);
        $this->assertStringContainsString('catalog:import-status', $out);
        $this->assertStringNotContainsString('rollback', mb_strtolower($out));
        $this->assertStringNotContainsString('nic nie usunięto', mb_strtolower($out));
    }

    public function test_a_commit_that_really_failed_is_still_a_rollback(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $this->bindRevert(['runRevertTransaction' => fn () => throw new RuntimeException('deadlock found')]);

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertSame(2, $this->services($org)->count());
        $this->assertStringContainsString('rollback', mb_strtolower($out));
    }

    public function test_when_the_fresh_read_cannot_rule_out_the_commit_the_state_is_reported_unknown(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $this->bindRevert([
            'runRevertTransaction' => function (\Closure $cb, \Closure $real) {
                $real();
                throw new RuntimeException('connection lost');
            },
            'freshBatchStatus' => fn () => throw new RuntimeException('still down'),
        ]);

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('stan nieznany', $out);
        $this->assertStringContainsString('catalog:import-status', $out);
        $this->assertStringNotContainsString('rollback', mb_strtolower($out));
        $this->assertStringNotContainsString('nic nie usunięto', mb_strtolower($out));
    }

    public function test_a_lock_release_that_throws_after_the_import_committed_is_a_warning(): void
    {
        $org = $this->rentalTenant();
        $this->bindImport(['releaseLock' => fn () => throw new RuntimeException('MySQL server has gone away')]);
        Log::spy();

        [$code, $out] = $this->importCmd($org, [$this->product()]);

        $this->assertSame(0, $code, $out);
        $this->assertSame(1, CatalogImportBatch::count());
        $this->assertStringContainsString('Gotowe', $out);
        $this->assertStringContainsString('catalog:import-unlock', $out);
        $this->assertStringNotContainsString('nic nie zapisano', $out);
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'lock release failed'))->once();
    }

    public function test_a_lock_release_that_throws_while_a_committed_exception_is_in_flight_does_not_replace_it(): void
    {
        $org = $this->rentalTenant();
        $this->bindImport([
            'afterCommit' => fn () => throw new RuntimeException('connection lost after commit'),
            'releaseLock' => fn () => throw new RuntimeException('MySQL server has gone away'),
        ]);

        [$code, $out] = $this->importCmd($org, [$this->product()]);

        $this->assertSame(1, $code);
        $this->assertSame(1, CatalogImportBatch::count());
        $this->assertNotEmpty(\Illuminate\Support\Facades\Storage::disk('public')->allFiles());
        $this->assertStringContainsString('zatwierdz', $out);
        $this->assertStringContainsString('catalog:import-status', $out);
        $this->assertStringNotContainsString('nic nie zapisano', $out);
    }

    // ================================================================== B. --cleanup-only, retry commands that do what they say

    public function test_a_plain_rerun_on_a_reverted_batch_still_forgets_the_sitemap(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $this->bindRevert(['forgetSitemap' => fn () => throw new RuntimeException('cache down')]);
        $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->app->bind(RevertCatalogImport::class, fn () => new RevertCatalogImport);
        Cache::put("sitemap:{$org->id}", 'stale');

        // no leftover files, no --reset-auto-increment: the old code printed "nic do sprzątania" and did nothing
        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertNull(Cache::get("sitemap:{$org->id}"), 'the sitemap retry must actually forget the sitemap');
        $this->assertStringContainsString('mapa strony odświeżona', $out);
    }

    public function test_every_post_commit_retry_hint_uses_cleanup_only(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $this->bindRevert([
            'forgetSitemap' => fn () => throw new RuntimeException('cache down'),
            'removeFile' => fn () => false,
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 50,
            'setSessionLockWait' => fn () => null,
            'executeAlter' => fn () => throw new RuntimeException('connection gone'),
        ]);

        [, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertStringContainsString("catalog:import-revert {$batch->id} --cleanup-only", $out);
        $this->assertStringContainsString("catalog:import-revert {$batch->id} --cleanup-only --reset-auto-increment", $out);
        $this->assertStringNotContainsString("catalog:import-revert {$batch->id}.", $out, 'no bare hint left');
    }

    public function test_cleanup_only_on_a_partially_reverted_batch_deletes_no_rows_asks_for_no_force_and_cleans_files(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $blocked = $this->services($org)->where('name', 'Produkt 1')->firstOrFail();
        CartItem::factory()->create(['service_id' => $blocked->id]);

        // --partial reverts Produkt 2; its files could not be removed
        $this->bindRevert(['removeFile' => fn () => false]);
        $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--partial' => true, '--force' => true, '--no-interaction' => true]);
        $this->assertSame('partially_reverted', $batch->fresh()->status);
        $left = \Illuminate\Support\Facades\Storage::disk('public')->allFiles();
        $this->assertNotEmpty($left);

        $this->app->bind(RevertCatalogImport::class, fn () => new RevertCatalogImport);
        $before = $this->services($org)->count();

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--cleanup-only' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame($before, $this->services($org)->count(), 'cleanup-only never deletes rows');
        $this->assertDatabaseHas('services', ['id' => $blocked->id]);
        $this->assertStringNotContainsString('Zablokowane produkty', $out, 'cleanup-only never analyses');
        $this->assertStringContainsString('dokańczam sprzątanie', $out);
        // the blocked product keeps ITS files; only the reverted product's files were removed
        $this->assertLessThan(count($left), count(\Illuminate\Support\Facades\Storage::disk('public')->allFiles()));
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($blocked->featured_image);
    }

    public function test_cleanup_only_is_refused_on_a_batch_that_was_never_reverted(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--cleanup-only' => true, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--cleanup-only', $out);
        $this->assertSame(2, $this->services($org)->count());
        $this->assertNotEmpty(\Illuminate\Support\Facades\Storage::disk('public')->allFiles());
    }

    public function test_a_plain_rerun_on_a_blocked_partial_batch_refuses_truthfully_and_does_not_clean_implicitly(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org);
        $blocked = $this->services($org)->where('name', 'Produkt 1')->firstOrFail();
        CartItem::factory()->create(['service_id' => $blocked->id]);
        $this->bindRevert(['removeFile' => fn () => false]);
        $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--partial' => true, '--force' => true, '--no-interaction' => true]);
        $this->app->bind(RevertCatalogImport::class, fn () => new RevertCatalogImport);
        $files = \Illuminate\Support\Facades\Storage::disk('public')->allFiles();

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('pozostałe produkty zablokowane — nic z nich nie usunięto', $out);
        $this->assertStringContainsString("catalog:import-revert {$batch->id} --cleanup-only", $out);
        $this->assertSame($files, \Illuminate\Support\Facades\Storage::disk('public')->allFiles(), 'no implicit file deletion in a command that says it deleted nothing');
    }

    // ================================================================== C. heartbeat, real lock expiry, config, scratch

    public function test_a_database_error_during_the_heartbeat_means_the_lock_is_lost_not_alive(): void
    {
        $org = $this->rentalTenant();
        $action = new SeamedImport(app(CatalogProductValidator::class), [
            'makeLock' => fn (int $orgId, int $seconds) => $this->lockWithRefresh(Cache::lock("catalog-import:{$orgId}", $seconds), function (): never {
                $pdo = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                $pdo->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
                throw new QueryException('mysql', 'update `cache_locks`', [], $pdo);
            }),
        ]);

        $result = $action->run($org, $this->catalogFile([$this->product()]), new ImportOptions(imageDelayMs: 0));

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('blokad', json_encode($result->report->errors(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->services($org)->count());
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('public')->allFiles());
    }

    public function test_a_store_that_genuinely_cannot_refresh_locks_is_tolerated(): void
    {
        $org = $this->rentalTenant();
        $action = new SeamedImport(app(CatalogProductValidator::class), [
            'makeLock' => fn (int $orgId, int $seconds) => $this->lockWithRefresh(Cache::lock("catalog-import:{$orgId}", $seconds), function (): never {
                throw new RuntimeException('This lock driver does not support refreshing locks.');
            }),
        ]);

        $result = $action->run($org, $this->catalogFile([$this->product()]), new ImportOptions(imageDelayMs: 0));

        $this->assertTrue($result->ok(), json_encode($result->report->errors()));
        $this->assertSame(1, $this->services($org)->count());
    }

    /** @return array<int, int> seconds left on the REAL lock in the store, sampled during each image request */
    private function sampleRealLockTtl(Organization $org, array $configOverrides = []): array
    {
        config($configOverrides);
        $samples = [];
        Http::fake(['cap.example.com/*' => function () use (&$samples, $org) {
            $lock = Cache::store()->getStore()->locks["catalog-import:{$org->id}"] ?? null;
            $samples[] = $lock === null ? -1 : (int) now()->diffInSeconds($lock['expiresAt'], false);

            return Http::response($this->png(), 200);
        }]);

        $result = app(\App\Actions\Catalog\ImportCatalog::class)->run($org, $this->catalogFile([
            $this->product(['featured_image' => ['url' => 'https://cap.example.com/1.png'], 'gallery' => [['url' => 'https://cap.example.com/2.png']]]),
        ]), new ImportOptions(imageDelayMs: 0));

        $this->assertTrue($result->ok(), json_encode($result->report->errors()));

        return $samples;
    }

    public function test_the_real_lock_in_the_store_outlives_budget_plus_write_allowance_and_is_refreshed(): void
    {
        $org = $this->rentalTenant();

        $samples = $this->sampleRealLockTtl($org, ['catalog_import.image_phase_max_seconds' => 900]);

        $this->assertCount(2, $samples);
        foreach ($samples as $left) {
            $this->assertGreaterThanOrEqual(900 + 3600 - 10, $left, 'the store holds the lock for budget + 1 h, not for a token 60 s');
        }
    }

    public function test_non_positive_budget_config_falls_back_to_the_documented_defaults(): void
    {
        $org = $this->rentalTenant();

        $samples = $this->sampleRealLockTtl($org, [
            'catalog_import.image_phase_max_seconds' => 0,
            'catalog_import.image_phase_max_bytes' => -5,
        ]);

        $this->assertNotEmpty($samples);
        $this->assertGreaterThanOrEqual(4490, min($samples), '0 seconds means the 900 s default, not "no time at all"');
        $this->assertSame(1, $this->services($org)->count(), 'a <= 0 byte budget must not reject every image');
    }

    public function test_the_stale_scratch_threshold_follows_the_lock_ttl(): void
    {
        config(['catalog_import.image_phase_max_seconds' => 20000]); // lock TTL 23600 s => threshold 47200 s (13.1 h)
        $org = $this->rentalTenant();
        $root = storage_path('app/tmp/catalog-import');
        File::ensureDirectoryExists($root.'/seven-hours');
        File::ensureDirectoryExists($root.'/fourteen-hours');
        touch($root.'/seven-hours', time() - 7 * 3600);
        touch($root.'/fourteen-hours', time() - 14 * 3600);

        $this->cli('catalog:import', ['organization' => (string) $org->id, 'file' => $this->catalogFile([$this->product()]), '--dry-run' => true, '--no-image-check' => true]);

        $this->assertDirectoryExists($root.'/seven-hours', 'younger than 2 x the lock TTL: a live run could still own it');
        $this->assertDirectoryDoesNotExist($root.'/fourteen-hours');
        File::deleteDirectory($root.'/seven-hours');
    }

    // ================================================================== D. small items

    public function test_the_status_list_does_not_print_raw_file_names(): void
    {
        $org = $this->rentalTenant();
        CatalogImportBatch::create([
            'organization_id' => $org->id, 'source_filename' => "<error>x</error>\e[31m.json", 'source_sha256' => str_repeat('a', 64),
            'status' => 'completed', 'imported_at' => now(),
        ]);

        [$code, $out] = $this->cli('catalog:import-status', []);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString("\e", $out);
        $this->assertStringContainsString('<error>x</error>', $out);
    }

    public function test_unit_and_stock_locations_are_matched_by_exact_string_not_loosely(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $five = Location::factory()->for($org, 'organization')->create(['slug' => '5', 'name' => 'Pięć']);
        $zeroFive = Location::factory()->for($org, 'organization')->create(['slug' => '05', 'name' => 'Zero-pięć']);
        $ten = Location::factory()->for($org, 'organization')->create(['slug' => '10', 'name' => 'Dziesięć']);
        $sci = Location::factory()->for($org, 'organization')->create(['slug' => '1e1', 'name' => 'Naukowy']);

        [$code, $out] = $this->importCmd($org, [
            $this->product(['external_id' => 'u', 'name' => 'Z jednostkami', 'stock' => null, 'units' => [
                ['identifier' => 'U-05', 'location' => '05'],
                ['identifier' => 'U-1e1', 'location' => '1e1'],
                ['identifier' => 'U-5', 'location' => '5'],
            ]]),
            $this->product(['external_id' => 's', 'name' => 'Ze stanem', 'stock' => ['locations' => ['05' => 2, '1e1' => 3]]]),
        ]);

        $this->assertSame(0, $code, $out);
        $at = fn (string $identifier) => ServiceUnit::withoutGlobalScope('organization')->where('identifier', $identifier)->value('location_id');
        $this->assertSame($zeroFive->id, $at('U-05'));
        $this->assertSame($sci->id, $at('U-1e1'));
        $this->assertSame($five->id, $at('U-5'));

        $stock = Service::withoutGlobalScope('organization')->where('name', 'Ze stanem')->firstOrFail();
        $rows = \App\Models\ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $stock->id)->pluck('quantity', 'location_id')->all();
        $this->assertSame(2, $rows[$zeroFive->id]);
        $this->assertSame(3, $rows[$sci->id]);
        $this->assertSame(0, $rows[$five->id]);
        $this->assertSame(0, $rows[$ten->id]);
    }

    public function test_a_counter_that_cannot_be_read_after_a_successful_alter_is_not_reported_as_an_alter_failure(): void
    {
        $org = $this->rentalTenant();
        $batch = $this->batch($org, 1);
        $this->bindRevert([
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 50,
            'setSessionLockWait' => fn () => null,
            'executeAlter' => fn () => null,
            'readCounter' => fn () => throw new RuntimeException('information_schema unavailable'),
        ]);

        [$code, $out] = $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ALTER wykonany; nie udało się odczytać licznika', $out);
        $this->assertStringNotContainsString('błąd ALTER', $out);
        $this->assertStringContainsString('AUTO_INCREMENT cofnięty: services', $out);
    }

    public function test_a_non_positive_lock_wait_config_uses_the_default_not_zero(): void
    {
        config(['catalog_import.auto_increment_lock_wait_seconds' => 0]);
        $org = $this->rentalTenant();
        $batch = $this->batch($org, 1);
        $seen = [];
        $this->bindRevert([
            'driver' => fn () => 'mysql',
            'sessionLockWait' => fn () => 50,
            'setSessionLockWait' => function (?int $s) use (&$seen): void {
                $seen[] = $s;
            },
            'executeAlter' => fn () => null,
            'readCounter' => fn () => null,
        ]);

        $this->cli('catalog:import-revert', ['batch' => (string) $batch->id, '--force' => true, '--no-interaction' => true, '--reset-auto-increment' => true]);

        $this->assertSame(5, $seen[0], 'lock_wait_timeout = 0 would mean "do not wait at all"/unbounded depending on the server');
        $this->assertContains(50, $seen, 'and the previous value is restored');
    }

    public function test_fold_does_not_create_false_duplicates_for_non_latin_names_but_keeps_latin_accent_folding(): void
    {
        $org = $this->rentalTenant();
        $validate = function (array $overrides) use ($org): array {
            $products = [];
            foreach ($overrides as $i => $o) {
                $products[] = $this->product(['external_id' => "e{$i}", 'slug' => "s-{$i}"] + $o);
            }

            return array_map(fn ($e) => $e['index'].':'.$e['field'], (new CatalogProductValidator)->validate($products, $org)->errors());
        };

        // two DIFFERENT emoji-only / CJK names (and external ids) are different products
        $this->assertSame([], $validate([['name' => '🔧'], ['name' => '🔨']]));
        $this->assertSame([], $validate([['name' => '鉄槌'], ['name' => '金槌']]));
        $this->assertSame([], $validate([['external_id' => '🔧'], ['external_id' => '🔨']]));
        // …identical ones still collide
        $this->assertContains('1:name', $validate([['name' => '鉄槌'], ['name' => ' 鉄槌 ']]));
        // Latin accents keep folding like utf8mb4_unicode_ci
        $this->assertContains('1:name', $validate([['name' => 'Zażółć'], ['name' => 'zazolc']]));
        $this->assertContains('1:name', $validate([['name' => 'Młot 18V +'], ['name' => 'mlot 18v +']]));
    }
}
