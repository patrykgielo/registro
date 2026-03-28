<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Order\OrderService;
use Illuminate\Console\Command;

class CleanupExpiredOrders extends Command
{
    protected $signature = 'orders:cleanup-expired';

    protected $description = 'Cancel pending_payment orders past their expires_at TTL';

    public function handle(OrderService $orderService): int
    {
        $count = $orderService->cleanupExpired();
        $this->info("Cancelled {$count} expired orders.");

        return self::SUCCESS;
    }
}
