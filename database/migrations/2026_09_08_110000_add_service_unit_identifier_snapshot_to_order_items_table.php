<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Code review, 2026-09-08 (point #3): `order_items.service_unit_id`
     * (2026_09_08_100000) is `nullOnDelete` — deliberately, so a retired
     * ServiceUnit can always be deleted (see that migration's own docblock).
     * But that means an OrderItem's own `Auditable` trail and the FK itself
     * are NOT enough to reconstruct "which number was handed out" once the
     * ServiceUnit row is gone: the audit log records only the numeric id
     * that changed, and a dangling id resolves to nothing once the row is
     * deleted. Faza 3 krok 3.8 puts that number on the handover/return
     * protocol PDF — a document the customer signs — which must not depend
     * on a relation that can legitimately disappear.
     *
     * This is exactly the pattern order_items already uses for
     * `service_name` (a Service can be renamed or, per rentals.service_id's
     * restrictOnDelete, is at least in principle mutable) and
     * `price_snapshot` (Service's price can change after the order was
     * placed) — a point-in-time copy alongside the FK, not instead of it.
     *
     * Nullable: a unit assigned with no identifier at all (product decision,
     * see service_units' own creation migration) legitimately snapshots to
     * NULL — that's not a missing snapshot, it's an accurate one.
     *
     * NOT backfilled for existing rows: no order_item in this codebase had a
     * non-null `service_unit_id` before this migration (the column itself
     * is one migration old, in the same phase, on the same branch) — there
     * is nothing to backfill from.
     *
     * That reasoning holds ONLY because both migrations ship in the same
     * release. If 3.6/3.7 ever get deployed without this one, rows with a
     * service_unit_id and a NULL snapshot become possible, and the claim
     * above must be re-checked against real data instead of re-read as fact.
     *
     * Written 2026-09-08. NOT run against dev-MySQL per explicit team-lead
     * instruction in this review round — only exercised so far via
     * RefreshDatabase's ephemeral SQLite in the test suite.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('service_unit_identifier_snapshot')
                ->nullable()
                ->after('service_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('service_unit_identifier_snapshot');
        });
    }
};
