<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Rental;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RentalCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Rental $rental,
        public string $reason = ''
    ) {}
}
