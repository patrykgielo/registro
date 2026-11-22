<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ServiceType;

/**
 * Keeps `services.price` equal to `price_per_day` for `item_rental` rows, on every save.
 *
 * A rental has no editable "base price": the admin form hides `price` for it (stage A,
 * 2026-11-21) and customers only ever see the per-day rates. The column still exists and
 * is NOT NULL, and two places read it - the JSON-LD `offers.price`
 * (ServiceController::buildServiceSchema) and the tracker's `data-service-price`
 * (services/show.blade.php) - so it must not drift. Before this trait it did: a rental
 * created in the panel stored the DB default 0.00, an imported one kept the copy
 * ImportCatalog / SeedEquipmentRental made once and went stale on the first price edit.
 *
 * Hangs on `saving` (not on the Filament pages) for the same reason NormalizesSpecsShape
 * does: it then covers EVERY write path that goes through the model - panel create and
 * edit, import, seeders, console, a future API - not only the one form that exposed the
 * gap. The writers that already copy the value (import, seeders, factory) become a
 * harmless no-op.
 *
 * Contract:
 *  - item_rental and `price_per_day` not null -> `price = price_per_day`;
 *  - item_rental and `price_per_day` null     -> `price` is left exactly as it is
 *    (the stored value on edit, the DB default 0.00 on create);
 *  - time_slot                                -> never touched (its `price` is a real,
 *    editable field).
 *
 * Not covered by design: query-builder writes that skip model events (`Service::query()
 * ->update()`, `DB::table()`); the one migration that needs to repair existing rows
 * (2026_11_21_100000_backfill_rental_price_from_price_per_day) uses the query builder
 * for exactly that reason.
 */
trait SyncsRentalPriceWithDailyRate
{
    protected static function bootSyncsRentalPriceWithDailyRate(): void
    {
        static::saving(function (self $service): void {
            // service_type is immutable (Service::booted() reverts it in `updating`, which fires AFTER `saving`),
            // so on an existing row the stored type is the effective one, not a dirty attempt to change it.
            $type = $service->exists && $service->isDirty('service_type')
                ? $service->getOriginal('service_type')
                : $service->service_type;

            if (is_string($type)) {
                $type = ServiceType::tryFrom($type);
            }

            if ($type !== ServiceType::ItemRental) {
                return;
            }

            if ($service->price_per_day === null) {
                return;
            }

            $service->price = $service->price_per_day;
        });
    }
}
