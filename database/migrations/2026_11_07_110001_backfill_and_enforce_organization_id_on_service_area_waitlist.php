<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companion to 2026_11_07_110000_add_organization_id_to_service_area_waitlist_table.
     *
     * HOW existing rows get an owner — there is NO derivation from a relation:
     * `service_area_waitlist` has no `service_area_id` (see 2025_12_13_162443),
     * and `nearest_area_city` is NOT evidence of the submitting tenant (the
     * route ran without ResolveTenant, so the validator that filled it saw
     * every tenant's areas). Nothing on a row names its tenant. So:
     *
     *  1. No NULL rows                  -> nothing to decide.
     *  2. NULL rows, exactly ONE organization in `organizations` (soft-deleted
     *     ones count — a deleted tenant may own some rows)
     *                                   -> every row can only have come from
     *                                      that tenant (dedicated stack, or a
     *                                      shared stack before its 2nd tenant).
     *                                      Assign them.
     *  3. NULL rows, 0 or 2+ organizations
     *                                   -> NOT derivable. NEVER guess a tenant
     *                                      (it would hand one customer's name,
     *                                      email, phone and address to a random
     *                                      business). Rows stay NULL, a warning
     *                                      is logged, and the column stays
     *                                      nullable. The BelongsToOrganization
     *                                      scope is `organization_id = <tenant>`,
     *                                      so NULL rows match NO tenant: they are
     *                                      invisible to everyone (fail-closed)
     *                                      and cannot be created again (the
     *                                      route now requires a tenant). They sit
     *                                      there until the platform operator
     *                                      deletes or assigns them by hand. This
     *                                      migration is then already "ran", so
     *                                      NOT NULL needs a follow-up migration
     *                                      once they are gone. Operator SQL and
     *                                      the "never copy this table whole when
     *                                      moving a tenant out of a shared DB"
     *                                      warning: app/docs/deployment/
     *                                      instalacja-tenanta-od-zera.md, 9.8.
     *
     * NOT NULL is applied only when no NULL row remains, so the migration can
     * never fail a deploy on the shared stack, and also never silently drops
     * PII to make the constraint fit.
     *
     * DB::table(), not Eloquent: a migration must not depend on model state
     * (global scopes, observers) that can change independently of the schema.
     */
    public function up(): void
    {
        $orphanRows = DB::table('service_area_waitlist')->whereNull('organization_id')->count();

        if ($orphanRows > 0) {
            $organizationIds = DB::table('organizations')->pluck('id');

            if ($organizationIds->count() !== 1) {
                Log::warning(
                    'service_area_waitlist: organization_id could not be derived for existing rows on a '.
                    'database with '.$organizationIds->count().' organizations; rows left NULL (invisible to '.
                    'every tenant) and the column left nullable. Review and delete/assign them manually; this '.
                    'migration will not run again, so NOT NULL then needs a follow-up migration.',
                    ['orphan_rows' => $orphanRows, 'organizations' => $organizationIds->count()]
                );

                return;
            }

            DB::table('service_area_waitlist')
                ->whereNull('organization_id')
                ->update(['organization_id' => $organizationIds->first()]);
        }

        Schema::table('service_area_waitlist', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
    }

    /**
     * Back to nullable (the schema 2026_11_07_110000 leaves behind). The
     * backfilled values are ordinary, correct data and are kept — same
     * precedent as 2026_09_10_090001_backfill_location_id_for_active_carts.
     */
    public function down(): void
    {
        $column = collect(Schema::getColumns('service_area_waitlist'))->firstWhere('name', 'organization_id');

        if ($column !== null && $column['nullable'] === false) {
            Schema::table('service_area_waitlist', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->change();
            });
        }
    }
};
