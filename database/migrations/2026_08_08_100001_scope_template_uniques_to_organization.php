<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The old (key, language) unique on email_templates/sms_templates made ANY
     * tenant-specific row collide with the global (organization_id NULL) row for the
     * same key+language — so a per-tenant override could never be created for a key
     * that already has a global template, i.e. essentially never. That contradicts
     * organization_id's presence on both models and EmailTemplate/SmsTemplate::resolveActive(),
     * which is written to prefer a tenant override when one exists.
     *
     * THIS DELIBERATELY REVERSES 2026_06_29_120000_fix_tenant_scoped_unique_constraints.php,
     * which explicitly SKIPPED email_templates and sms_templates from this exact composite-unique
     * treatment (see its own docblock), for a real reason: MySQL and SQLite both treat every NULL
     * as distinct in a unique index, so composite (organization_id, key, language) does not stop a
     * second accidental global (NULL-org) row for the same key+language the way the single-column
     * (key, language) unique did.
     *
     * That risk is real only because of HOW the row was found for re-seeding, not because of the
     * unique index shape itself. Before this migration, EmailTemplateSeeder and SmsTemplateSeeder
     * both `updateOrCreate`'d matched on (key, language) alone, unscoped by organization_id — safe
     * only because at most one row could ever exist per key+language. Once tenant overrides became
     * possible, that same unscoped match could hit and overwrite a TENANT'S override with generic
     * seed content, or create a duplicate global row, with no deterministic tie-break. Both seeders
     * were fixed in the same change as this migration to match `organization_id IS NULL` explicitly
     * (`EmailTemplate::withoutGlobalScope('organization')->updateOrCreate([..., 'organization_id' =>
     * null], ...)`), which makes re-seeding always target the global row and only the global row —
     * closing the exact gap the 2026-06-29 migration was avoiding, not just accepting it. That is
     * what makes doing the opposite of that migration's decision safe here.
     *
     * A filtered "unique WHERE organization_id IS NULL" index would close the gap at the schema
     * level too and isn't portable across MySQL and SQLite alike, so the seeder-level fix is the
     * one actually relied on; this is documented, not attempted.
     */
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique('email_templates_key_language_unique');
            $table->unique(['organization_id', 'key', 'language'], 'email_templates_org_key_language_unique');
        });

        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropUnique('sms_templates_key_language_unique');
            $table->unique(['organization_id', 'key', 'language'], 'sms_templates_org_key_language_unique');
        });
    }

    public function down(): void
    {
        // Cannot roll back once any tenant override exists: restoring the global (key,
        // language) unique fails with a duplicate-entry error the moment a tenant row
        // shares a key+language with the global template it was created to override.
        throw new \RuntimeException(
            'Cannot roll back: tenant-specific template overrides created under the composite unique would collide with the restored global (key, language) unique constraint.'
        );
    }
};
