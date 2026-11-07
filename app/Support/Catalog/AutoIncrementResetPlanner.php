<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;

/**
 * Pure decision: may `ALTER TABLE x AUTO_INCREMENT = <batch lowest id>` run after a
 * revert? Facts are gathered by RevertCatalogImport; this class only judges them, so
 * the rule table is testable on SQLite.
 *
 * All must hold:
 *  1. the driver is exactly `mysql` (never sqlite; mariadb is not exercised, so skipped);
 *  2. NO row with id >= the batch's lowest id remains in that table, any organization
 *     (a partial revert, a later foreign insert, a kept category all end up here);
 *  3. no other still-live (non-reverted) ledger row of that entity type — of any batch —
 *     points at an id >= the lowest one;
 *  4. no audit_logs row points (auditable_type + auditable_id >= lowest id) at that
 *     table — an Auditable model's old audit trail would otherwise alias a NEW record
 *     that reuses the id (service_units / service_location_stocks, created through
 *     Eloquent, always have such rows).
 * MySQL itself clamps the value to MAX(id)+1, so a reset can never create a duplicate
 * key; the rules above protect what remembers the old id outside the table.
 */
final class AutoIncrementResetPlanner
{
    /**
     * table => Auditable model class (audit_logs.auditable_type) or null.
     *
     * @var array<string, class-string|null>
     */
    public const TABLES = [
        'services' => null,
        'rental_categories' => null,
        'service_units' => ServiceUnit::class,
        'service_location_stocks' => ServiceLocationStock::class,
    ];

    /**
     * @return array{reset: bool, reason: string}
     */
    public static function decide(string $driver, bool $rowsAtOrAboveLowestIdRemain, bool $auditRowsAlias, bool $liveLedgerRowsAtOrAbove = false): array
    {
        if ($driver !== 'mysql') {
            return ['reset' => false, 'reason' => "sterownik '{$driver}' nie jest mysql"];
        }

        if ($rowsAtOrAboveLowestIdRemain) {
            return ['reset' => false, 'reason' => 'w tabeli zostały wiersze o id >= najniższego id paczki'];
        }

        if ($liveLedgerRowsAtOrAbove) {
            return ['reset' => false, 'reason' => 'inny niecofnięty wiersz ledgera (też z innej paczki) wskazuje na id >= najniższego id paczki'];
        }

        if ($auditRowsAlias) {
            return ['reset' => false, 'reason' => 'audit_logs odwołuje się do tych id (nowy rekord przejąłby cudzą historię)'];
        }

        return ['reset' => true, 'reason' => 'bezpieczne'];
    }
}
