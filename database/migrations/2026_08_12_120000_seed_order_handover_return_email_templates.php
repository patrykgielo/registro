<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the two new order-lifecycle email templates (order-handed-over,
     * order-returned; PL+EN = 4 rows) introduced by feature/handover-return-emails.
     *
     * Same reason as 2025_12_02_224732_seed_email_templates.php: EmailTemplateSeeder
     * only ever runs once per stack, at first-tenant provisioning
     * (ProvisionTenantCommand::runGlobalSeedersOnce(), gated by
     * TenantProvisioningState so a re-run never overwrites a tenant's customized
     * templates). Every already-provisioned stack — including UAT's `budowlana` —
     * would otherwise never receive these two keys, and the first handover/return
     * would fail with "template not found" straight into failed_jobs, unmonitored.
     *
     * insertOrIgnore() against the (organization_id, key, language) unique
     * (2026_08_08_100001_scope_template_uniques_to_organization.php) makes this a
     * pure addition: explicit organization_id => null targets only the global row,
     * so a re-run — or a stack where a tenant has already created their own
     * override for one of these brand-new keys — never touches or overwrites
     * anything that already exists.
     */
    public function up(): void
    {
        $templates = [
            [
                'organization_id' => null,
                'key' => 'order-handed-over',
                'language' => 'pl',
                'subject' => 'Potwierdzenie wydania sprzętu — zamówienie #{{order_number}}',
                'html_body' => '<h1>Sprzęt został Ci wydany</h1><p>Cześć {{customer_name}},</p><p>Potwierdzamy, że sprzęt z zamówienia numer <strong>#{{order_number}}</strong> został Ci przekazany:</p>{{items_list_html}}<p>Zachowaj tę wiadomość — to potwierdzenie odbioru sprzętu.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Szczegóły zamówienia</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Sprzęt został Ci wydany. Cześć {{customer_name}}, Potwierdzamy, że sprzęt z zamówienia nr #{{order_number}} został Ci przekazany.

{{items_list_text}}

Zachowaj tę wiadomość — to potwierdzenie odbioru sprzętu.

Szczegóły zamówienia: {{orders_url}}

Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'orders_url', 'app_name', 'items_list_html', 'items_list_text']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'order-handed-over',
                'language' => 'en',
                'subject' => 'Handover Confirmation — Order #{{order_number}}',
                'html_body' => '<h1>Your equipment has been handed over</h1><p>Hello {{customer_name}},</p><p>We confirm the equipment from order <strong>#{{order_number}}</strong> has been handed over to you:</p>{{items_list_html}}<p>Keep this email — it is your confirmation of receipt.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Order Details</a></p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Your equipment has been handed over. Hello {{customer_name}}, We confirm the equipment from order #{{order_number}} has been handed over to you.

{{items_list_text}}

Keep this email — it is your confirmation of receipt.

Order details: {{orders_url}}

Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'orders_url', 'app_name', 'items_list_html', 'items_list_text']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'order-returned',
                'language' => 'pl',
                'subject' => 'Potwierdzenie zwrotu sprzętu — zamówienie #{{order_number}}',
                'html_body' => '<h1>Dziękujemy za zwrot sprzętu</h1><p>Cześć {{customer_name}},</p><p>Potwierdzamy, że sprzęt z zamówienia numer <strong>#{{order_number}}</strong> został przez nas odebrany, a wypożyczenie zostało zakończone:</p>{{items_list_html}}<p>Zachowaj tę wiadomość — to potwierdzenie przyjęcia zwrotu.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Szczegóły zamówienia</a></p><p>Dziękujemy za skorzystanie z naszych usług!</p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Dziękujemy za zwrot sprzętu. Cześć {{customer_name}}, Potwierdzamy, że sprzęt z zamówienia nr #{{order_number}} został przez nas odebrany, a wypożyczenie zostało zakończone.

{{items_list_text}}

Zachowaj tę wiadomość — to potwierdzenie przyjęcia zwrotu.

Szczegóły zamówienia: {{orders_url}}

Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'orders_url', 'app_name', 'items_list_html', 'items_list_text']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'order-returned',
                'language' => 'en',
                'subject' => 'Return Confirmation — Order #{{order_number}}',
                'html_body' => '<h1>Thank you for returning your equipment</h1><p>Hello {{customer_name}},</p><p>We confirm the equipment from order <strong>#{{order_number}}</strong> has been received back and the rental is now complete:</p>{{items_list_html}}<p>Keep this email — it is your confirmation that the return was accepted.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Order Details</a></p><p>Thank you for choosing our services!</p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Thank you for returning your equipment. Hello {{customer_name}}, We confirm the equipment from order #{{order_number}} has been received back and the rental is now complete.

{{items_list_text}}

Keep this email — it is your confirmation that the return was accepted.

Order details: {{orders_url}}

Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'orders_url', 'app_name', 'items_list_html', 'items_list_text']),
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
     * override of the same key, and never any other template.
     */
    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('key', ['order-handed-over', 'order-returned'])
            ->whereNull('organization_id')
            ->delete();
    }
};
