<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('customer_email', 255)->nullable()->after('expires_at');
            $table->timestamp('checkout_started_at')->nullable()->after('customer_email');
            $table->string('last_checkout_step', 50)->nullable()->after('checkout_started_at');
            $table->timestamp('abandoned_at')->nullable()->after('last_checkout_step');
            $table->string('utm_source', 255)->nullable()->after('abandoned_at');
            $table->string('utm_medium', 100)->nullable()->after('utm_source');
            $table->string('utm_campaign', 255)->nullable()->after('utm_medium');
            $table->index(['status', 'updated_at'], 'carts_status_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropIndex('carts_status_updated_at_idx');
            $table->dropColumn([
                'customer_email',
                'checkout_started_at',
                'last_checkout_step',
                'abandoned_at',
                'utm_source',
                'utm_medium',
                'utm_campaign',
            ]);
        });
    }
};
