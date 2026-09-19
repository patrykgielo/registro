<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use RuntimeException;
use Tests\TestCase;

/**
 * Code review follow-up (2026-09-19, ClickUp 123k99cvc54): proves
 * App\Observers\ServiceUnitObserver::materializePlaceholdersForFirstUnit()'s
 * `lockForUpdate()` actually closes a real race under InnoDB, the same
 * "two-connection, real MySQL" standard as CartCheckoutRaceTest.php — see
 * .claude/rules/tests.md -> "tests/Concurrency" and kontrakt-dostepnosci.md
 * Zasada 6 ("dowód, nie deklaracja").
 *
 * **Why ServiceUnit::create() is wrapped in an explicit DB::transaction()
 * inside probe.php's 'createUnit' action, unlike every other probe action —
 * this was measured, not assumed, while building this test:** a BARE,
 * unwrapped ServiceUnit::create() (Filament's actual default — no panel here
 * calls ->databaseTransactions(), which defaults to false) auto-commits the
 * unit's own INSERT BEFORE the `created` event (and therefore
 * ServiceUnitObserver) ever runs. That makes the naive "two units both see
 * totalUnitsAtPair === 1" race mathematically UNREACHABLE: each process's
 * own insert always commits (and becomes visible to any read, on any
 * connection) strictly before that SAME process's own subsequent count
 * query — so for BOTH probes to simultaneously miss each other's unit would
 * require process A's count to run before process B's insert AND process
 * B's count to run before process A's insert, which is a direct
 * chronological contradiction given each process's own insert always
 * precedes its own count. The transaction wrapping reproduces the scenario
 * that IS reachable for real: ServiceUnitObserver's own docblock already
 * documents supporting a caller with an ambient transaction ("either starts
 * one or ... becomes a savepoint") — a bulk/batch unit-creation action, or a
 * future ->databaseTransactions(true) panel, are undramatic, legitimate ways
 * to reach it in production.
 */
final class ServiceUnitFirstUnitRaceTest extends TestCase
{
    use DatabaseTruncation;

    /** @var array<int, string> */
    protected array $connectionsToTruncate = ['mysql'];

    protected function setUp(): void
    {
        // Deliberately BEFORE parent::setUp() — same reasoning as
        // CartCheckoutRaceTest::setUp().
        $connection = (string) getenv('DB_CONNECTION');

        if ($connection !== 'mysql') {
            $this->markTestSkipped(
                'tests/Concurrency requires a real MySQL connection — InnoDB row '.
                'locking is not observable on SQLite (kontrakt-dostepnosci.md Zasada 6). '.
                'Run via scripts/test-concurrency.sh instead of a plain `php artisan test`.'
            );
        }

        $host = (string) getenv('DB_HOST');
        $database = (string) getenv('DB_DATABASE');

        if (in_array($host, ['mysql', 'registro-mysql', '127.0.0.1', 'localhost', ''], true)
            || in_array($database, ['registro', ''], true)) {
            throw new RuntimeException(sprintf(
                'tests/Concurrency resolved DB_HOST=%s DB_DATABASE=%s — refusing to run '.
                'against what looks like the dev database. Aborting before the app boots.',
                $host,
                $database
            ));
        }

        fwrite(STDERR, sprintf("[Concurrency] target: host=%s database=%s\n", $host, $database));

        parent::setUp();
    }

