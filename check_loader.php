<?php
// Debug: what SQLite state looks like before/during test setUp
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Http\Kernel::class);

// Check the DB connection state
$db = $app->make('db');
$conn = $db->connection();
$pdo = $conn->getPdo();

echo "Transaction level before beginTransaction: " . $conn->transactionLevel() . PHP_EOL;
echo "PDO in transaction: " . ($pdo->inTransaction() ? 'YES' : 'NO') . PHP_EOL;

try {
    $pdo->beginTransaction();
    echo "beginTransaction succeeded" . PHP_EOL;
    $pdo->rollBack();
} catch (\Exception $e) {
    echo "beginTransaction FAILED: " . $e->getMessage() . PHP_EOL;
}
