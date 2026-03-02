<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop legacy boolean reminder flags from appointments table.
 *
 * These columns are replaced by the reminder_logs table which provides:
 * - Idempotency via message_key
 * - Full history (not just boolean sent/not-sent)
 * - Support for unlimited reminder types
 *
 * Related: reminder_configs + reminder_logs tables
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop indexes first — required for SQLite compatibility in tests.
        // SQLite cannot drop columns that have indexes referencing them.
        $indexes = [
            'appointments_sent_24h_reminder_index',
            'appointments_sent_2h_reminder_index',
            'appointments_sent_followup_index',
            'appointments_sent_24h_reminder_sms_index',
            'appointments_sent_2h_reminder_sms_index',
            'appointments_sent_followup_sms_index',
        ];

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ($indexes as $index) {
                DB::statement("DROP INDEX IF EXISTS \"{$index}\"");
            }
        } else {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropIndex(['sent_24h_reminder']);
                $table->dropIndex(['sent_2h_reminder']);
                $table->dropIndex(['sent_followup']);
                $table->dropIndex(['sent_24h_reminder_sms']);
                $table->dropIndex(['sent_2h_reminder_sms']);
                $table->dropIndex(['sent_followup_sms']);
            });
        }

        // Drop email reminder columns (separate calls for SQLite compatibility)
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'sent_24h_reminder',
                'sent_2h_reminder',
                'sent_followup',
            ]);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'sent_24h_reminder_sms',
                'sent_2h_reminder_sms',
                'sent_followup_sms',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('sent_24h_reminder')->default(false)->after('vehicle_custom_model');
            $table->boolean('sent_2h_reminder')->default(false)->after('sent_24h_reminder');
            $table->boolean('sent_followup')->default(false)->after('sent_2h_reminder');

            $table->index('sent_24h_reminder');
            $table->index('sent_2h_reminder');
            $table->index('sent_followup');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('sent_24h_reminder_sms')->default(false)->after('sent_followup');
            $table->boolean('sent_2h_reminder_sms')->default(false)->after('sent_24h_reminder_sms');
            $table->boolean('sent_followup_sms')->default(false)->after('sent_2h_reminder_sms');

            $table->index('sent_24h_reminder_sms');
            $table->index('sent_2h_reminder_sms');
            $table->index('sent_followup_sms');
        });
    }
};
