<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * GDPR compliance: Add opt-out instructions to SMS templates.
     * Required by GDPR Article 21 (right to object to processing).
     */
    public function up(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            // Opt-out text appended to every SMS (GDPR compliance)
            // Example PL: "Wyslij STOP aby zrezygnowac"
            // Example EN: "Reply STOP to opt-out"
            $table->string('opt_out_text', 100)->nullable()->after('message_body');
        });

        // Update existing templates with default opt-out text
        DB::table('sms_templates')->where('language', 'pl')->update([
            'opt_out_text' => 'Wyslij STOP aby zrezygnowac',
        ]);

        DB::table('sms_templates')->where('language', 'en')->update([
            'opt_out_text' => 'Reply STOP to opt-out',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropColumn('opt_out_text');
        });
    }
};
