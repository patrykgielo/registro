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
        Schema::table('users', function (Blueprint $table) {
            // User limits (admin-configurable per user)
            $table->unsignedTinyInteger('max_vehicles')->default(1)->after('sms_opt_out_method');
            $table->unsignedTinyInteger('max_addresses')->default(1)->after('max_vehicles');

            // Email marketing consent (opt-in required)
            $table->timestamp('email_marketing_consent_at')->nullable()->after('max_addresses');
            $table->string('email_marketing_consent_ip', 45)->nullable()->after('email_marketing_consent_at');
            $table->timestamp('email_marketing_opted_out_at')->nullable()->after('email_marketing_consent_ip');

            // Email newsletter consent (opt-in required)
            $table->timestamp('email_newsletter_consent_at')->nullable()->after('email_marketing_opted_out_at');
            $table->string('email_newsletter_consent_ip', 45)->nullable()->after('email_newsletter_consent_at');
            $table->timestamp('email_newsletter_opted_out_at')->nullable()->after('email_newsletter_consent_ip');

            // SMS marketing consent (extends existing SMS consent)
            $table->timestamp('sms_marketing_consent_at')->nullable()->after('email_newsletter_opted_out_at');
            $table->string('sms_marketing_consent_ip', 45)->nullable()->after('sms_marketing_consent_at');
            $table->timestamp('sms_marketing_opted_out_at')->nullable()->after('sms_marketing_consent_ip');

            // Email change verification flow
            $table->string('pending_email', 255)->nullable()->after('sms_marketing_opted_out_at');
            $table->string('pending_email_token', 64)->nullable()->after('pending_email');
            $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email_token');

            // Account deletion (GDPR Art. 17)
            $table->timestamp('deletion_requested_at')->nullable()->after('pending_email_expires_at');
            $table->string('deletion_token', 64)->nullable()->after('deletion_requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'max_vehicles',
                'max_addresses',
                'email_marketing_consent_at',
                'email_marketing_consent_ip',
                'email_marketing_opted_out_at',
                'email_newsletter_consent_at',
                'email_newsletter_consent_ip',
                'email_newsletter_opted_out_at',
                'sms_marketing_consent_at',
                'sms_marketing_consent_ip',
                'sms_marketing_opted_out_at',
                'pending_email',
                'pending_email_token',
                'pending_email_expires_at',
                'deletion_requested_at',
                'deletion_token',
            ]);
        });
    }
};
