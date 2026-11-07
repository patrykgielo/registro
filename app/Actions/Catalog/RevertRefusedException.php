<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use RuntimeException;

final class RevertRefusedException extends RuntimeException
{
    public function __construct(public readonly RevertPlan $plan)
    {
        parent::__construct('Cofnięcie importu odmówione — szczegóły w planie.');
    }
}
