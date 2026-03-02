<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Service data snapshot - captured at booking time to preserve historical pricing
            $table->decimal('service_price_at_booking', 10, 2)->nullable()->after('service_id');
            $table->string('service_name_at_booking')->nullable()->after('service_price_at_booking');
            $table->unsignedInteger('service_duration_at_booking')->nullable()->after('service_name_at_booking');

            // Status change timestamps
            $table->timestamp('completed_at')->nullable()->after('cancellation_reason');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'service_price_at_booking',
                'service_name_at_booking',
                'service_duration_at_booking',
                'completed_at',
                'cancelled_at',
            ]);
        });
    }
};
