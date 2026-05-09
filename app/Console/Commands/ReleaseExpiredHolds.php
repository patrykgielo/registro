<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RentalStatus;
use App\Models\Rental;
use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'rentals:release-expired-holds';

    protected $description = 'Release expired rental holds to free up inventory';

    public function handle(): int
    {
        $count = Rental::where('status', RentalStatus::Held)
            ->where('held_until', '<', now())
            ->update(['status' => RentalStatus::Expired]);

        if ($count > 0) {
            $this->info("Released {$count} expired hold(s).");
        }

        return self::SUCCESS;
    }
}
