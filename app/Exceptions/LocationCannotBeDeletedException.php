<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Backstops the "every tenant keeps at least one location, and exactly one
 * primary" invariant (app/docs/features/lokalizacje/tryb-jednooddzialowy.md)
 * at the model layer — see App\Observers\LocationObserver::deleting().
 *
 * Filament's LocationResource::guardDeletion() checks the same two
 * Location predicates first and halts the UI action with a friendly
 * notification before a delete() call ever reaches the model — this
 * exception is the last line of defense for any caller that goes around
 * Filament (tinker, a console command, a future API endpoint, a seeder).
 */
class LocationCannotBeDeletedException extends RuntimeException {}
