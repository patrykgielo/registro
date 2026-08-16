<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\EmailTemplate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins database/migrations/2026_08_14_100000_fix_order_paid_pickup_html_separator.php.
 *
 * Unlike order-handed-over/order-returned (2026_08_12_120000_...), `order-paid` is NOT
 * seeded by any production data migration at all — only by EmailTemplateSeeder, run once
 * at first-tenant provisioning (a pre-existing gap, reported separately, see
 * OrderHandoverReturnEmailTemplateMigrationTest's own docblock). So an already-provisioned
 * tenant's global order-paid row exists in production with whatever content
 * EmailTemplateSeeder wrote at the time that tenant was provisioned — pre-fix, that is the
 * glued-together html_body. This migration corrects that row directly, by exact-value
 * match, rather than re-seeding it.
 *
 * TestReferenceDataSeeder runs EmailTemplateSeeder exactly ONCE per test process (wired as
 * Tests\TestCase::$seeder, via RefreshDatabase's own --seeder mechanism — see tests.md's
 * "Reference data seeding"), before the first test's transaction begins. It writes the
 * ALREADY-FIXED content directly (EmailTemplateSeeder.php was fixed in the same change) —
 * every RefreshDatabase test's per-test transaction starts from that already-seeded
 * baseline, so the row already has the new separator by the time any test body runs,
 * regardless of whether this migration's up() does anything. Each test below first
 * overwrites the row back to the pre-fix, glued content (inside its own transaction, rolled
 * back afterward — simulating "a tenant provisioned before this fix") to actually exercise
 * the migration in isolation.
 *
 * Runs entirely against SQLite via the normal test harness (.env.testing) — never touches
 * dev MySQL.
 */
class OrderPaidPickupHtmlSeparatorMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_14_100000_fix_order_paid_pickup_html_separator.php';

    private const GLUED_FRAGMENT = '{{pickup_address}}{{pickup_phone}}';

    private const SEPARATED_FRAGMENT = '{{pickup_address}}<br>{{pickup_phone}}';

    private function currentGlobalRow(string $language): EmailTemplate
    {
        return EmailTemplate::withoutGlobalScope('organization')
            ->where('key', 'order-paid')
            ->where('language', $language)
            ->whereNull('organization_id')
            ->firstOrFail();
    }

    /**
     * Simulates "a tenant provisioned before this fix": the stored row still has the
     * pre-fix, glued html_body — swap in a template body containing the glued fragment
     * without disturbing anything else the row already has (subject, text_body, etc.).
     */
    private function revertGlobalRowToGluedContent(string $language): void
    {
        $row = $this->currentGlobalRow($language);
        $gluedHtml = str_replace(self::SEPARATED_FRAGMENT, self::GLUED_FRAGMENT, $row->html_body);

        $this->assertStringContainsString(self::GLUED_FRAGMENT, $gluedHtml, 'test setup sanity check');

        DB::table('email_templates')->where('id', $row->id)->update(['html_body' => $gluedHtml]);
    }

    public function test_up_replaces_the_glued_separator_for_an_already_provisioned_tenant_row(): void
    {
        $this->revertGlobalRowToGluedContent('pl');
        $this->revertGlobalRowToGluedContent('en');

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $pl = $this->currentGlobalRow('pl');
        $en = $this->currentGlobalRow('en');

        $this->assertStringContainsString(self::SEPARATED_FRAGMENT, $pl->html_body);
        $this->assertStringNotContainsString(self::GLUED_FRAGMENT, $pl->html_body);
        $this->assertStringContainsString(self::SEPARATED_FRAGMENT, $en->html_body);
        $this->assertStringNotContainsString(self::GLUED_FRAGMENT, $en->html_body);
    }

    /**
     * Despite the method name, the rollback+migrate below is NOT itself a no-op
     * invocation of up() — down() mutates the row back to glued content first, so
     * the up() call that follows genuinely rewrites it. What this asserts is that
     * the down()/up() pair round-trips back to identical content. The actual
     * no-op this method's name refers to happened once already, earlier: up()
     * ran as part of RefreshDatabase's initial full migrate, before
     * TestReferenceDataSeeder (Tests\TestCase::$seeder) had inserted the
     * order-paid row at all, so it matched nothing and did nothing. That first,
     * real no-op isn't re-observable from inside a test body — this method
     * instead confirms the pair is safe to run a second time on a row that
     * already has the fixed content, which is the closest re-creation available.
     */
    public function test_up_is_a_no_op_when_the_row_already_has_the_fixed_content(): void
    {
        $before = $this->currentGlobalRow('pl');

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $after = $this->currentGlobalRow('pl');
        $this->assertSame($before->html_body, $after->html_body);
    }

    public function test_down_restores_the_exact_glued_content(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $pl = $this->currentGlobalRow('pl');
        $en = $this->currentGlobalRow('en');

        $this->assertStringContainsString(self::GLUED_FRAGMENT, $pl->html_body);
        $this->assertStringNotContainsString(self::SEPARATED_FRAGMENT, $pl->html_body);
        $this->assertStringContainsString(self::GLUED_FRAGMENT, $en->html_body);
        $this->assertStringNotContainsString(self::SEPARATED_FRAGMENT, $en->html_body);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    /**
     * A tenant that customised their own order-paid template (organization_id IS
     * NOT NULL) must never be touched — this migration's WHERE clause explicitly
     * scopes to whereNull('organization_id').
     */
    public function test_a_tenants_own_customised_override_is_never_touched(): void
    {
        $org = Organization::factory()->create();

        $override = EmailTemplate::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'key' => 'order-paid',
            'language' => 'pl',
            'subject' => 'Custom subject',
            'html_body' => '<p>Custom '.self::GLUED_FRAGMENT.'</p>',
            'text_body' => 'Custom',
            'variables' => ['pickup_address', 'pickup_phone'],
            'active' => true,
        ]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(
            '<p>Custom '.self::GLUED_FRAGMENT.'</p>',
            $override->fresh()->html_body,
            'a tenant-specific override must never be rewritten by this migration'
        );
    }

    /**
     * An operator who hand-edited the GLOBAL row to different content entirely
     * (not the exact pre-fix string this migration recognizes) must not have
     * that edit silently overwritten — the exact-value WHERE match is the whole
     * safety mechanism, same convention as every other data migration in this
     * codebase (models.md's "GOTCHA" section, migrations.md).
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
