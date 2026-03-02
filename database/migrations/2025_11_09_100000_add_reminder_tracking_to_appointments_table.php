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
            $table->boolean('sent_24h_reminder')->default(false)->index()->after('notes')->comment('24-hour reminder email sent');
            $table->boolean('sent_2h_reminder')->default(false)->index()->after('sent_24h_reminder')->comment('2-hour reminder email sent');
            $table->boolean('sent_followup')->default(false)->index()->after('sent_2h_reminder')->comment('Follow-up email sent after completed appointment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['sent_24h_reminder', 'sent_2h_reminder', 'sent_followup']);
        });
    }
};
