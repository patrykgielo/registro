<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seeds email templates for the rental extension request flow
     * (TemplateKey::RENTAL_EXTENSION_REQUESTED/APPROVED/REJECTED).
     * Without these rows, EmailService::sendFromTemplate() throws and the
     * ShouldQueue notifications fail silently into the failed-jobs queue.
     *
     * Idempotency: Uses insertOrIgnore() with unique constraint on (key, language).
     */
    public function up(): void
    {
        $templates = [
            // 1. Rental Extension Requested (to tenant admin)
            [
                'key' => 'rental-extension-requested',
                'language' => 'pl',
                'subject' => 'Nowy wniosek o przedłużenie wynajmu - {{order_number}}',
                'html_body' => '<h1>Nowy wniosek o przedłużenie</h1><p>Klient złożył wniosek o przedłużenie wynajmu w zamówieniu <strong>{{order_number}}</strong>.</p><p><strong>Szczegóły wniosku:</strong><br>Pozycja: {{service_name}}<br>Aktualna data końca: {{original_end_date}}<br>Żądana data końca: {{requested_end_date}}<br>Dodatkowe dni: {{additional_days}}<br>Dodatkowa kwota: {{additional_amount}} zł</p><p style="margin: 30px 0;"><a href="{{admin_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Przejdź do wniosków</a></p><p>Pozdrawiamy,<br>System {{app_name}}</p>',
                'text_body' => 'Nowy wniosek o przedłużenie. Klient złożył wniosek o przedłużenie wynajmu w zamówieniu {{order_number}}. Pozycja: {{service_name}}, Aktualna data końca: {{original_end_date}}, Żądana data końca: {{requested_end_date}}, Dodatkowe dni: {{additional_days}}, Dodatkowa kwota: {{additional_amount}} zł. Przejdź do wniosków: {{admin_url}}. System {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['order_number', 'service_name', 'original_end_date', 'requested_end_date', 'additional_days', 'additional_amount', 'admin_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'rental-extension-requested',
                'language' => 'en',
                'subject' => 'New rental extension request - {{order_number}}',
                'html_body' => '<h1>New Extension Request</h1><p>A customer has requested a rental extension on order <strong>{{order_number}}</strong>.</p><p><strong>Request details:</strong><br>Item: {{service_name}}<br>Current end date: {{original_end_date}}<br>Requested end date: {{requested_end_date}}<br>Additional days: {{additional_days}}<br>Additional amount: {{additional_amount}} PLN</p><p style="margin: 30px 0;"><a href="{{admin_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Go to requests</a></p><p>Best regards,<br>{{app_name}} System</p>',
                'text_body' => 'New Extension Request. A customer has requested a rental extension on order {{order_number}}. Item: {{service_name}}, Current end date: {{original_end_date}}, Requested end date: {{requested_end_date}}, Additional days: {{additional_days}}, Additional amount: {{additional_amount}} PLN. Go to requests: {{admin_url}}. {{app_name}} System',
                'blade_path' => null,
                'variables' => json_encode(['order_number', 'service_name', 'original_end_date', 'requested_end_date', 'additional_days', 'additional_amount', 'admin_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 2. Rental Extension Approved (to customer)
            [
                'key' => 'rental-extension-approved',
                'language' => 'pl',
                'subject' => 'Przedłużenie wynajmu zatwierdzone - {{order_number}}',
                'html_body' => '<h1>Cześć {{customer_name}}!</h1><p>Twój wniosek o przedłużenie wynajmu w zamówieniu <strong>{{order_number}}</strong> został zatwierdzony.</p><p><strong>Szczegóły:</strong><br>Pozycja: {{service_name}}<br>Nowa data końca: {{new_end_date}}<br>Dodatkowe dni: {{additional_days}}<br>Dodatkowa kwota: {{additional_amount}} zł</p><p style="margin: 30px 0;"><a href="{{orders_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Zobacz zamówienie</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}! Twój wniosek o przedłużenie wynajmu w zamówieniu {{order_number}} został zatwierdzony. Pozycja: {{service_name}}, Nowa data końca: {{new_end_date}}, Dodatkowe dni: {{additional_days}}, Dodatkowa kwota: {{additional_amount}} zł. Zobacz zamówienie: {{orders_url}}. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'new_end_date', 'additional_days', 'additional_amount', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'rental-extension-approved',
                'language' => 'en',
                'subject' => 'Rental extension approved - {{order_number}}',
                'html_body' => '<h1>Hello {{customer_name}}!</h1><p>Your rental extension request for order <strong>{{order_number}}</strong> has been approved.</p><p><strong>Details:</strong><br>Item: {{service_name}}<br>New end date: {{new_end_date}}<br>Additional days: {{additional_days}}<br>Additional amount: {{additional_amount}} PLN</p><p style="margin: 30px 0;"><a href="{{orders_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">View order</a></p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}! Your rental extension request for order {{order_number}} has been approved. Item: {{service_name}}, New end date: {{new_end_date}}, Additional days: {{additional_days}}, Additional amount: {{additional_amount}} PLN. View order: {{orders_url}}. Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'new_end_date', 'additional_days', 'additional_amount', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 3. Rental Extension Rejected (to customer)
            [
                'key' => 'rental-extension-rejected',
                'language' => 'pl',
                'subject' => 'Przedłużenie wynajmu odrzucone - {{order_number}}',
                'html_body' => '<h1>Cześć {{customer_name}},</h1><p>Niestety Twój wniosek o przedłużenie wynajmu w zamówieniu <strong>{{order_number}}</strong> został odrzucony.</p><p><strong>Szczegóły:</strong><br>Pozycja: {{service_name}}<br>Żądana data końca: {{requested_end_date}}<br>Powód odrzucenia: {{rejection_reason}}</p><p style="margin: 30px 0;"><a href="{{orders_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Zobacz zamówienie</a></p><p>Jeśli masz pytania, skontaktuj się z nami.</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Cześć {{customer_name}}, Niestety Twój wniosek o przedłużenie wynajmu w zamówieniu {{order_number}} został odrzucony. Pozycja: {{service_name}}, Żądana data końca: {{requested_end_date}}, Powód odrzucenia: {{rejection_reason}}. Zobacz zamówienie: {{orders_url}}. Jeśli masz pytania, skontaktuj się z nami. Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'requested_end_date', 'rejection_reason', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'rental-extension-rejected',
                'language' => 'en',
                'subject' => 'Rental extension rejected - {{order_number}}',
                'html_body' => '<h1>Hello {{customer_name}},</h1><p>Unfortunately, your rental extension request for order <strong>{{order_number}}</strong> has been rejected.</p><p><strong>Details:</strong><br>Item: {{service_name}}<br>Requested end date: {{requested_end_date}}<br>Rejection reason: {{rejection_reason}}</p><p style="margin: 30px 0;"><a href="{{orders_url}}" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">View order</a></p><p>If you have any questions, please contact us.</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Hello {{customer_name}}, Unfortunately, your rental extension request for order {{order_number}} has been rejected. Item: {{service_name}}, Requested end date: {{requested_end_date}}, Rejection reason: {{rejection_reason}}. View order: {{orders_url}}. If you have any questions, please contact us. Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'requested_end_date', 'rejection_reason', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($templates as $template) {
            DB::table('email_templates')->insertOrIgnore($template);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Deletes all seeded rental extension email templates.
     */
    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('key', [
                'rental-extension-requested',
                'rental-extension-approved',
                'rental-extension-rejected',
            ])
            ->delete();
    }
};
