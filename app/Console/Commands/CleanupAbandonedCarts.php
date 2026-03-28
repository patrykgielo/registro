<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

class CleanupAbandonedCarts extends Command
{
    protected $signature = 'carts:cleanup-abandoned';

    protected $description = 'Delete abandoned carts older than 7 days';

    public function handle(): int
    {
        $count = Cart::where('status', 'abandoned')
            ->where('updated_at', '<', now()->subDays(7))
            ->delete();

        $this->info("Deleted {$count} abandoned carts.");

        return self::SUCCESS;
    }
}
