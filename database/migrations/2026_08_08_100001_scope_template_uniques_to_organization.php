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
     * Trade-off, already accepted for `categories` in
     * 2026_06_29_120000_fix_tenant_scoped_unique_constraints.php: MySQL and SQLite both
     * treat every NULL as distinct in a unique index, so composite (organization_id, key,
     * language) no longer blocks a second accidental global (NULL-org) row for the same
     * key+language. Nothing in the codebase creates one deliberately — EmailTemplateSeeder
     * and SmsTemplateSeeder both `updateOrCreate` matched on (key, language) alone, which
     * (in the console context seeders run in) ignores tenant scoping entirely and finds the
     * existing global row regardless — and resolveActive() picks deterministically via
     * orderBy even if a duplicate ever existed. A filtered "unique WHERE organization_id IS
     * NULL" index would close that gap but isn't portable across MySQL and SQLite alike, so
     * it is documented here rather than attempted.
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
