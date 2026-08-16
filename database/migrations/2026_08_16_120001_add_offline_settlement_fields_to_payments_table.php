<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `p24_session_id` was NOT NULL — a cash/transfer settlement recorded by
     * staff has no P24 session to put there. Relaxing it to nullable (still
     * UNIQUE — MySQL/SQLite both allow multiple NULLs in a unique index) lets
     * offline Payment rows exist without a synthetic placeholder value (the
     * `'fake-'.$order->id` pattern from Dev/FakePaymentController is a dev-only
     * bypass and deliberately NOT reused here).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('p24_session_id')->nullable()->change();

            $table->string('method', 20)->notNull()->default('p24')->after('p24_order_id');
            $table->foreignId('recorded_by')->nullable()->after('method')
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['method', 'notes']);
        });

        // Backfill any offline rows (NULL p24_session_id) before restoring NOT NULL,
        // same pattern as migrations.md's "handle NULL rows FIRST" rule.
        DB::table('payments')->whereNull('p24_session_id')->orderBy('id')->cursor()->each(
            fn ($payment) => DB::table('payments')->where('id', $payment->id)
                ->update(['p24_session_id' => 'legacy-'.$payment->id])
        );

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('p24_session_id')->nullable(false)->change();
        });
    }
};
