<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Faza 6 krok 6.2 (86cbahqgv, plan-wdrozenia.md) — the team lead's brief,
 * decision 4 ("Wyścig między pytaniem a potwierdzeniem — rewalidacja przy
 * potwierdzeniu, pod blokadą; samo pokazanie liczb w pytaniu to nie
 * rezerwacja").
 *
 * NOT two carts racing each other directly — a CartItem sitting in ANYONE's
 * cart is never itself a reservation in this system (getAvailableQuantity()
 * only ever counts committed Rentals/OrderItems; this is the whole reason
 * convertToOrder() re-validates at all). An earlier version of this file
 * raced two concurrent setLocation() calls against each other and found
 * BOTH kept their unit — that is correct, expected behaviour given that
 * invariant, not a bug: neither confirmation creates a real reservation, so
 * neither can block the other. Proving nothing changed for that invariant
 * is not this ticket's job.
 *
 * The scenario that actually matches the team lead's framing: customer A is
 * CHECKING OUT (a real reservation, `convertToOrder()`) for the last unit at
 * the location customer B is simultaneously CONFIRMING a switch into
 * (`setLocation()`). Under the SAME `Service::lockForUpdate()` +
 * `forUpdate: true` discipline `CartCheckoutRaceTest` already proves for
 * convertToOrder() alone, B's confirmation must see A's just-committed
 * reservation and trim itself — not the stale, pre-commit snapshot Zasada 3
 * (rental-availability.md) warns a plain read would return.
 *
 * Deliberately a separate file from CartCheckoutRaceTest.php, same
 * precedent as the Unit/Feature split between CartServiceLocationTest (Faza
 * 4 availability) and this ticket's own CartServiceLocationChangeTest (Faza
 * 6.2 pickup-location switch) — different CartService write path, own
 * scenario. Sequential tests (tests/Unit, tests/Feature) pass even with
 * every lock removed, because SQLite has no InnoDB row locking to defeat —
 * kontrakt-dostepnosci.md Zasada 6.
 */
final class CartLocationChangeRaceTest extends TestCase
{
    use DatabaseTruncation;

    /** @var array<int, string> */
    protected array $connectionsToTruncate = ['mysql'];

    protected function setUp(): void
    {
        // Same guard as CartCheckoutRaceTest::setUp() — deliberately BEFORE
        // parent::setUp(), see that class's own docblock for why.
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
     * A checks out (convertToOrder) a cart item explicitly scoped to the
     * NEW location, for the last unit. B has a DIFFERENT cart, still parked
     * at an old location, and confirms (setLocation) a switch onto that
     * exact same NEW location, for the same service. A takes the shared
     * `services` row lock first and holds it through its own commit — B's
     * own lock attempt (issued by evaluateLocationChange()'s
     * `Service::lockForUpdate()`) is guaranteed to queue behind it and only
     * proceed once A's OrderItem is already committed. B must then see 0
     * available and trim its item to 0 (removed) — not the stale
     * pre-commit "1 available" a non-locking read would still return under
     * REPEATABLE READ (rental-availability.md Zasada 3).
     */
    public function test_a_concurrent_checkout_for_the_last_unit_is_seen_by_a_location_change_confirmation_racing_it(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 0, // deliberately unused — proves the location branch, not this column (same precedent as CartCheckoutRaceTest scenario 3)
            'price_per_day' => 100,
        ]);

        // Locations created AFTER the service so ServiceLocationStockObserver
        // auto-materializes the anchor rows for it (SyncServiceLocationStock::
        // forLocation()'s own docblock — forService() is lazy, not triggered
        // by creating a Service).
        $oldLocation = Location::factory()->for($org, 'organization')->create();
        $newLocation = Location::factory()->for($org, 'organization')->create();

