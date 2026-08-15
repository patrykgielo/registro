<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the two new rental-return-reminder email templates
     * (rental-return-due-soon, rental-return-overdue; PL+EN = 4 rows)
     * introduced by feature/rental-return-reminders.
     *
     * Same reason as 2026_08_12_120000_seed_order_handover_return_email_templates.php:
     * EmailTemplateSeeder only ever runs once per stack, at first-tenant provisioning
     * (ProvisionTenantCommand::runGlobalSeedersOnce(), gated by TenantProvisioningState
     * so a re-run never overwrites a tenant's customized templates). Every
     * already-provisioned stack — including UAT's `budowlana` — would otherwise never
     * receive these two keys, and the first reminder send would fail with "template not
     * found" straight into failed_jobs, unmonitored.
     *
     * insertOrIgnore() against the (organization_id, key, language) unique
     * (2026_08_08_100001_scope_template_uniques_to_organization.php) makes this a pure
     * addition: explicit organization_id => null targets only the global row, so a
     * re-run — or a stack where a tenant has already created their own override for one
     * of these brand-new keys — never touches or overwrites anything that already exists.
     */
    public function up(): void
    {
        $templates = [
            [
                'organization_id' => null,
                'key' => 'rental-return-due-soon',
                'language' => 'pl',
                'subject' => 'Przypomnienie: jutro zwrot sprzętu — zamówienie #{{order_number}}',
                'html_body' => '<h1>Przypomnienie o zwrocie</h1><p>Cześć {{customer_name}},</p><p>Przypominamy, że jutro (<strong>{{return_date}}</strong>) kończy się okres wypożyczenia sprzętu <strong>{{service_name}}</strong> z zamówienia numer #{{order_number}}.</p><p>Prosimy o zwrot sprzętu w umówionym terminie.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Szczegóły zamówienia</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Przypomnienie o zwrocie. Cześć {{customer_name}}, przypominamy, że jutro ({{return_date}}) kończy się okres wypożyczenia sprzętu {{service_name}} z zamówienia nr #{{order_number}}.

Prosimy o zwrot sprzętu w umówionym terminie.

Szczegóły zamówienia: {{orders_url}}

Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'return_date', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'rental-return-due-soon',
                'language' => 'en',
                'subject' => 'Reminder: equipment return due tomorrow — Order #{{order_number}}',
                'html_body' => '<h1>Return reminder</h1><p>Hello {{customer_name}},</p><p>This is a reminder that the rental period for <strong>{{service_name}}</strong> from order #{{order_number}} ends tomorrow (<strong>{{return_date}}</strong>).</p><p>Please return the equipment on time.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Order Details</a></p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Return reminder. Hello {{customer_name}}, this is a reminder that the rental period for {{service_name}} from order #{{order_number}} ends tomorrow ({{return_date}}).

Please return the equipment on time.

Order details: {{orders_url}}

Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'return_date', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'rental-return-overdue',
                'language' => 'pl',
                'subject' => 'Zwrot sprzętu po terminie — zamówienie #{{order_number}}',
                'html_body' => '<h1>Zwrot sprzętu jest po terminie</h1><p>Cześć {{customer_name}},</p><p>Okres wypożyczenia sprzętu <strong>{{service_name}}</strong> z zamówienia numer #{{order_number}} zakończył się <strong>{{return_date}}</strong> ({{days_overdue}} dni temu), a sprzęt nie został jeszcze zwrócony.</p><p>Prosimy o jak najszybszy zwrot lub kontakt z nami.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Szczegóły zamówienia</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Zwrot sprzętu jest po terminie. Cześć {{customer_name}}, okres wypożyczenia sprzętu {{service_name}} z zamówienia nr #{{order_number}} zakończył się {{return_date}} ({{days_overdue}} dni temu), a sprzęt nie został jeszcze zwrócony.

Prosimy o jak najszybszy zwrot lub kontakt z nami.

Szczegóły zamówienia: {{orders_url}}

Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'return_date', 'days_overdue', 'orders_url', 'app_name']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'rental-return-overdue',
                'language' => 'en',
                'subject' => 'Equipment return overdue — Order #{{order_number}}',
                'html_body' => '<h1>Your equipment return is overdue</h1><p>Hello {{customer_name}},</p><p>The rental period for <strong>{{service_name}}</strong> from order #{{order_number}} ended on <strong>{{return_date}}</strong> ({{days_overdue}} days ago), and the equipment has not yet been returned.</p><p>Please return it as soon as possible, or get in touch with us.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Order Details</a></p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Your equipment return is overdue. Hello {{customer_name}}, the rental period for {{service_name}} from order #{{order_number}} ended on {{return_date}} ({{days_overdue}} days ago), and the equipment has not yet been returned.

Please return it as soon as possible, or get in touch with us.

Order details: {{orders_url}}

Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'service_name', 'return_date', 'days_overdue', 'orders_url', 'app_name']),
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
     * Removes only the exact rows this migration inserted: the two new keys,
     * global row only (organization_id IS NULL) — never a tenant's own
     * override, and never any other template.
     */
    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('key', ['rental-return-due-soon', 'rental-return-overdue'])
            ->whereNull('organization_id')
            ->delete();
    }
};
