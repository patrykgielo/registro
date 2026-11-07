<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\CatalogImportBatch;
use App\Models\Service;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * MySQL ONLY — skipped on SQLite (the default test run) and on any connection that is not
 * explicitly a throwaway MySQL.
 *
 * Why DatabaseTruncation (like tests/Concurrency/CartCheckoutRaceTest) and not RefreshDatabase
 * or DatabaseMigrations: `ALTER TABLE ... AUTO_INCREMENT` is DDL and implicitly COMMITs, which
 * would defeat RefreshDatabase's wrapping transaction; DatabaseMigrations rolls back at teardown,
 * and `2026_08_30_150000_normalize_empty_body_html_to_null` deliberately refuses to be rolled back.
 * DatabaseTruncation runs migrate:fresh ONCE and TRUNCATEs between tests (which also resets
 * AUTO_INCREMENT — every test here seeds the ids it depends on explicitly). It must NEVER be
 * pointed at the dev database; run it against a throwaway container only, e.g.
 *
 *   CATALOG_MYSQL_TESTS=1 DB_CONNECTION=mysql DB_HOST=<throwaway> DB_DATABASE=<throwaway> \
 *     php artisan test tests/Feature/Catalog/CatalogImportAutoIncrementMysqlTest.php
 */
class CatalogImportAutoIncrementMysqlTest extends TestCase
{
    use CatalogTestHelpers, DatabaseTruncation;

    /** @var array<int, string> */
    protected array $connectionsToTruncate = ['mysql'];

    protected function setUp(): void
    {
        // Before parent::setUp(): DatabaseTruncation would run migrate:fresh in setUp.
        if (getenv('DB_CONNECTION') !== 'mysql' || getenv('CATALOG_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('MySQL-only (needs a throwaway MySQL and CATALOG_MYSQL_TESTS=1) — see the class docblock.');
        }

        // migrate:fresh is about to run: refuse anything that does not look like a throwaway database.
        $host = (string) getenv('DB_HOST');

        if (in_array($host, ['mysql', 'registro-mysql', '127.0.0.1', 'localhost', ''], true)
            || ! preg_match('/test|throwaway|tmp/i', (string) getenv('DB_DATABASE'))) {
            throw new \RuntimeException("Refusing to run migrate:fresh against host={$host} database=".getenv('DB_DATABASE').' — not a throwaway database.');
        }

        parent::setUp();

        $this->setUpCatalog();
        $this->fakeImageHost();
    }

    private function autoIncrement(string $table): int
    {
        DB::statement('SET SESSION information_schema_stats_expiry = 0');

        return (int) DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('AUTO_INCREMENT');
    }

    private function import(): CatalogImportBatch
    {
        $org = $this->rentalTenant();
        $this->artisan('catalog:import', [
            'organization' => (string) $org->id,
            'file' => $this->catalogFile([$this->product(), $this->product()]),
            '--image-delay' => '0',
        ])->assertExitCode(0);

        return CatalogImportBatch::firstOrFail();
    }

    public function test_counter_goes_back_to_the_lowest_batch_id_when_nothing_at_or_above_it_remains(): void
    {
        $batch = $this->import();
        $min = (int) $batch->items()->where('entity_type', 'service')->min('entity_id');
        $this->assertGreaterThan($min, $this->autoIncrement('services'));

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--reset-auto-increment' => true, '--no-interaction' => true])
            ->assertExitCode(0);

        $this->assertSame($min, $this->autoIncrement('services'));
        $this->assertSame(
            (int) $batch->items()->where('entity_type', 'rental_category')->where('origin', 'created')->min('entity_id'),
            $this->autoIncrement('rental_categories')
        );
    }

    public function test_counter_is_left_alone_when_a_later_row_exists_in_the_table(): void
    {
        $batch = $this->import();
        $foreign = Service::factory()->itemRental()->create(); // gets an id ABOVE the batch's
        $before = $this->autoIncrement('services');

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--reset-auto-increment' => true, '--no-interaction' => true])
            ->expectsOutputToContain('AUTO_INCREMENT pominięty: services')
            ->assertExitCode(0);

        $this->assertSame($before, $this->autoIncrement('services'));
        $this->assertDatabaseHas('services', ['id' => $foreign->id]);
    }

    public function test_service_units_are_skipped_while_audit_logs_still_point_at_their_ids(): void
    {
        $org = $this->rentalTenant();
        $this->artisan('catalog:import', [
            'organization' => (string) $org->id,
            'file' => $this->catalogFile([$this->product(['stock' => null, 'units' => [['identifier' => 'AI-1']]])]),
            '--image-delay' => '0',
        ])->assertExitCode(0);
        $batch = CatalogImportBatch::firstOrFail();
        $before = $this->autoIncrement('service_units');

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--reset-auto-increment' => true, '--no-interaction' => true])
            ->expectsOutputToContain('AUTO_INCREMENT pominięty: service_units')
            ->assertExitCode(0);

        $this->assertSame($before, $this->autoIncrement('service_units'));
    }

    /**
     * W1/W2 (REPEATABLE READ): SQLite compiles `FOR UPDATE` away, so only a real MySQL can show
     * that the revert actually ISSUES the locking reads, in the documented order:
     *   batch row -> batch services (ORDER BY id, FOR UPDATE) -> dependents (FOR UPDATE)
     *   -> candidate categories (FOR UPDATE) -> "still used?" read (FOR UPDATE) -> category DELETE.
     * This proves the statements and their order. It does NOT replay the race itself (a second
     * connection committing a child row mid-revert) — that needs the two-process harness of
     * tests/Concurrency and is not provided here.
     */
    public function test_revert_issues_its_locking_reads_in_the_documented_order(): void
    {
        $batch = $this->import();

        // Statements AND transaction boundaries, in order. The command runs its own (non-locking)
        // analyse() BEFORE the transaction; only the statements INSIDE it are the locking ones.
        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log): void {
            $log[] = '@@BEGIN';
        });
        Event::listen(TransactionCommitted::class, function () use (&$log): void {
            $log[] = '@@COMMIT';
        });
        DB::listen(function ($q) use (&$log): void {
            $log[] = strtolower($q->sql);
        });