        ServiceLocationStock::where('service_id', $service->id)
            ->where('location_id', $newLocation->id)
            ->update(['quantity' => 1]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $start = Carbon::today()->addDays(10);
        $end = $start->copy()->addDays(2);

        // A's own cart item is explicitly scoped to $newLocation — the
        // reservation convertToOrder() creates from it must count against
        // THAT location's capacity for B's evaluateLocationChange() to see it.
        $cartA = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $userA->id,
            'location_id' => $newLocation->id,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cartA->id,
            'service_id' => $service->id,
            'location_id' => $newLocation->id,
            'quantity' => 1,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        // B's cart is still at the OLD location, confirming a switch onto
        // $newLocation — exactly the write path under test.
        $cartB = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $userB->id,
            'location_id' => $oldLocation->id,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cartB->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        [$readyA, $outA] = $this->probeFiles('a');
        [$readyB, $outB] = $this->probeFiles('b');

        // A (checkout) takes the Service row lock and holds it for 1500ms —
        // long enough that B's own lock attempt (launched only once A
        // confirms it is holding, never guessed from outside) is guaranteed
        // to have already queued behind it before A commits.
        $procA = $this->spawnCheckoutProbe($cartA->id, 1500, $readyA, $outA);
        $this->waitForFile($readyA);

        $procB = $this->spawnLocationChangeProbe($cartB->id, $newLocation->id, 0, $readyB, $outB);

        $this->waitForFile($outA, 10.0);
        $this->waitForFile($outB, 10.0);
        proc_close($procA);
        proc_close($procB);

        $resultA = $this->readResult($outA);
        $resultB = $this->readResult($outB);

        $this->assertSame('ok', $resultA['status'], 'A (checkout) unexpectedly failed: '.json_encode($resultA));
        $this->assertSame('ok', $resultB['status'], 'B (setLocation) unexpectedly failed: '.json_encode($resultB));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(
            0,
            $resultB['kept_quantity'],
            'B\'s confirmation must see A\'s just-committed reservation and trim to 0, not the stale pre-commit snapshot. Got: '.json_encode([$resultA, $resultB])
        );
        $this->assertSame(1, $resultB['removed_count']);

        // B's confirmation still moved carts.location_id — setLocation()
        // never refuses the pickup switch itself, only trims what doesn't fit.
        $this->assertSame($newLocation->id, $cartB->fresh()->location_id);
    }

