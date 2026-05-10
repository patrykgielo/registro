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
            $table->enum('subscription_status', ['trial', 'active', 'paused', 'cancelled'])
                ->default('trial')
                ->after('trial_ends_at');
            $table->decimal('monthly_fee', 8, 2)->nullable()->after('subscription_status');
            $table->timestamp('subscribed_at')->nullable()->after('monthly_fee');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscribed_at');
        });

        // Backfill: mark inactive organizations as cancelled; rest remain 'trial' (default)
        DB::table('organizations')
            ->where('is_active', false)
            ->update(['subscription_status' => 'cancelled']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'monthly_fee', 'subscribed_at', 'subscription_expires_at']);
        });
    }
};
