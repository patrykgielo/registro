<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * GDPR Compliance: Track SMS consent for audit trail and compliance.
     * Required by GDPR Article 7 (proof of consent).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SMS consent tracking (GDPR Article 7)
            $table->timestamp('sms_consent_given_at')->nullable()->after('remember_token');
            $table->string('sms_consent_ip', 45)->nullable()->after('sms_consent_given_at'); // IPv6-compatible
            $table->string('sms_consent_user_agent')->nullable()->after('sms_consent_ip');

            // SMS opt-out tracking (GDPR Article 21 - right to object)
            $table->timestamp('sms_opted_out_at')->nullable()->after('sms_consent_user_agent');
            $table->string('sms_opt_out_method', 50)->nullable()->after('sms_opted_out_at'); // 'manual', 'STOP_reply', 'admin'

            // Index for filtering users with SMS consent
            $table->index('sms_consent_given_at', 'idx_users_sms_consent');
            $table->index('sms_opted_out_at', 'idx_users_sms_opted_out');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_sms_consent');
            $table->dropIndex('idx_users_sms_opted_out');

            $table->dropColumn([
                'sms_consent_given_at',
                'sms_consent_ip',
                'sms_consent_user_agent',
                'sms_opted_out_at',
                'sms_opt_out_method',
            ]);
        });
    }
};
