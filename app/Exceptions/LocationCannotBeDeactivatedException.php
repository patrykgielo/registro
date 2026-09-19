<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Faza 6 code review (2026-09-10) — the deactivation-side twin of
 * LocationCannotBeDeletedException. Backstops "every tenant keeps at least
 * one ACTIVE location" (LocationContext::selected()/mustPrompt() both
 * assume this — a tenant with zero active locations strands every existing
 * customer cart at checkout with no switcher to recover through, see
 * App\Observers\LocationObserver's own docblock).
 *
 * Filament's LocationResource::guardDeactivation() checks the same
 * predicate first and halts the UI action with a friendly notification
 * before a save ever reaches the model — this exception is the last line
 * of defense for any caller that goes around Filament (tinker, a console
 * command, a future API endpoint, a seeder).
 */
class LocationCannotBeDeactivatedException extends RuntimeException {}
