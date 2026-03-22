<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add hold/expired statuses and held_until timestamp for temporary reservation pattern.
 *
 * Flow: held (15 min TTL) → pending → confirmed → active → returned
 *       held → expired (if not confirmed within TTL)
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: ALTER ENUM to add 'held' and 'expired'
        // SQLite: ENUM is stored as TEXT, no ALTER needed
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rentals MODIFY COLUMN status ENUM('held','pending','confirmed','active','returned','cancelled','expired') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('rentals', function (Blueprint $table) {
            $table->timestamp('held_until')->nullable()->after('cancelled_at');
            $table->index(['status', 'held_until'], 'idx_held_cleanup');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropIndex('idx_held_cleanup');
            $table->dropColumn('held_until');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rentals MODIFY COLUMN status ENUM('pending','confirmed','active','returned','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
