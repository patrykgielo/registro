<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds all SMS templates (production reference data).
     *
     * Replaces SmsTemplateSeeder for production deployments.
     *
     * Creates 14 SMS templates covering 7 event types in 2 languages (PL, EN):
     * 1. appointment-created - Booking created confirmation
     * 2. appointment-confirmed - Admin confirmation
     * 3. appointment-rescheduled - Date/time change notification
     * 4. appointment-cancelled - Cancellation notification
     * 5. appointment-reminder-24h - 24-hour reminder
     * 6. appointment-reminder-2h - 2-hour reminder
     * 7. appointment-followup - Post-service follow-up
     *
     * Idempotency: Uses insertOrIgnore() with unique constraint on (key, language).
     * The sms_templates table has a unique index on ['key', 'language'] ensuring
     * this migration can be run multiple times without creating duplicates.
     *
     * Rollback: down() method removes all templates seeded by this migration.
     *
     * Data Migration Pattern:
     * - Uses DB::table() instead of Eloquent (faster, no ORM overhead)
     * - insertOrIgnore() provides idempotency (relies on existing unique constraint)
     * - Tracked in migrations table (won't run twice)
     * - Standard Laravel practice for production reference data
     *
     * See: docs/guides/data-migrations.md
     */
    public function up(): void
    {
        $templates = [
            // 1. Appointment Created - Booking Confirmation
            [
                'key' => 'appointment-created',
                'language' => 'pl',
                'message_body' => 'Witaj {{customer_name}}! Rezerwacja na {{service_name}} dnia {{appointment_date}} o {{appointment_time}} utworzona. {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-created',
                'language' => 'en',
                'message_body' => 'Hi {{customer_name}}! Your {{service_name}} booking on {{appointment_date}} at {{appointment_time}} is confirmed. {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 2. Appointment Confirmed - Admin Confirmation
            [
                'key' => 'appointment-confirmed',
                'language' => 'pl',
                'message_body' => 'Witaj {{customer_name}}! Twoja wizyta ({{service_name}}) {{appointment_date}} o {{appointment_time}} potwierdzona. Do zobaczenia! {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-confirmed',
                'language' => 'en',
                'message_body' => 'Hi {{customer_name}}! Your appointment ({{service_name}}) on {{appointment_date}} at {{appointment_time}} is confirmed. See you! {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 3. Appointment Rescheduled - Date/Time Change
            [
                'key' => 'appointment-rescheduled',
                'language' => 'pl',
                'message_body' => 'Witaj {{customer_name}}! Twoja wizyta ({{service_name}}) przeniesiona na {{appointment_date}} o {{appointment_time}}. {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-rescheduled',
                'language' => 'en',
                'message_body' => 'Hi {{customer_name}}! Your appointment ({{service_name}}) has been reschedulled to {{appointment_date}} at {{appointment_time}}. {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 4. Appointment Cancelled - Cancellation Notice
            [
                'key' => 'appointment-cancelled',
                'language' => 'pl',
                'message_body' => 'Witaj {{customer_name}}! Twoja wizyta ({{service_name}}) {{appointment_date}} o {{appointment_time}} anulowana. Kontakt: {{contact_phone}}. {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'contact_phone', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-cancelled',
                'language' => 'en',
                'message_body' => 'Hi {{customer_name}}! Your appointment ({{service_name}}) on {{appointment_date}} at {{appointment_time}} has been cancelled. Contact: {{contact_phone}}. {{app_name}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'contact_phone', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 5. Appointment Reminder 24h - Day Before Reminder
            [
                'key' => 'appointment-reminder-24h',
                'language' => 'pl',
                'message_body' => 'Przypomnienie! Jutro masz wizyte: {{service_name}}, {{appointment_date}} o {{appointment_time}}. Lokalizacja: {{location_address}}. {{app_name}}',
                'variables' => json_encode(['service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-reminder-24h',
                'language' => 'en',
                'message_body' => 'Reminder! Your appointment tomorrow: {{service_name}}, {{appointment_date}} at {{appointment_time}}. Location: {{location_address}}. {{app_name}}',
                'variables' => json_encode(['service_name', 'appointment_date', 'appointment_time', 'location_address', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 6. Appointment Reminder 2h - Same Day Reminder
            [
                'key' => 'appointment-reminder-2h',
                'language' => 'pl',
                'message_body' => 'Przypomnienie! Za 2h wizyta: {{service_name}} o {{appointment_time}}. Lokalizacja: {{location_address}}. Do zobaczenia! {{app_name}}',
                'variables' => json_encode(['service_name', 'appointment_time', 'location_address', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-reminder-2h',
                'language' => 'en',
                'message_body' => 'Reminder! In 2 hours: {{service_name}} at {{appointment_time}}. Location: {{location_address}}. See you soon! {{app_name}}',
                'variables' => json_encode(['service_name', 'appointment_time', 'location_address', 'app_name']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 7. Appointment Follow-up - Post-Service Feedback Request
            [
                'key' => 'appointment-followup',
                'language' => 'pl',
                'message_body' => 'Witaj {{customer_name}}! Dziekujemy za skorzystanie z {{service_name}}. Bylibyśmy wdzieczni za opinie. {{app_name}} {{contact_phone}}',
                'variables' => json_encode(['customer_name', 'service_name', 'app_name', 'contact_phone']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-followup',
                'language' => 'en',
                'message_body' => 'Hi {{customer_name}}! Thank you for using {{service_name}}. We would appreciate your feedback. {{app_name}} {{contact_phone}}',
                'variables' => json_encode(['customer_name', 'service_name', 'app_name', 'contact_phone']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insert all templates (idempotent - skips duplicates via unique constraint)
        foreach ($templates as $template) {
            DB::table('sms_templates')->insertOrIgnore($template);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Removes all SMS templates seeded by this migration.
     * Safe rollback - deletes only templates we seeded.
     */
    public function down(): void
    {
        // Delete all SMS templates by key (both PL and EN versions)
        $templateKeys = [
            'appointment-created',
            'appointment-confirmed',
            'appointment-rescheduled',
            'appointment-cancelled',
            'appointment-reminder-24h',
            'appointment-reminder-2h',
            'appointment-followup',
        ];

        DB::table('sms_templates')
            ->whereIn('key', $templateKeys)
            ->delete();
    }
};
