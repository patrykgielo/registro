<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\EmailTemplate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins database/migrations/2026_08_16_120002_seed_order_accepted_offline_email_templates.php
 * in isolation from EmailTemplateSeeder — same reasoning and structure as
 * OrderHandoverReturnEmailTemplateMigrationTest / RentalReturnReminderEmailTemplateMigrationTest:
 * EmailTemplateSeeder only ever runs once per stack, at first-tenant provisioning
 * (ProvisionTenantCommand::runGlobalSeedersOnce()). An already-provisioned stack —
 * including UAT's `budowlana` — would otherwise never receive this key, and the first
 * offline-settlement checkout would silently fail to email the customer.
 *
 * Runs entirely against SQLite via the normal test harness (.env.testing) — never touches
 * dev MySQL.
 */
class OrderAcceptedOfflineEmailTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_16_120002_seed_order_accepted_offline_email_templates.php';

    private const KEY = 'order-accepted-offline';

    public function test_up_inserts_exactly_two_global_rows(): void
    {
        // Already ran once as part of RefreshDatabase's full migrate — this
        // establishes the baseline the rest of the test compares against.
        $rows = EmailTemplate::withoutGlobalScope('organization')
            ->where('key', self::KEY)
            ->whereNull('organization_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['pl', 'en'],
            $rows->pluck('language')->all()
        );
    }

    public function test_down_removes_only_this_key_and_leaves_an_existing_template_untouched(): void
    {
        $control = EmailTemplate::withoutGlobalScope('organization')
            ->where('key', 'order-paid')
            ->where('language', 'pl')
            ->whereNull('organization_id')
            ->firstOrFail();
        $controlUpdatedAt = $control->updated_at;
        $controlHtml = $control->html_body;

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $remaining = EmailTemplate::withoutGlobalScope('organization')
            ->where('key', self::KEY)
            ->count();
        $this->assertSame(0, $remaining, 'down() must remove both seeded rows.');

        $control->refresh();
        $this->assertTrue($controlUpdatedAt->equalTo($control->updated_at), 'An unrelated existing template must not be touched by down().');
        $this->assertSame($controlHtml, $control->html_body);
    }

    public function test_up_after_rollback_restores_the_two_rows_without_touching_the_control_template(): void
    {
        $control = EmailTemplate::withoutGlobalScope('organization')
            ->where('key', 'order-paid')
            ->where('language', 'pl')
            ->whereNull('organization_id')
            ->firstOrFail();
        $controlUpdatedAt = $control->updated_at;

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $rows = EmailTemplate::withoutGlobalScope('organization')
            ->where('key', self::KEY)
            ->whereNull('organization_id')
            ->get();
        $this->assertCount(2, $rows);

        $control->refresh();
        $this->assertTrue($controlUpdatedAt->equalTo($control->updated_at));
    }

    /**
     * A tenant that already customized its OWN override of this brand-new key
     * (edge case, but the composite unique makes it possible) must survive
     * down() — the migration only ever targets organization_id IS NULL.
     */
    public function test_down_never_touches_a_tenant_specific_override_of_the_same_key(): void
    {
        $org = Organization::factory()->create();

        $override = EmailTemplate::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Custom subject',
            'html_body' => '<p>Custom</p>',
            'text_body' => 'Custom',
            'variables' => ['customer_name'],
            'active' => true,
        ]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertDatabaseHas('email_templates', [
            'id' => $override->id,
            'organization_id' => $org->id,
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    /**
     * Directly re-invokes every migration file known to seed `email_templates` in
     * production (bypassing the Migrator's "already run" bookkeeping, since
     * RefreshDatabase already recorded them in this same batch) against a wiped
     * table, then resolves the new key the same way EmailService::sendFromTemplate()
     * does. Proves the key is reachable from production data migrations alone,
     * without EmailTemplateSeeder (which TestReferenceDataSeeder runs once per test
     * process and which every other test in this suite already benefits from — and
     * which would mask a missing migration here).
     */
    public function test_new_template_key_resolves_from_production_migrations_alone(): void
    {
        DB::table('email_templates')->delete();

        $productionSeedMigrations = [
            '2025_12_02_224732_seed_email_templates.php',
            '2026_07_07_000001_seed_rental_extension_email_templates.php',
            '2026_08_02_000001_seed_tenant_registration_email_templates.php',
            basename(self::MIGRATION_PATH),
        ];

        foreach ($productionSeedMigrations as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        foreach (['pl', 'en'] as $language) {
            $this->assertNotNull(
                EmailTemplate::resolveActive(self::KEY, $language),
                'Production data migrations alone must seed ['.self::KEY.":{$language}] — an already-provisioned tenant never runs EmailTemplateSeeder again."
            );
        }
    }
}
