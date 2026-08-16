<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the new order-accepted-offline email template (PL+EN = 2 rows).
     *
     * Same reason as 2026_08_12_120000_seed_order_handover_return_email_templates.php /
     * 2026_08_14_160000_seed_rental_return_reminder_email_templates.php: EmailTemplateSeeder
     * only ever runs once per stack, at first-tenant provisioning
     * (ProvisionTenantCommand::runGlobalSeedersOnce()). Every already-provisioned stack —
     * including UAT's `budowlana` — would otherwise never receive this key, and the first
     * offline-settlement checkout would fail sending its acceptance email with "template
     * not found" straight into failed_jobs, unmonitored.
     *
     * insertOrIgnore() against the (organization_id, key, language) unique
     * (2026_08_08_100001_scope_template_uniques_to_organization.php) makes this a pure
     * addition: explicit organization_id => null targets only the global row.
     */
    public function up(): void
    {
        $templates = [
            [
                'organization_id' => null,
                'key' => 'order-accepted-offline',
                'language' => 'pl',
                'subject' => 'Zamówienie #{{order_number}} przyjęte — zapłata przy odbiorze — {{app_name}}',
                'html_body' => '<h1>Zamówienie przyjęte!</h1><p>Cześć {{customer_name}},</p><p>Twoje zamówienie numer <strong>#{{order_number}}</strong> zostało zarezerwowane. Zapłacisz przy odbiorze sprzętu — gotówką lub przelewem. Poniżej znajdziesz szczegóły wynajmu:</p>{{items_list_html}}<p style="margin-top:16px;"><strong>Do zapłaty przy odbiorze:</strong> {{total_amount}} zł</p>{{deposit_amount}}<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;"><p><strong>Rezerwacja ważna do:</strong> {{hold_until}}<br>Po tym czasie zamówienie zostanie automatycznie anulowane.</p><p><strong>Miejsce odbioru sprzętu:</strong><br>{{pickup_address}}<br>{{pickup_phone}}</p><p>Zachowaj tę wiadomość — przyda się przy odbiorze sprzętu.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Szczegóły zamówienia</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>',
                'text_body' => 'Zamówienie przyjęte! Cześć {{customer_name}}, Twoje zamówienie nr #{{order_number}} zostało zarezerwowane. Zapłacisz przy odbiorze — gotówką lub przelewem.

Sprzęt:
{{items_list_text}}

Do zapłaty przy odbiorze: {{total_amount}} zł
{{deposit_amount}}
Rezerwacja ważna do: {{hold_until}} — po tym czasie zamówienie zostanie automatycznie anulowane.

Miejsce odbioru: {{pickup_address}}
Telefon: {{pickup_phone}}

Szczegóły zamówienia: {{orders_url}}

Pozdrawiamy, Zespół {{app_name}}',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'total_amount', 'hold_until', 'orders_url', 'app_name', 'items_list_html', 'items_list_text', 'deposit_amount', 'pickup_address', 'pickup_phone']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => null,
                'key' => 'order-accepted-offline',
                'language' => 'en',
                'subject' => 'Order #{{order_number}} received — pay at pickup — {{app_name}}',
                'html_body' => '<h1>Order received!</h1><p>Hello {{customer_name}},</p><p>Your order number <strong>#{{order_number}}</strong> has been reserved. You will pay in cash or by bank transfer when you pick up the equipment. Here are your rental details:</p>{{items_list_html}}<p style="margin-top:16px;"><strong>Amount due at pickup:</strong> {{total_amount}} PLN</p>{{deposit_amount}}<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;"><p><strong>Reservation valid until:</strong> {{hold_until}}<br>After this time the order will be automatically cancelled.</p><p><strong>Equipment pickup location:</strong><br>{{pickup_address}}<br>{{pickup_phone}}</p><p>Keep this email — you will need it when collecting the equipment.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Order Details</a></p><p>Best regards,<br>The {{app_name}} Team</p>',
                'text_body' => 'Order received! Hello {{customer_name}}, Your order #{{order_number}} has been reserved. You will pay in cash or by bank transfer at pickup.

Items:
{{items_list_text}}

Amount due at pickup: {{total_amount}} PLN
{{deposit_amount}}
Reservation valid until: {{hold_until}} — after this time the order will be automatically cancelled.

Pickup location: {{pickup_address}}
Phone: {{pickup_phone}}

Order details: {{orders_url}}

Best regards, The {{app_name}} Team',
                'blade_path' => null,
                'variables' => json_encode(['customer_name', 'order_number', 'total_amount', 'hold_until', 'orders_url', 'app_name', 'items_list_html', 'items_list_text', 'deposit_amount', 'pickup_address', 'pickup_phone']),
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
     * Removes only the exact rows this migration inserted: the new key,
     * global row only (organization_id IS NULL) — never a tenant's own
     * override, and never any other template.
     */
    public function down(): void
    {
        DB::table('email_templates')
            ->where('key', 'order-accepted-offline')
            ->whereNull('organization_id')
            ->delete();
    }
};
