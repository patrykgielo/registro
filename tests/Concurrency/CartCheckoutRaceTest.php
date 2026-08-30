<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Models\Cart;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The only two-connection, two-process test in this repo — see
 * kontrakt-dostepnosci.md Zasada 6 ("dowód, nie deklaracja"). Every other
 * oversell test in tests/Unit and tests/Feature is sequential, runs on
 * SQLite, and would still pass with the lock discipline in
 * CartService::convertToOrder() (CartService.php:214/225) completely
 * removed — SQLite has no InnoDB-style row locking to defeat.
 *
 * NOT run by a plain `php artisan test` (excluded from phpunit.xml's
 * defaultTestSuite, same precedent as tests/Browser) and NOT usable on
 * SQLite even if invoked directly — see setUp() below. Run via
 * scripts/test-concurrency.sh — see .claude/rules/tests.md ->
 * "tests/Concurrency" for the full runbook and the measured result of
 * deliberately breaking each of the two lock layers in turn.
 *
 * Deliberately excludes a per-location scenario: locations do not exist
 * yet (Faza 4 of the multi-location plan), so `getAvailableQuantity()` has
 * no `$locationId` parameter to race on today.
 */
final class CartCheckoutRaceTest extends TestCase
{
    use DatabaseTruncation;

    /** @var array<int, string> */
    protected array $connectionsToTruncate = ['mysql'];

    protected function setUp(): void
    {
        // Deliberately BEFORE parent::setUp(): Laravel's app (and therefore
        // DatabaseTruncation's migrate:fresh/truncate) does not exist yet at
        // this point, so a wrong DB_* here is caught before it can touch
        // anything, never after. getenv() reads real OS env vars directly —
        // no framework involved.
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

        // Independent of scripts/test-concurrency.sh's own guard — this one
        // runs even if the suite is ever invoked by hand with a hand-typed
        // -e flag. registro-mysql is this repo's ONE dev database
        // (Incident 2026-03-17); refuse outright rather than degrade to a
        // skip.
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
     * Scenario 1 (kontrakt-dostepnosci.md Zasada 6): two customers, the
     * last unit, overlapping dates, same service — exactly one checkout
     * must win.
     */
    public function test_two_concurrent_checkouts_for_the_last_unit_only_one_succeeds(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 1,
            'price_per_day' => 100,
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $start = Carbon::today()->addDays(10);
        $end = $start->copy()->addDays(2);

        $cartA = $this->makeCartWithItem($org, $userA, $service, $start, $end);
        $cartB = $this->makeCartWithItem($org, $userB, $service, $start, $end);

        [$readyA, $outA] = $this->probeFiles('a');
        [$readyB, $outB] = $this->probeFiles('b');

        // A takes the Service row lock and holds it for 1500ms — long
        // enough that B's own lock attempt (launched only once A confirms
        // it is holding, never guessed from outside) is guaranteed to have
        // already queued behind it before A commits.
        $procA = $this->spawnProbe($cartA->id, 1500, $readyA, $outA, 'a@example.com');
        $this->waitForFile($readyA);

        $procB = $this->spawnProbe($cartB->id, 0, $readyB, $outB, 'b@example.com');

        $this->waitForFile($outA, 10.0);
        $this->waitForFile($outB, 10.0);
        proc_close($procA);
        proc_close($procB);

        $resultA = $this->readResult($outA);
        $resultB = $this->readResult($outB);

        $statuses = [$resultA['status'], $resultB['status']];
        sort($statuses);

        $this->assertSame(
            ['ok', 'unavailable'],
            $statuses,
            'Expected exactly one winner and one RentalUnavailableException. Got: '.json_encode([$resultA, $resultB])
        );

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, OrderItem::where('service_id', $service->id)->count());

        $winnerCartId = $resultA['status'] === 'ok' ? $cartA->id : $cartB->id;
        $loserCartId = $resultA['status'] === 'ok' ? $cartB->id : $cartA->id;

        $this->assertSame('converted', Cart::find($winnerCartId)->status);
        $this->assertSame('active', Cart::find($loserCartId)->status);
    }

    /**
     * Scenario 2 (kontrakt-dostepnosci.md Zasada 6, "przechodzą oba"):
     * same service, same last unit, but the two requested windows do NOT
     * overlap — proves the Service-row lock (Zasada 4) only serialises the
     * two checkouts, it does not falsely reject either of them.
     */
    public function test_two_concurrent_checkouts_for_non_overlapping_windows_both_succeed(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 1,
            'price_per_day' => 100,
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $startA = Carbon::today()->addDays(10);
        $endA = $startA->copy()->addDays(2);

        $startB = Carbon::today()->addDays(20);
        $endB = $startB->copy()->addDays(2);

        $cartA = $this->makeCartWithItem($org, $userA, $service, $startA, $endA);
        $cartB = $this->makeCartWithItem($org, $userB, $service, $startB, $endB);

        [$readyA, $outA] = $this->probeFiles('a');
        [$readyB, $outB] = $this->probeFiles('b');

        // A still queues B behind it on the SAME Service row (Zasada 4 pays
        // for cross-window serialisation deliberately) — 800ms is enough
        // for B to have issued its own blocking read and be waiting.
        $procA = $this->spawnProbe($cartA->id, 800, $readyA, $outA, 'a@example.com');
        $this->waitForFile($readyA);

        $procB = $this->spawnProbe($cartB->id, 0, $readyB, $outB, 'b@example.com');

        $this->waitForFile($outA, 10.0);
        $this->waitForFile($outB, 10.0);
        proc_close($procA);
        proc_close($procB);

        $resultA = $this->readResult($outA);
        $resultB = $this->readResult($outB);

        $this->assertSame('ok', $resultA['status'], 'A unexpectedly failed: '.json_encode($resultA));
        $this->assertSame('ok', $resultB['status'], 'B unexpectedly failed: '.json_encode($resultB));

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(2, OrderItem::where('service_id', $service->id)->count());
    }

    private function makeCartWithItem(Organization $org, User $user, Service $service, Carbon $start, Carbon $end): Cart
    {
        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        \App\Models\CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        return $cart;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function probeFiles(string $label): array
    {
        $dir = sys_get_temp_dir();
        $unique = uniqid('concurrency_'.$label.'_', true);

        return [
            $dir.'/'.$unique.'.ready',
            $dir.'/'.$unique.'.out',
        ];
    }

    /**
     * @return resource
     */
    private function spawnProbe(int $cartId, int $delayMs, string $readyFile, string $outFile, string $email)
    {
        @unlink($readyFile);
        @unlink($outFile);

        $script = __DIR__.'/Support/probe.php';

        $command = [
            PHP_BINARY,
            $script,
            '--cart-id='.$cartId,
            '--delay-ms='.$delayMs,
            '--ready-file='.$readyFile,
            '--out-file='.$outFile,
            '--customer-email='.$email,
        ];

        $stdoutLog = $outFile.'.stdout.log';
        $stderrLog = $outFile.'.stderr.log';

        $descriptors = [
            1 => ['file', $stdoutLog, 'w'],
            2 => ['file', $stderrLog, 'w'],
        ];

        // env: null — inherit this PHPUnit process's own environment
        // verbatim, including the DB_* overrides scripts/test-concurrency.sh
        // set for it. No env array is reconstructed by hand here, so there
        // is nothing in THIS file that could drift from what setUp() above
        // already validated.
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
