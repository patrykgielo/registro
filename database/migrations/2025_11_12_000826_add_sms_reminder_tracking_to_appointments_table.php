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
            $table->boolean('sent_24h_reminder_sms')->default(false)->index()->after('sent_followup')->comment('24-hour reminder SMS sent');
            $table->boolean('sent_2h_reminder_sms')->default(false)->index()->after('sent_24h_reminder_sms')->comment('2-hour reminder SMS sent');
            $table->boolean('sent_followup_sms')->default(false)->index()->after('sent_2h_reminder_sms')->comment('Follow-up SMS sent after completed appointment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['sent_24h_reminder_sms', 'sent_2h_reminder_sms', 'sent_followup_sms']);
        });
    }
};