    /**
     * Faza 6 krok 6.2 follow-up (2026-09-19, rental-availability.md Zasada 9,
     * second incident) — the OTHER two write paths' own version of this bug:
     * `addItem()`/`updateQuantity()` used to derive `location_id` from the
     * caller's in-memory `$cart` instance, loaded in a SEPARATE,
     * already-committed transaction (`CartController::add()` ->
     * `getOrCreateCart()`) — never re-locked/re-read. A concurrent
     * `setLocation()` confirming a branch switch on the SAME cart could
     * commit in between, leaving a freshly-inserted CartItem pointing at the
     * OLD location while `carts.location_id` already says the NEW one.
     *
     * Unlike the checkout-vs-confirmation scenario above, this is not a
     * stock/oversell race — both locations have generous stock, on purpose,
     * so the only thing under test is WHICH location the new row lands in.
     * The probe for `--action=addItem` loads its own `$cart` with a plain,
     * unlocked `Cart::findOrFail()` (mirroring `CartController::add()`
     * exactly), so the race is real: it is spawned only once the
     * `setLocation()` probe already holds the cart row lock
     * (`--lock-watch=carts`, since this scenario has nothing contended on
     * the `services` row the default watches), mid-delay, before that
     * transaction's own `$cart->update(['location_id' => ...])` and commit.
     *
     * Falsified (`addItem()`'s `Cart::where(...)->lockForUpdate()->firstOrFail()`
     * reverted to reading `$cart->location_id` straight off the caller's
     * argument) → this test fails: the new CartItem lands at the OLD
     * location while `carts.location_id` already reads the NEW one. Restored
     * → passes.
     */
    public function test_add_item_race_against_a_concurrent_location_change_keeps_the_new_cart_item_consistent_with_the_cart(): void
    {
        $org = Organization::factory()->itemRental()->create();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 0, // unused — capacity comes from the per-location anchor rows below
            'price_per_day' => 100,
        ]);

        // Locations created AFTER the service — same ordering requirement as
        // the scenario above (ServiceLocationStockObserver auto-materializes
        // anchor rows on Location::created(), not on Service::created()).
        $oldLocation = Location::factory()->for($org, 'organization')->create();
        $newLocation = Location::factory()->for($org, 'organization')->create();

        // Generous stock at BOTH locations — deliberately not the resource
        // under contention in this scenario.
        ServiceLocationStock::where('service_id', $service->id)
            ->whereIn('location_id', [$oldLocation->id, $newLocation->id])
            ->update(['quantity' => 5]);

        $user = User::factory()->create();

        $start = Carbon::today()->addDays(10);
        $end = $start->copy()->addDays(2);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $oldLocation->id,
        ]);

        // Non-empty cart, so setLocation() has an existing row to re-stamp
        // alongside the new one addItem() is about to insert — a realistic
        // "customer switches branch mid-cart" shape.
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'location_id' => $oldLocation->id,
            'quantity' => 1,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        [$readySwitch, $outSwitch] = $this->probeFiles('switch');
        [$readyAdd, $outAdd] = $this->probeFiles('add');

        // Tab 1: confirms the branch switch old -> new, holding the CART ROW
        // lock (not the services lock — nothing here is stock-contended) for
        // 1500ms before committing.
        $procSwitch = $this->spawnLocationChangeProbe($cart->id, $newLocation->id, 1500, $readySwitch, $outSwitch, lockWatch: 'carts');
        $this->waitForFile($readySwitch);

        // Tab 2: adds a NEW item to the SAME cart via its own plain, unlocked
        // read of the cart row (same shape as CartController::add()),
        // spawned only once Tab 1 already holds the cart lock — guaranteed
        // to queue behind it if addItem() itself re-locks, or to race ahead
        // with stale data if it doesn't.
        $procAdd = $this->spawnAddItemProbe($cart->id, $service->id, $start, $end, $readyAdd, $outAdd);

        $this->waitForFile($outSwitch, 10.0);
        $this->waitForFile($outAdd, 10.0);
        proc_close($procSwitch);
        proc_close($procAdd);

        $resultSwitch = $this->readResult($outSwitch);
        $resultAdd = $this->readResult($outAdd);

        $this->assertSame('ok', $resultSwitch['status'], 'setLocation() unexpectedly failed: '.json_encode($resultSwitch));
        $this->assertSame('ok', $resultAdd['status'], 'addItem() unexpectedly failed: '.json_encode($resultAdd));

        $cart->refresh();

        $this->assertSame(
            $newLocation->id,
            $resultAdd['item_location_id'],
            'The new CartItem must be stamped with the CURRENT (post-switch) location, not the stale one addItem() started with: '.json_encode([$resultSwitch, $resultAdd])
        );

        // The invariant this whole step guarantees (rental-availability.md
        // Zasada 9): every surviving cart_item.location_id must equal the
        // cart's OWN, just-committed location_id.
        foreach ($cart->items as $item) {
            $this->assertSame(
                $cart->location_id,
                $item->location_id,
                "cart_item #{$item->id} location_id={$item->location_id} does not match carts.location_id={$cart->location_id}"
            );
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function probeFiles(string $label): array
    {
        $dir = sys_get_temp_dir();
        $unique = uniqid('concurrency_loc_'.$label.'_', true);

        return [
            $dir.'/'.$unique.'.ready',
            $dir.'/'.$unique.'.out',
        ];
    }

    /**
     * @return resource
     */
    private function spawnLocationChangeProbe(int $cartId, int $newLocationId, int $delayMs, string $readyFile, string $outFile, string $lockWatch = 'services')
    {
        return $this->spawnProbe([
            '--cart-id='.$cartId,
            '--action=setLocation',
            '--new-location-id='.$newLocationId,
            '--delay-ms='.$delayMs,
            '--lock-watch='.$lockWatch,
            '--ready-file='.$readyFile,
            '--out-file='.$outFile,
            '--customer-email=unused@example.com',
        ], $outFile);
    }

    /**
     * @return resource
     */
    private function spawnAddItemProbe(int $cartId, int $serviceId, Carbon $start, Carbon $end, string $readyFile, string $outFile)
    {
        return $this->spawnProbe([
            '--cart-id='.$cartId,
            '--action=addItem',
            '--service-id='.$serviceId,
            '--start-date='.$start->toDateString(),
            '--end-date='.$end->toDateString(),
            '--quantity=1',
            '--delay-ms=0',
            '--ready-file='.$readyFile,
            '--out-file='.$outFile,
            '--customer-email=unused@example.com',
        ], $outFile);
    }

    /**
     * @return resource
     */
    private function spawnCheckoutProbe(int $cartId, int $delayMs, string $readyFile, string $outFile)
    {
        return $this->spawnProbe([
            '--cart-id='.$cartId,
            '--action=convertToOrder',
            '--delay-ms='.$delayMs,
            '--ready-file='.$readyFile,
            '--out-file='.$outFile,
            '--customer-email=checkout-a@example.com',
        ], $outFile);
    }

    /**
     * @param  array<int, string>  $args
     * @return resource
     */
    private function spawnProbe(array $args, string $outFile)
    {
        $readyFile = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--ready-file=')) {
                $readyFile = substr($arg, strlen('--ready-file='));
            }
        }

        if ($readyFile !== null) {
            @unlink($readyFile);
        }
        @unlink($outFile);

        $script = __DIR__.'/Support/probe.php';

        $command = array_merge([PHP_BINARY, $script], $args);

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
