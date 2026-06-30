<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\RentalCancelled;
use App\Notifications\RentalCancelledNotification;
use Illuminate\Support\Facades\Log;

class SendRentalCancelledNotification
{
    public function handle(RentalCancelled $event): void
    {
        $rental = $event->rental->loadMissing('customer');

        if ($rental->customer) {
            $rental->customer->notify(new RentalCancelledNotification($rental, $event->reason));
        } else {
            Log::warning('RentalCancelled: no customer attached, skipping notification', [
                'rental_id' => $rental->id,
            ]);
        }
    }
}
