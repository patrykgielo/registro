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
| the first statement in CartService::convertToOrder() that touches the
| contended row — touches --ready-file at that exact moment, then (if
| --delay-ms > 0) holds the transaction open for that long before letting
| the query return. The orchestrating test waits for --ready-file to exist
| before starting a second probe, so the second probe's own lock attempt is
| guaranteed to land while the first is still holding the row — deterministic,
| not raced.
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
]);

foreach (['cart-id', 'ready-file', 'out-file', 'customer-email'] as $required) {
    if (! isset($options[$required])) {
        fwrite(STDERR, "probe.php: missing required --{$required}\n");
        exit(2);
    }
}

$cartId = (int) $options['cart-id'];
$delayMs = (int) ($options['delay-ms'] ?? 0);
$readyFile = (string) $options['ready-file'];
$outFile = (string) $options['out-file'];
$customerEmail = (string) $options['customer-email'];

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

Illuminate\Support\Facades\DB::listen(function ($query) use (&$hookFired, $readyFile, $delayMs): void {
    if ($hookFired) {
        return;
    }

    $sql = strtolower($query->sql);

    // The first (and, for these fixtures, only) locking read CartService
    // issues against the shared `services` row — see
    // CartService::convertToOrder() and kontrakt-dostepnosci.md Zasada 4.
    if (! str_contains($sql, '`services`') || ! str_contains($sql, 'for update')) {
        return;
    }

    $hookFired = true;

    file_put_contents($readyFile, (string) getmypid());

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
});

$result = ['cart_id' => $cartId, 'pid' => getmypid()];

try {
    $cart = App\Models\Cart::findOrFail($cartId);

    $order = app(App\Services\Cart\CartService::class)->convertToOrder($cart, [
        'customer_email' => $customerEmail,
        'customer_first_name' => 'Proba',
        'customer_last_name' => (string) $cartId,
    ]);

    $result['status'] = 'ok';
    $result['order_id'] = $order->id;
    $result['order_number'] = $order->order_number;
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
