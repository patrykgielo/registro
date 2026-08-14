<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 2025_12_06_142446_add_prelaunch_settings.php seeded 'contact.logo_path'
     * with a bundled asset ('/images/logo.svg') left over from the codebase
     * this project was migrated from — every existing tenant's pre-launch
     * page has been showing that foreign brand instead of their own name.
     * Only the exact placeholder value is removed, so any tenant who
     * somehow set a real value of their own is left untouched.
     *
     * Uses DB::table(), not the Setting Eloquent model: Setting uses
     * BelongsToOrganization, whose global scope behaves differently depending
     * on whether a tenant happens to be resolved at migrate time (console
     * context usually no-ops it, but "usually" is exactly the shape that
     * silently cut off email templates for every tenant on 2026-08-08 — see
     * .claude/rules/models.md "Globalne wiersze... resolveActive()").
     * DB::table() bypasses Eloquent entirely, so this migration means what
     * it says regardless of that context — mirrors RecalculateDailyStatisticsJob.
     *
     * Deliberately no `organization_id` filter in the WHERE clause below: a
     * tenant can have its OWN row for the same group/key (overriding the
     * global default), and that row is exactly as customer-facing as the
     * global one — matching on (group, key, exact value) alone catches both.
     * The exact-value guard is what makes this safe to run unscoped: only a
     * row whose value is byte-for-byte the seeded placeholder is touched, so
     * a tenant who deliberately typed something of their own — global or
     * tenant-scoped — is never at risk.
     */
    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'contact')
            ->where('key', 'logo_path')
            ->get(['id', 'value'])
            ->each(function ($row) {
                if (json_decode((string) $row->value, true) === ['/images/logo.svg']) {
                    DB::table('settings')->where('id', $row->id)->delete();
                }
            });
    }

    /**
     * Irreversible by design, not by omission: the only thing this migration
     * ever deletes is a pointer to public/images/logo.svg, and this same
     * branch deletes that file from the repo permanently. Recreating the
     * Setting row would restore a reference to an asset that no longer
     * exists — a broken image, not "the prior state" — so there is nothing
     * correct for down() to restore.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'Irreversible: this migration removes a pointer to public/images/logo.svg, '
            .'which no longer exists in the repository as of this same branch. Restoring '
            .'the Setting row would recreate a broken image reference, not the prior state.'
        );
    }
};
