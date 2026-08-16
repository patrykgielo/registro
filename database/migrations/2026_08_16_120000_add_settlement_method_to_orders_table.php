<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records HOW the customer chose to settle the order at checkout
     * ('online' = Przelewy24, 'offline' = cash/transfer at pickup) — independent
     * from `payments.method`, which records the actual gateway/instrument used
     * for a given Payment row (p24/cash/bank_transfer). Default 'online' keeps
     * every existing order's meaning unchanged (they all went through P24).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('settlement_method', ['online', 'offline'])
                ->notNull()
                ->default('online')
                ->after('status');

            $table->index('settlement_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['settlement_method']);
            $table->dropColumn('settlement_method');
        });
    }
};
