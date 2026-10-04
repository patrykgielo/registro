<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ClickUp 123k99cvc55/123k99cvc56: `admin-new-order` (ADMIN_NEW_ORDER) is now also
     * sent for a pay-at-pickup order (OrderAcceptedOfflineNotification's new 'admin'
     * recipient, app/Providers/AppServiceProvider.php's OrderAcceptedOffline listener),
     * not just OrderPaidNotification's admin branch. Both callers now pass a
     * `{{payment_note}}` variable ("Zapłacono online" / "Płatność przy odbiorze…") plus
     * the same `{{items_list_html}}`/`{{pickup_address}}`/`{{pickup_phone}}` fields the
     * customer-facing order templates already use (BuildsOrderRentalEmailVariables) — the
     * owner previously saw only order number/customer/total, nothing about WHAT was
     * ordered or WHERE it will be picked up.
     *
     * Same reason as every other seed/patch migration for this table
     * (migrations.md → "Nowy email_templates key = też migracja danych"):
     * EmailTemplateSeeder only ever runs once per stack, at first-tenant provisioning.
     * Every already-provisioned stack — including UAT's `budowlana` — keeps the old,
     * unenriched body forever without this migration, and every render leaves the new
     * tokens ({{payment_note}} etc.) untouched literal text the moment the notification
     * code starts sending them (EmailTemplate::substitutePlaceholders() only replaces
     * tokens present in $data that also appear in the stored body — a body that doesn't
     * reference {{payment_note}} yet just never shows it, harmlessly, until this runs).
     *
     * Exact-value WHERE match (html_body = the precise old string), same pattern as
     * 2026_08_14_100000_fix_order_paid_pickup_html_separator.php:
     *  - A tenant that customised their own admin-new-order html_body (organization_id
     *    IS NOT NULL, or a global row an operator hand-edited) never matches and is left
     *    untouched.
     *  - Re-running, or running on a stack that already has this content some other way
     *    (e.g. freshly provisioned via EmailTemplateSeeder, which seeds the enriched body
     *    directly), is a safe no-op — nothing matches, update() returns 0.
     */
    public function up(): void
    {
        $this->apply(
            'pl',
            self::htmlPl(before: true), self::htmlPl(before: false),
            self::textPl(before: true), self::textPl(before: false),
            self::variablesBefore(), self::variablesAfter(),
        );
        $this->apply(
            'en',
            self::htmlEn(before: true), self::htmlEn(before: false),
            self::textEn(before: true), self::textEn(before: false),
            self::variablesBefore(), self::variablesAfter(),
        );
    }

    public function down(): void
    {
        $this->apply(
            'pl',
            self::htmlPl(before: false), self::htmlPl(before: true),
            self::textPl(before: false), self::textPl(before: true),
            self::variablesAfter(), self::variablesBefore(),
        );
        $this->apply(
            'en',
            self::htmlEn(before: false), self::htmlEn(before: true),
            self::textEn(before: false), self::textEn(before: true),
            self::variablesAfter(), self::variablesBefore(),
        );
    }

    private function apply(
        string $language,
        string $fromHtml,
        string $toHtml,
        string $fromText,
        string $toText,
        string $fromVariables,
        string $toVariables,
    ): void {
        DB::table('email_templates')
            ->where('key', 'admin-new-order')
            ->where('language', $language)
            ->whereNull('organization_id')
            ->where('html_body', $fromHtml)
            ->where('text_body', $fromText)
            ->update([
                'html_body' => $toHtml,
                'text_body' => $toText,
                'variables' => $toVariables,
                'updated_at' => now(),
            ]);
    }

    private static function variablesBefore(): string
    {
        return json_encode(['customer_name', 'order_number', 'total_amount', 'admin_url', 'app_name']);
    }

    private static function variablesAfter(): string
    {
        return json_encode(['customer_name', 'order_number', 'total_amount', 'payment_note', 'admin_url', 'app_name', 'items_list_html', 'items_list_text', 'pickup_address', 'pickup_phone']);
    }

    private static function htmlPl(bool $before): string
    {
        if ($before) {
            return '<h1>Nowe zamówienie!</h1><p>Otrzymałeś nowe zamówienie w systemie {{app_name}}.</p><p><strong>Numer zamówienia:</strong> #{{order_number}}<br><strong>Klient:</strong> {{customer_name}}<br><strong>Kwota:</strong> {{total_amount}} zł</p><p>Zaloguj się do panelu administracyjnego, aby potwierdzić zamówienie:</p><p><a href="{{admin_url}}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Panel administracyjny</a></p><p>Pozdrawiamy,<br>System {{app_name}}</p>';
        }

        return '<h1>Nowe zamówienie!</h1><p>Otrzymałeś nowe zamówienie w systemie {{app_name}}.</p><p><strong>Numer zamówienia:</strong> #{{order_number}}<br><strong>Klient:</strong> {{customer_name}}<br><strong>Kwota:</strong> {{total_amount}} zł<br><strong>Płatność:</strong> {{payment_note}}</p>{{items_list_html}}<p><strong>Odbiór:</strong><br>{{pickup_address}}<br>{{pickup_phone}}</p><p>Zaloguj się do panelu administracyjnego, aby potwierdzić zamówienie:</p><p><a href="{{admin_url}}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Panel administracyjny</a></p><p>Pozdrawiamy,<br>System {{app_name}}</p>';
    }

    private static function textPl(bool $before): string
    {
        if ($before) {
            return 'Nowe zamówienie! Zamówienie nr #{{order_number}} od {{customer_name}}. Kwota: {{total_amount}} zł. Zaloguj się do panelu: {{admin_url}}. System {{app_name}}';
        }

        return 'Nowe zamówienie! Zamówienie nr #{{order_number}} od {{customer_name}}. Kwota: {{total_amount}} zł. Płatność: {{payment_note}}.

{{items_list_text}}

Odbiór: {{pickup_address}}, tel. {{pickup_phone}}

Zaloguj się do panelu: {{admin_url}}. System {{app_name}}';
    }

    private static function htmlEn(bool $before): string
    {
        if ($before) {
            return '<h1>New Order!</h1><p>You have received a new order in {{app_name}}.</p><p><strong>Order number:</strong> #{{order_number}}<br><strong>Customer:</strong> {{customer_name}}<br><strong>Amount:</strong> {{total_amount}} PLN</p><p>Log in to the admin panel to confirm the order:</p><p><a href="{{admin_url}}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Admin Panel</a></p><p>Best regards,<br>{{app_name}} System</p>';
        }

        return '<h1>New Order!</h1><p>You have received a new order in {{app_name}}.</p><p><strong>Order number:</strong> #{{order_number}}<br><strong>Customer:</strong> {{customer_name}}<br><strong>Amount:</strong> {{total_amount}} PLN<br><strong>Payment:</strong> {{payment_note}}</p>{{items_list_html}}<p><strong>Pickup:</strong><br>{{pickup_address}}<br>{{pickup_phone}}</p><p>Log in to the admin panel to confirm the order:</p><p><a href="{{admin_url}}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Admin Panel</a></p><p>Best regards,<br>{{app_name}} System</p>';
    }

    private static function textEn(bool $before): string
    {
        if ($before) {
            return 'New Order! Order #{{order_number}} from {{customer_name}}. Amount: {{total_amount}} PLN. Log in to admin panel: {{admin_url}}. {{app_name}} System';
        }

        return 'New Order! Order #{{order_number}} from {{customer_name}}. Amount: {{total_amount}} PLN. Payment: {{payment_note}}.

{{items_list_text}}

Pickup: {{pickup_address}}, phone {{pickup_phone}}

Log in to admin panel: {{admin_url}}. {{app_name}} System';
    }
};
