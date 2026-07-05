<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `active_slot` is an application-managed shadow column (kept in sync with
     * `status` by App\Models\Cart::booted()'s saving hook) that lets us enforce
     * "at most one active cart per user per org" via a plain unique index,
     * without blocking a user from having many converted/abandoned carts —
     * NULL values are never considered duplicates by a unique index (MySQL
     * and SQLite alike), so only rows with active_slot = 1 collide.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_slot')->nullable()->after('status');
        });

        // Backfill in bounded batches (chunkById) rather than one unbounded
        // UPDATE — safe to run against a production-sized table later, not
        // just the current empty/small dev table. Same pattern as the sibling
        // 2026_07_05_000001_add_double_booking_guard_to_appointments_table.php.
        DB::table('carts')->select(['id', 'status'])->chunkById(500, function ($carts) {
            $activeIds = $carts->where('status', 'active')->pluck('id');

            if ($activeIds->isNotEmpty()) {
                DB::table('carts')->whereIn('id', $activeIds)->update(['active_slot' => 1]);
            }
        });

        // Resolve any pre-existing duplicate active carts (this is exactly the
        // bug being fixed) before the unique index can be created — keep the
        // most recently updated one active, mark the rest abandoned. Grouping
        // is inherently a full-table aggregate (mirrors the appointments
        // migration's pre-flight duplicate check); the per-group resolution
        // below only ever touches the (expected to be small) set of actually
        // duplicated rows, not the whole table.
        $duplicateGroups = DB::table('carts')
            ->select('organization_id', 'user_id')
            ->where('status', 'active')
            ->groupBy('organization_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $carts = DB::table('carts')
                ->where('organization_id', $group->organization_id)
                ->where('user_id', $group->user_id)
                ->where('status', 'active')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            $staleIds = $carts->skip(1)->pluck('id');

            if ($staleIds->isNotEmpty()) {
                DB::table('carts')->whereIn('id', $staleIds)->update([
                    'status' => 'abandoned',
                    'active_slot' => null,
                    'abandoned_at' => now(),
                ]);
            }
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->unique(['organization_id', 'user_id', 'active_slot'], 'carts_org_user_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_org_user_active_unique');
            $table->dropColumn('active_slot');
        });
    }
};