    /**
     * Two units created near-simultaneously for the SAME (service, location)
     * pair, both racing to be "the first". With the fix, exactly ONE
     * backfill round happens; the second probe's genuinely-real second unit
     * is simply counted on top — 2 real units + 4 placeholders = 6 total,
     * matching what a SEQUENTIAL "add unit, then add another" would have
     * produced.
     *
     * **Falsification result, measured, not predicted:** reverting
     * ServiceUnitObserver.php's lock and re-running this exact test does
     * NOT reproduce silent double-backfill (10 units) — it reproduces a real
     * `SQLSTATE[40001]: 1213 Deadlock found` on B's own `insertOrIgnore`
     * into `service_location_stocks`. Same safe-failure mode already
     * documented in rental-availability.md's "Realny deadlock InnoDB"
     * section for a different pair of paths: without lock discipline, two
     * concurrent `insertOrIgnore`s against the SAME unique key take
     * conflicting S-locks (kontrakt-dostepnosci.md Zasada 4), and InnoDB's
     * own deadlock detector picks a victim rather than silently corrupting
     * data. The test fails either way (deadlock == 'error', not 'ok') — the
     * assertion on `status` is what actually catches it, not the specific
     * exception class.
     */
    public function test_two_concurrent_first_units_for_the_same_pair_do_not_double_backfill(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 5,
        ]);

        // Single, first-ever location for this org — SyncServiceLocationStock::
        // forLocation() (ClickUp 123k99cvcc3 fix) seeds its anchor row with
        // quantity_total (5), exactly the "admin already typed a manual
        // quantity" scenario this fix defends.
        $location = Location::factory()->for($org, 'organization')->create();

        // sanity check: the anchor must already carry the pre-fix manual
        // quantity before either probe runs
        $this->assertDatabaseHas('service_location_stocks', [
            'service_id' => $service->id,
            'location_id' => $location->id,
            'quantity' => 5,
        ]);

        [$readyA, $outA] = $this->probeFiles('a');
        [$readyB, $outB] = $this->probeFiles('b');

        // A takes the service_units COUNT read first and holds it open for
        // 1500ms — long enough that B's own attempt (launched only once A
        // confirms it has reached that point, never guessed from outside)
        // is guaranteed to already be queued behind A's anchor-row lock.
        $procA = $this->spawnProbe($org->id, $service->id, $location->id, 'UNIT-A', 1500, $readyA, $outA);
        $this->waitForFile($readyA);

        $procB = $this->spawnProbe($org->id, $service->id, $location->id, 'UNIT-B', 0, $readyB, $outB);

        $this->waitForFile($outA, 10.0);
        $this->waitForFile($outB, 10.0);
        proc_close($procA);
        proc_close($procB);

        $resultA = $this->readResult($outA);
        $resultB = $this->readResult($outB);

        $this->assertSame('ok', $resultA['status'], 'A unexpectedly failed: '.json_encode($resultA));
        $this->assertSame('ok', $resultB['status'], 'B unexpectedly failed: '.json_encode($resultB));

        $units = ServiceUnit::withoutGlobalScope('organization')
            ->where('service_id', $service->id)
            ->where('location_id', $location->id)
            ->get();

        $this->assertCount(
            6,
            $units,
            'Expected exactly 2 real units + 4 backfilled placeholders (6 total), got '.$units->count().
            ' — a count above 6 means BOTH probes backfilled, the exact double-backfill this fix closes.'
        );
        $this->assertCount(2, $units->whereNotNull('identifier'), 'both real, named units must survive');
        $this->assertCount(4, $units->whereNull('identifier'), 'exactly one backfill round of 4 placeholders, not two');

        $stock = ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)
            ->where('location_id', $location->id)
            ->first();

        $this->assertSame(6, $stock->quantity);
        $this->assertSame(6, $service->fresh()->quantity_total);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function probeFiles(string $label): array
    {
        $dir = sys_get_temp_dir();
        $unique = uniqid('concurrency_unit_'.$label.'_', true);

        return [
            $dir.'/'.$unique.'.ready',
            $dir.'/'.$unique.'.out',
        ];
    }

    /**
     * @return resource
     */
    private function spawnProbe(int $organizationId, int $serviceId, int $locationId, string $identifier, int $delayMs, string $readyFile, string $outFile)
    {
        @unlink($readyFile);
        @unlink($outFile);

        $script = __DIR__.'/Support/probe.php';

        $command = [
            PHP_BINARY,
            $script,
            '--action=createUnit',
            '--lock-watch=service_units',
            '--organization-id='.$organizationId,
            '--service-id='.$serviceId,
            '--location-id='.$locationId,
            '--identifier='.$identifier,
            '--delay-ms='.$delayMs,
            '--ready-file='.$readyFile,
            '--out-file='.$outFile,
        ];

        $stdoutLog = $outFile.'.stdout.log';
        $stderrLog = $outFile.'.stderr.log';

        $descriptors = [
            1 => ['file', $stdoutLog, 'w'],
            2 => ['file', $stderrLog, 'w'],
        ];

        // env: null — inherit this PHPUnit process's own environment
        // verbatim, same reasoning as CartCheckoutRaceTest::spawnProbe().
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), null);

        if (! is_resource($process)) {
            $this->fail('Failed to spawn concurrency probe process.');
        }

        return $process;
    }

    private function waitForFile(string $path, float $timeoutSeconds = 5.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                $this->fail("Timed out after {$timeoutSeconds}s waiting for {$path}.");
            }

            usleep(10_000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readResult(string $outFile): array
    {
        $contents = file_get_contents($outFile);

        if ($contents === false || $contents === '') {
            $this->fail("Probe wrote no result to {$outFile}.");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->fail("Probe result in {$outFile} was not valid JSON: {$contents}");
        }

        return $decoded;
    }
}
