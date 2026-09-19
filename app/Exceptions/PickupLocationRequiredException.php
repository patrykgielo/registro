<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Faza 6 krok 6.4 (plan-wdrozenia.md) — the fail-closed backstop inside
 * CartService::convertToOrder() itself, behind SubmitCheckoutRequest's own
 * `pickup_location_id` validation (the FIRST line of defence, checked
 * against the cart's location_id before this method is even reached).
 *
 * This one only fires on the genuine TOCTOU race the request-level check
 * cannot close — and ONLY for a genuinely ambiguous (2+ active locations)
 * tenant, never a 0-/1-location one (see the call site's own docblock for
 * that distinction). Two independent races land here, not one (code review
 * 2026-09-10):
 *   1. The cart's Location is DELETED in the narrow window between
 *      SubmitCheckoutRequest validating and this method's own locked
 *      re-fetch of $cart — nullOnDelete turns `carts.location_id` itself to
 *      NULL.
 *   2. The cart's Location is DEACTIVATED in that same window — the FK is
 *      untouched (still points at a real row), but CartService::
 *      convertToOrder()'s own `->active()` filter treats it the same as
 *      "not found".
 * See CartService::convertToOrder()'s own docblock at the call site for the
 * full mechanism of both.
 */
class PickupLocationRequiredException extends RuntimeException
{
    public static function make(string $message = 'Wybierz oddział odbioru, aby złożyć zamówienie.'): static
    {
        return new static($message);
    }
}
