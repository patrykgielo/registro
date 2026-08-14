<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The order-paid HTML template concatenated {{pickup_address}}{{pickup_phone}} with no
     * separator. Dormant since the template was written (2026-08-08, PR #? seeding this key)
     * because pickup_address/pickup_phone always resolved to empty strings — the settings-store
     * disconnect fixed by this same branch (feature/settings-store-disconnect, 2026-08-14; see
     * order-notifications.md). Now that both variables actually carry values, an unfixed template
     * would render "…00-100 Warszawa+48123123123" glued together in every order-paid HTML email.
     * The text_body sibling was never affected — it already puts each on its own line with its
     * own label ("Miejsce odbioru: …" / "Telefon: …").
     *
     * Same reason as 2026_08_12_120000_seed_order_handover_return_email_templates.php:
     * EmailTemplateSeeder only ever runs once per stack, at first-tenant provisioning. Every
     * already-provisioned stack needs this correction applied directly to its stored row.
     *
     * Exact-value WHERE match (html_body = the precise old string) means:
     *  - A tenant that customised their own order-paid html_body (organization_id IS NOT NULL,
     *    or a global row an operator hand-edited to different content) never matches and is left
     *    untouched — this migration only ever "fixes forward" the row it recognizes exactly.
     *  - Re-running (or running on a stack that already has the corrected content some other way)
     *    is a safe no-op — nothing matches, update() returns 0.
     *
     * Header-with-no-address-under-it decision: the templating engine (EmailTemplate::render(),
     * see its docblock) is intentionally literal-substitution-only — no conditionals, and every
     * substituted value is HTML-escaped, so a variable cannot carry its own <br>/<p> markup to
     * hide itself either. Making the "Miejsce odbioru sprzętu:" heading disappear when a tenant
     * has configured no contact info at all would require either template-engine conditionals or
     * loosening that escaping for specific "trusted HTML fragment" variables — both are separate,
     * security-relevant changes (the escaping exists specifically because html_body is editable by
     * tenant-level admins), out of scope here. Decision: the heading stays unconditional; this
     * migration only removes the glued-values regression, not that pre-existing rough edge.
     */
    public function up(): void
    {
        $this->apply('pl', self::htmlPl(before: true), self::htmlPl(before: false));
        $this->apply('en', self::htmlEn(before: true), self::htmlEn(before: false));
    }

    public function down(): void
    {
        $this->apply('pl', self::htmlPl(before: false), self::htmlPl(before: true));
        $this->apply('en', self::htmlEn(before: false), self::htmlEn(before: true));
    }

    private function apply(string $language, string $fromHtml, string $toHtml): void
    {
        DB::table('email_templates')
            ->where('key', 'order-paid')
            ->where('language', $language)
            ->whereNull('organization_id')
            ->where('html_body', $fromHtml)
            ->update(['html_body' => $toHtml, 'updated_at' => now()]);
    }

    private static function htmlPl(bool $before): string
    {
        $separator = $before ? '' : '<br>';

        return '<h1>Dziękujemy za zamówienie!</h1><p>Cześć {{customer_name}},</p><p>Twoje zamówienie numer <strong>#{{order_number}}</strong> zostało opłacone. Poniżej znajdziesz szczegóły wynajmu:</p>{{items_list_html}}<p style="margin-top:16px;"><strong>Suma za wynajem:</strong> {{total_amount}} zł</p>{{deposit_amount}}<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;"><p><strong>Miejsce odbioru sprzętu:</strong><br>{{pickup_address}}'.$separator.'{{pickup_phone}}</p><p>Zachowaj tę wiadomość — przyda się przy odbiorze sprzętu.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Szczegóły zamówienia</a></p><p>Pozdrawiamy,<br>Zespół {{app_name}}</p>';
    }

    private static function htmlEn(bool $before): string
    {
        $separator = $before ? '' : '<br>';

        return '<h1>Thank you for your order!</h1><p>Hello {{customer_name}},</p><p>Your order number <strong>#{{order_number}}</strong> has been paid. Here are your rental details:</p>{{items_list_html}}<p style="margin-top:16px;"><strong>Rental total:</strong> {{total_amount}} PLN</p>{{deposit_amount}}<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;"><p><strong>Equipment pickup location:</strong><br>{{pickup_address}}'.$separator.'{{pickup_phone}}</p><p>Keep this email — you will need it when collecting the equipment.</p><p><a href="{{orders_url}}" style="background-color:#3D8A94;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Order Details</a></p><p>Best regards,<br>The {{app_name}} Team</p>';
    }
};
