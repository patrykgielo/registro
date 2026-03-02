<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Contact information captured at time of booking
            // This preserves historical data even if user later updates their profile
            $table->string('first_name', 100)->nullable()->after('location_components');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('email', 255)->nullable()->after('last_name');
            $table->string('phone', 20)->nullable()->after('email');

            // Notification preferences at time of booking
            $table->boolean('notify_email')->default(true)->after('phone');
            $table->boolean('notify_sms')->default(true)->after('notify_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'email',
                'phone',
                'notify_email',
                'notify_sms',
            ]);
        });
    }
};
