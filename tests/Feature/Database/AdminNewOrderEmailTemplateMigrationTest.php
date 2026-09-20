<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\EmailTemplate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins database/migrations/2026_09_20_100000_enrich_admin_new_order_email_template.php.
 *
 * Same situation as OrderPaidPickupHtmlSeparatorMigrationTest (see its own docblock for the
 * full mechanism): `admin-new-order` is NOT seeded by any base data migration, only by
 * EmailTemplateSeeder at first-tenant provisioning. This migration's up() therefore runs
 * against an EMPTY table during RefreshDatabase's initial migrate:fresh (TestReferenceDataSeeder
 * hasn't inserted the row yet) and matches nothing — a real, unobservable-from-inside-a-test
 * no-op. EmailTemplateSeeder.php was updated in the same change to write the enriched body
 * directly, so every test's baseline already has the new content regardless of this migration.
 * Each test below first overwrites the row back to the pre-enrichment content (inside its own
 * transaction, rolled back afterward) to actually exercise the migration in isolation.
 *
 * Runs entirely against SQLite via the normal test harness (.env.testing) — never dev MySQL.
 */
class AdminNewOrderEmailTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_20_100000_enrich_admin_new_order_email_template.php';

    private const OLD_HTML_PL = '<h1>Nowe zamówienie!</h1><p>Otrzymałeś nowe zamówienie w systemie {{app_name}}.</p><p><strong>Numer zamówienia:</strong> #{{order_number}}<br><strong>Klient:</strong> {{customer_name}}<br><strong>Kwota:</strong> {{total_amount}} zł</p><p>Zaloguj się do panelu administracyjnego, aby potwierdzić zamówienie:</p><p><a href="{{admin_url}}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Panel administracyjny</a></p><p>Pozdrawiamy,<br>System {{app_name}}</p>';

    private const OLD_TEXT_PL = 'Nowe zamówienie! Zamówienie nr #{{order_number}} od {{customer_name}}. Kwota: {{total_amount}} zł. Zaloguj się do panelu: {{admin_url}}. System {{app_name}}';

    private const OLD_HTML_EN = '<h1>New Order!</h1><p>You have received a new order in {{app_name}}.</p><p><strong>Order number:</strong> #{{order_number}}<br><strong>Customer:</strong> {{customer_name}}<br><strong>Amount:</strong> {{total_amount}} PLN</p><p>Log in to the admin panel to confirm the order:</p><p><a href="{{admin_url}}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Admin Panel</a></p><p>Best regards,<br>{{app_name}} System</p>';

    private const OLD_TEXT_EN = 'New Order! Order #{{order_number}} from {{customer_name}}. Amount: {{total_amount}} PLN. Log in to admin panel: {{admin_url}}. {{app_name}} System';

    private const OLD_VARIABLES = ['customer_name', 'order_number', 'total_amount', 'admin_url', 'app_name'];

    private function currentGlobalRow(string $language): EmailTemplate
    {
        return EmailTemplate::withoutGlobalScope('organization')
            ->where('key', 'admin-new-order')
            ->where('language', $language)
            ->whereNull('organization_id')
            ->firstOrFail();
    }

    private function revertGlobalRowToOldContent(string $language): void
    {
        $row = $this->currentGlobalRow($language);

        DB::table('email_templates')->where('id', $row->id)->update([
            'html_body' => $language === 'en' ? self::OLD_HTML_EN : self::OLD_HTML_PL,
            'text_body' => $language === 'en' ? self::OLD_TEXT_EN : self::OLD_TEXT_PL,
            'variables' => json_encode(self::OLD_VARIABLES),
        ]);
    }

    public function test_up_enriches_the_row_for_an_already_provisioned_tenant(): void
    {
        $this->revertGlobalRowToOldContent('pl');
        $this->revertGlobalRowToOldContent('en');

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $pl = $this->currentGlobalRow('pl');
        $en = $this->currentGlobalRow('en');

        foreach ([$pl, $en] as $row) {
            $this->assertStringContainsString('{{payment_note}}', $row->html_body);
            $this->assertStringContainsString('{{items_list_html}}', $row->html_body);
            $this->assertStringContainsString('{{pickup_address}}', $row->html_body);
            $this->assertStringContainsString('{{payment_note}}', $row->text_body);
            $this->assertContains('payment_note', $row->variables);
            $this->assertContains('items_list_html', $row->variables);
            $this->assertContains('pickup_address', $row->variables);
        }

        $this->assertNotSame(self::OLD_HTML_PL, $pl->html_body);
        $this->assertNotSame(self::OLD_HTML_EN, $en->html_body);
    }

    /**
     * See OrderPaidPickupHtmlSeparatorMigrationTest's identically-named test for why this
     * name is accurate despite the rollback+migrate pair inside it: the real no-op already
     * happened, unobservably, during RefreshDatabase's initial migrate:fresh.
     */
    public function test_up_is_a_no_op_when_the_row_already_has_the_enriched_content(): void
    {
        $before = $this->currentGlobalRow('pl');

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $after = $this->currentGlobalRow('pl');
        $this->assertSame($before->html_body, $after->html_body);
        $this->assertSame($before->text_body, $after->text_body);
        $this->assertSame($before->variables, $after->variables);
    }

    public function test_down_restores_the_exact_old_content(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $pl = $this->currentGlobalRow('pl');
        $en = $this->currentGlobalRow('en');

        $this->assertSame(self::OLD_HTML_PL, $pl->html_body);
        $this->assertSame(self::OLD_TEXT_PL, $pl->text_body);
        $this->assertSame(self::OLD_VARIABLES, $pl->variables);
        $this->assertSame(self::OLD_HTML_EN, $en->html_body);
        $this->assertSame(self::OLD_TEXT_EN, $en->text_body);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    /**
     * A tenant that customised their own admin-new-order template (organization_id IS
     * NOT NULL) must never be touched — the migration's WHERE clause explicitly scopes
     * to whereNull('organization_id').
     */
    public function test_a_tenants_own_customised_override_is_never_touched(): void
    {
        $org = Organization::factory()->create();

        $override = EmailTemplate::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'key' => 'admin-new-order',
            'language' => 'pl',
            'subject' => 'Custom subject',
            'html_body' => '<p>Custom '.self::OLD_HTML_PL.'</p>',
            'text_body' => 'Custom',
            'variables' => self::OLD_VARIABLES,
            'active' => true,
        ]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(
            '<p>Custom '.self::OLD_HTML_PL.'</p>',
            $override->fresh()->html_body,
            'a tenant-specific override must never be rewritten by this migration'
        );
    }

    /**
     * An operator who hand-edited the GLOBAL row to different content entirely (not the
     * exact pre-enrichment string this migration recognizes) must not have that edit
     * silently overwritten.
     */
    public function test_a_hand_edited_global_row_with_different_content_is_never_touched(): void
    {
        $row = $this->currentGlobalRow('pl');
        $handEdited = '<p>Completely custom content, not what this migration expects at all.</p>';
        DB::table('email_templates')->where('id', $row->id)->update(['html_body' => $handEdited]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame($handEdited, $row->fresh()->html_body);
    }
}
