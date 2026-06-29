<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('lifecycle_state', 20)->default('active')->after('is_active')->index();
            $table->timestamp('closing_initiated_at')->nullable()->after('lifecycle_state');
            $table->timestamp('closed_at')->nullable()->after('closing_initiated_at');
            $table->timestamp('purge_after')->nullable()->after('closed_at');
            $table->timestamp('closure_requested_at')->nullable()->after('purge_after');
        });

        // Backfill: derive lifecycle_state from the legacy is_active flag.
        // Uses DB::table() to bypass Eloquent models/global scopes.
        DB::table('organizations')->where('is_active', true)->update(['lifecycle_state' => 'active']);
        DB::table('organizations')->where('is_active', false)->update(['lifecycle_state' => 'suspended']);
        // Defensive: rows with is_active=NULL would otherwise keep the column DEFAULT ('active').
        // Treat NULL (unknown/legacy) as suspended rather than silently granting active access.
        DB::table('organizations')->whereNull('is_active')->update(['lifecycle_state' => 'suspended']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_state',
                'closing_initiated_at',
                'closed_at',
                'purge_after',
                'closure_requested_at',
            ]);
        });
    }
};
