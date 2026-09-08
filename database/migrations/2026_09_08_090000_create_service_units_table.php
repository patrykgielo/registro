<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 3 (app/docs/features/lokalizacje/model-danych.md,
     * kontrakt-dostepnosci.md) — the physical egzemplarz (unit) of an
     * item_rental Service: WHERE it lives and whether it is fit for use.
     * Deliberately NOT a second source of truth for occupancy — that stays in
     * order_items/rentals — and NOT what the hot availability path reads:
     * App\Observers\ServiceUnitObserver keeps `service_location_stocks.quantity`
     * (the Faza 2 anchor) in sync as a mirror of
     * `COUNT(service_units WHERE location_id = L AND status = 'available')`,
     * in the same transaction as any unit create/update/delete. This table
     * has ZERO effect on availability today — RentalAvailabilityService is
     * untouched by this migration.
     *
     * Column naming: `identifier`, NOT `serial_number`. Product decision
     * (plan-wdrozenia.md, "Nazewnictwo kolumny — rozstrzygnięcie", 2026-09-07):
     * this is the TENANT'S OWN mark on the equipment ("KOP-04" on a sticker),
     * never the manufacturer's serial number, and it is free text with no
     * enforced format for exactly that reason. Nullable — a unit can exist
     * (and count toward stock) before anyone assigns it a mark; Faza 3 krok
     * 3.6 fills this in at first hand-over, not at creation.
     *
     * `status` is a plain string column backed by App\Enums\ServiceUnitStatus,
     * not a DB-level $table->enum() — this codebase has zero
     * `$table->enum(` calls in database/migrations/ (grepped before writing
     * this), and .claude/rules/tests.md's own MySQL-gate section documents
     * why: SQLite never enforces a real ENUM, so a literal DB enum column
     * only reveals a mismatch on the MySQL release gate, and the existing
     * status-bearing tables in this codebase (orders.status, rentals.status)
     * already use plain string() + a PHP BackedEnum cast instead.
     *
     * UNIQUE (organization_id, identifier) — MySQL and SQLite both treat NULL
     * as distinct from every other NULL in a unique index (standard SQL
     * behaviour, not a driver quirk), so any number of units without a mark
     * coexist under this constraint. Verified empirically, not assumed — see
     * CreateServiceUnitsTableMigrationTest::
     * test_multiple_units_without_an_identifier_do_not_collide().
     *
     * FK onDelete choices mirror service_location_stocks
     * (2026_08_28_090000_create_service_location_stocks_table.php's own
     * docblock, which this repeats rather than references because a
     * migration should stay self-contained):
     *
     * - `service_id` -> cascadeOnDelete. A unit is operational data with no
     *   legal-retention requirement (migrations.md's FK classification table)
     *   — unlike rentals.service_id/order_items.service_id, which protect
     *   legal records and stay restrictOnDelete. Faza 2's code-reviewer
     *   BLOKER 2 is the concrete cautionary precedent: copying
     *   restrictOnDelete from a legal-record sibling made almost every real
     *   item_rental service silently undeletable the moment it got its first
     *   row on the sibling table.
     * - `location_id` -> cascadeOnDelete, not restrictOnDelete, for the same
     *   sibling-cascade-ordering reason as service_location_stocks.location_id:
     *   `locations.organization_id` is already cascadeOnDelete (Faza 1), so a
     *   restrict here would race two sibling cascades hanging off the same
     *   organizations row with no guaranteed MySQL ordering between them.
     * - `organization_id` -> cascadeOnDelete — consistent with this table's
     *   own "ephemeral operational data" classification, and a backstop
     *   against a stray row whose location belonged to a different
     *   organization than its own organization_id (should never happen given
     *   every write path sets both from the same source, same reasoning as
     *   service_location_stocks.organization_id).
     */
    public function up(): void
    {
        Schema::create('service_units', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('identifier')->nullable();
            $table->string('inventory_number')->nullable();
            $table->string('status')->default('available');
            $table->date('acquired_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'identifier'], 'service_units_org_identifier_unique');
            $table->index(['service_id', 'location_id', 'status'], 'service_units_service_location_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_units');
    }
};