        $this->artisan('catalog:import-revert', ['batch' => (string) $batch->id, '--no-interaction' => true])->assertExitCode(0);

        $begin = array_search('@@BEGIN', $log, true);
        $commit = array_search('@@COMMIT', $log, true);
        $this->assertNotFalse($begin);
        $this->assertNotFalse($commit);
        $inside = array_slice($log, $begin + 1, $commit - $begin - 1);

        if (getenv('CATALOG_DUMP_SQL') === '1') {
            fwrite(STDERR, '
[revert SQL, inside the transaction]
'.implode('
', array_map(fn ($s, $i) => sprintf('%02d  %s', $i, $s), $inside, array_keys($inside))).'
');
        }

        $at = function (string $needle, int $from = 0) use ($inside): int|false {
            foreach ($inside as $i => $statement) {
                if ($i >= $from && str_contains($statement, $needle)) {
                    return $i;
                }
            }

            return false;
        };
        $isLocking = fn (int $i) => str_ends_with(trim($inside[$i]), 'for update');

        // 1. the batch row, then the batch's services in id order — both locking
        $batchLock = $at('from `catalog_import_batches` where `catalog_import_batches`.`id` =');
        $this->assertNotFalse($batchLock, 'batch row read');
        $this->assertTrue($isLocking($batchLock), 'the batch row must be locked');

        $servicesLock = $at('from `services` where `id` in');
        $this->assertNotFalse($servicesLock);
        $this->assertGreaterThan($batchLock, $servicesLock);
        $this->assertStringContainsString('order by `id` asc for update', $inside[$servicesLock], 'services are locked in id order');

        // no consistent (plain) read at all before that lock — it would pin the REPEATABLE READ snapshot
        foreach (array_slice($inside, 0, $servicesLock, true) as $i => $statement) {
            $this->assertTrue($isLocking($i), "statement #{$i} before the services lock is a plain read: {$statement}");
        }

        // 2. nothing the decisions rest on is read BEFORE that lock: the first read of any
        //    dependent table comes after it, and every one of them is a locking read
        foreach (['order_items', 'rentals', 'appointments', 'cart_items'] as $table) {
            $read = $at("from `{$table}` where `service_id` in");
            $this->assertNotFalse($read, $table);
            $this->assertGreaterThan($servicesLock, $read, "{$table} must be read AFTER the services are locked");
            $this->assertTrue($isLocking($read), "{$table} must be a locking read");
        }

        foreach (['service_units', 'service_location_stocks'] as $table) {
            $foreign = $at("from `{$table}` where `service_id` in");
            $this->assertNotFalse($foreign, "{$table} foreign-row read");
            $this->assertTrue($isLocking($foreign), "{$table} foreign-row read must be locking");
        }

        // 3. deletes, then categories: lock rows -> locking usage check -> delete
        $deleteServices = $at('delete from `services`');
        $categoryLock = $at('from `rental_categories` where `id` in', $servicesLock);
        $stillUsed = $at('from `services` where `rental_category_id` =');
        $categoryDelete = $at('delete from `rental_categories`');

        $this->assertNotFalse($deleteServices);
        $this->assertNotFalse($categoryLock);
        $this->assertNotFalse($stillUsed);
        $this->assertNotFalse($categoryDelete);
        $this->assertTrue($isLocking($categoryLock), 'candidate categories are locked');
        $this->assertGreaterThan($deleteServices, $stillUsed, 'the usage check runs after the services are deleted');
        $this->assertGreaterThan($categoryLock, $stillUsed, 'candidate categories are locked BEFORE the usage check');
        $this->assertTrue($isLocking($stillUsed), 'the usage check must be a locking read');
        $this->assertGreaterThan($stillUsed, $categoryDelete);
    }
}
