<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Concurrency probe — one real OS process, one real InnoDB session
|--------------------------------------------------------------------------
|
| Standalone bootstrap script (same two lines as `artisan` itself), NOT a
| PHPUnit test and NOT wired into any Artisan command list. tests/Concurrency
| spawns this via proc_open() so that CartService::convertToOrder() runs on
| a genuinely separate MySQL connection/session — the one thing a single,
| synchronous PHP process cannot give us. See
| tests/Concurrency/CartCheckoutRaceTest.php for the orchestration and
| .claude/rules/tests.md -> "tests/Concurrency" for the full picture.
|
| Coordination is by FILE SIGNAL + injected delay, not a fixed sleep guessed
| from outside: a DB::listen() hook fires the instant this process's own
| transaction issues the query that matters — `Service::lockForUpdate()`,
| the first statement CartService::convertToOrder()/setLocation() both issue
| against the contended row — touches --ready-file at that exact moment,
| then (if --delay-ms > 0) holds the transaction open for that long before
| letting the query return. The orchestrating test waits for --ready-file to
| exist before starting a second probe, so the second probe's own lock
| attempt is guaranteed to land while the first is still holding the row —
| deterministic, not raced.
|
| --action selects which write path this probe drives:
| 'convertToOrder' (default, unchanged from Faza 4/6.1's own scenarios),
| 'setLocation' (Faza 6 krok 6.2 — requires --new-location-id), 'addItem'
| (Faza 6 krok 6.2 follow-up, 2026-09-19 — requires --service-id,
| --start-date, --end-date; --quantity defaults to 1), or 'createUnit'
| (code review follow-up, 2026-09-19, ClickUp 123k99cvc54 — requires
| --organization-id, --service-id, --location-id; --identifier optional).
| The first three lock the `services` row (Service::lockForUpdate());
| 'addItem' additionally locks the `carts` row FIRST (same fix that made
| setLocation()/convertToOrder() do it); 'createUnit' does NOT go through
| CartService at all — it creates a real App\Models\ServiceUnit INSIDE an
| explicit DB::transaction() (see the action itself for why this wrapping is
| necessary, not just convenient), whose App\Observers\ServiceUnitObserver::
| materializePlaceholdersForFirstUnit() locks the `service_location_stocks`
| anchor row and, since the code review fix, the `service_units` COUNT read
| too.
|
| --lock-watch picks WHICH query fires the ready signal + holds --delay-ms
| (default 'services', unchanged for every existing scenario). 'carts' races
| the cart-row lock addItem()/updateQuantity() take (CartLocationChangeRaceTest's
| addItem-vs-setLocation scenario) — both 'services' and 'carts' require the
| matched query to say `for update` (a genuine lock acquisition). 'service_units'
| is DIFFERENT on purpose: it matches the first `count(*) ... from
| \`service_units\`` query REGARDLESS of `for update`, so the SAME probe/test
| can exercise BOTH the fixed code (a locking count) and the pre-fix code (a
| plain, unlocked count) at the identical logical point — "how many units
| exist for this pair" — which is what ServiceUnitFirstUnitRaceTest.php's own
| falsification (reverting ServiceUnitObserver.php) depends on.
|
| Safety: this process takes its DB_* purely from real OS environment
| variables the parent test passes via proc_open(..., env: null) (inherited
| from the PHPUnit process, which itself only has them because
| scripts/test-concurrency.sh set them for a throwaway container) — never
| from a file this repo tracks. It re-verifies independently, before
| booting the app at all, that they do not look like the dev database —
| the same guard CartCheckoutRaceTest::setUp() applies for the outer
| process, kept here too because this file can be invoked on its own.
|
*/

$options = getopt('', [
    'cart-id:',
    'delay-ms:',
    'ready-file:',
    'out-file:',
    'customer-email:',
    'action:',
    'new-location-id:',
    'lock-watch:',
    'service-id:',
    'start-date:',
    'end-date:',
    'quantity:',
    'organization-id:',
    'location-id:',
    'identifier:',
]);

$action = (string) ($options['action'] ?? 'convertToOrder');
$lockWatch = (string) ($options['lock-watch'] ?? 'services');

if (! in_array($action, ['convertToOrder', 'setLocation', 'addItem', 'createUnit'], true)) {
    fwrite(STDERR, "probe.php: --action must be 'convertToOrder', 'setLocation', 'addItem' or 'createUnit', got '{$action}'\n");
    exit(2);
}

if (! in_array($lockWatch, ['services', 'carts', 'service_units'], true)) {
    fwrite(STDERR, "probe.php: --lock-watch must be 'services', 'carts' or 'service_units', got '{$lockWatch}'\n");
    exit(2);
}

// 'cart-id'/'customer-email' only apply to the Cart-based actions;
// 'createUnit' does not touch a Cart at all.
$requiredOptions = ['ready-file', 'out-file'];
$requiredOptions = $action === 'createUnit'
    ? [...$requiredOptions, 'organization-id', 'service-id', 'location-id']
    : [...$requiredOptions, 'cart-id', 'customer-email'];

foreach ($requiredOptions as $required) {
    if (! isset($options[$required])) {
        fwrite(STDERR, "probe.php: missing required --{$required} for --action={$action}\n");
        exit(2);
    }
}

$cartId = isset($options['cart-id']) ? (int) $options['cart-id'] : null;
$delayMs = (int) ($options['delay-ms'] ?? 0);
$readyFile = (string) $options['ready-file'];
$outFile = (string) $options['out-file'];
$customerEmail = (string) ($options['customer-email'] ?? '');

if ($action === 'setLocation' && ! isset($options['new-location-id'])) {
    fwrite(STDERR, "probe.php: --action=setLocation requires --new-location-id\n");
    exit(2);
}

if ($action === 'addItem' && (! isset($options['service-id']) || ! isset($options['start-date']) || ! isset($options['end-date']))) {
    fwrite(STDERR, "probe.php: --action=addItem requires --service-id, --start-date and --end-date\n");
    exit(2);
}

$newLocationId = isset($options['new-location-id']) ? (int) $options['new-location-id'] : null;
$serviceId = isset($options['service-id']) ? (int) $options['service-id'] : null;
$startDate = isset($options['start-date']) ? (string) $options['start-date'] : null;
$endDate = isset($options['end-date']) ? (string) $options['end-date'] : null;
$quantity = (int) ($options['quantity'] ?? 1);
$organizationId = isset($options['organization-id']) ? (int) $options['organization-id'] : null;
$locationId = isset($options['location-id']) ? (int) $options['location-id'] : null;
$identifier = isset($options['identifier']) ? (string) $options['identifier'] : null;

// Refuse to run against anything that looks like the dev database. Belt
// and suspenders with CartCheckoutRaceTest::setUp()'s own matching guard —
// this check is independent and runs before a single line of the
// framework boots.
$dbConnection = (string) getenv('DB_CONNECTION');
$dbHost = (string) getenv('DB_HOST');
$dbDatabase = (string) getenv('DB_DATABASE');

if ($dbConnection !== 'mysql'
    || in_array($dbHost, ['mysql', 'registro-mysql', '127.0.0.1', 'localhost', ''], true)
    || in_array($dbDatabase, ['registro', ''], true)) {
    fwrite(STDERR, sprintf(
        "probe.php: refusing to run — DB_CONNECTION=%s DB_HOST=%s DB_DATABASE=%s does not look like the throwaway concurrency container.\n",
        $dbConnection,
        $dbHost,
        $dbDatabase
    ));
    exit(3);
}

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$hookFired = false;
$watchedTable = '`'.$lockWatch.'`';
$requireForUpdate = $lockWatch !== 'service_units';

Illuminate\Support\Facades\DB::listen(function ($query) use (&$hookFired, $readyFile, $delayMs, $watchedTable, $requireForUpdate): void {
    if ($hookFired) {
        return;
    }

    $sql = strtolower($query->sql);

    if (! str_contains($sql, $watchedTable)) {
        return;
    }

    // 'services'/'carts': the first LOCKING read CartService issues against
    // --lock-watch's table ('services' by default — see
    // CartService::convertToOrder() and kontrakt-dostepnosci.md Zasada 4;
    // 'carts' for scenarios racing the cart row lock itself, e.g. addItem()
    // vs setLocation(), 2026-09-19) — `for update` IS the thing being raced.
    //
    // 'service_units': deliberately the OPPOSITE — matches the first
    // `count(*)` query regardless of `for update`, so the identical hook
    // point exists in BOTH the fixed code (a locking count) and the
    // pre-fix code (a plain, unlocked count) — see this file's own
    // docblock on --lock-watch.
    if ($requireForUpdate) {
        if (! str_contains($sql, 'for update')) {
            return;
        }
    } elseif (! str_contains($sql, 'count(')) {
        return;
    }

    $hookFired = true;

    file_put_contents($readyFile, (string) getmypid());

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
});

$result = ['cart_id' => $cartId, 'pid' => getmypid(), 'action' => $action];

try {
    if ($action === 'createUnit') {
        // Deliberately wrapped in an EXPLICIT DB::transaction() here, unlike
        // the other three actions (which call CartService methods that open
        // their own). A bare, unwrapped ServiceUnit::create() (matching
        // Filament's default -- AdminPanelProvider never calls
        // ->databaseTransactions(), which defaults to false, confirmed
        // against vendor/filament/filament/src/Panel/Concerns/
        // HasDatabaseTransactions.php) auto-commits the unit's own INSERT
        // BEFORE App\Observers\ServiceUnitObserver::created() even starts
        // its own transaction -- which makes the exact "two units both
        // think they're first" race UNREACHABLE by construction: each
        // process's own insert-then-count ordering makes it mathematically
        // impossible for both processes to simultaneously miss each
        // other's already-committed unit (proved, not assumed, while
        // building this test -- see ServiceUnitFirstUnitRaceTest.php's own
        // docblock). Wrapping in a transaction here reproduces the ACTUAL
        // vulnerable scenario the fix defends against: the observer's own
        // docblock explicitly documents supporting a caller that already
        // has an ambient transaction open ("either starts one or ... becomes
        // a savepoint") -- a bulk/batch unit-creation action, or a future
        // ->databaseTransactions(true) panel, both legitimate, undramatic
        // ways to reach it for real.
        Illuminate\Support\Facades\DB::transaction(function () use ($organizationId, $serviceId, $locationId, $identifier, &$result): void {
            $unit = App\Models\ServiceUnit::withoutGlobalScope('organization')->create([
                'organization_id' => $organizationId,
                'service_id' => $serviceId,
                'location_id' => $locationId,
                'identifier' => $identifier,
                'status' => App\Enums\ServiceUnitStatus::Available->value,
            ]);

            $result['unit_id'] = $unit->id;
        });

        $result['status'] = 'ok';
    } elseif ($action === 'setLocation') {
        $cart = App\Models\Cart::findOrFail($cartId);
        $newLocation = App\Models\Location::findOrFail($newLocationId);

        $report = app(App\Services\Cart\CartService::class)->setLocation($cart, $newLocation);

        $result['status'] = 'ok';
        // setLocation() never throws (see its own docblock) — the OUTCOME
        // that matters for a race is how much quantity actually survived,
        // not an exception class. Reload the item to report its POST-write
        // quantity; 0 means it was removed entirely.
        $item = $cart->items()->first();
        $result['kept_quantity'] = $item?->quantity ?? 0;
        $result['reduced_count'] = count($report['reduced']);
        $result['removed_count'] = count($report['removed']);
    } elseif ($action === 'addItem') {
        // Deliberately a PLAIN, unlocked App\Models\Cart::findOrFail() — mirrors
        // CartController::add(), which loads the cart in a separate,
        // already-committed transaction (getOrCreateCart()) before ever
        // calling addItem(). Whatever location_id this read sees is exactly
        // the "stale in-memory $cart" a real concurrent request would carry
        // into addItem().
        $cart = App\Models\Cart::findOrFail($cartId);
        $service = App\Models\Service::findOrFail($serviceId);

        $item = app(App\Services\Cart\CartService::class)->addItem(
            $cart,
            $service,
            Illuminate\Support\Carbon::parse($startDate),
            Illuminate\Support\Carbon::parse($endDate),
            $quantity
        );

        $result['status'] = 'ok';
        $result['item_id'] = $item->id;
        $result['item_location_id'] = $item->location_id;
    } else {
        $cart = App\Models\Cart::findOrFail($cartId);

        $order = app(App\Services\Cart\CartService::class)->convertToOrder($cart, [
            'customer_email' => $customerEmail,
            'customer_first_name' => 'Proba',
            'customer_last_name' => (string) $cartId,
        ]);

        $result['status'] = 'ok';
        $result['order_id'] = $order->id;
        $result['order_number'] = $order->order_number;
    }
} catch (App\Exceptions\RentalUnavailableException $e) {
    $result['status'] = 'unavailable';
    $result['message'] = $e->getMessage();
} catch (Throwable $e) {
    $result['status'] = 'error';
    $result['class'] = get_class($e);
    $result['message'] = $e->getMessage();
}

file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT));

// Guarantee --ready-file exists even if the query-shape assumption above
// stopped matching (e.g. after a refactor of CartService) — otherwise the
// orchestrating test's wait-loop would hang until its own timeout instead
// of failing with a clear "hook never fired" signal.
if (! file_exists($readyFile)) {
    file_put_contents($readyFile, 'hook-did-not-fire');
}
