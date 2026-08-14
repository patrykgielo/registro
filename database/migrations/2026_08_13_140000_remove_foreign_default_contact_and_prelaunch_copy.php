<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Group/key/exact-value triples this migration removes. Every value here
     * came from database/seeders/SettingSeeder.php and/or
     * 2025_12_06_142446_add_prelaunch_settings.php, seeded GLOBAL
     * (organization_id IS NULL) — used by every tenant that has not
     * configured its own override. Two distinct problems, same fix shape:
     *
     * - contact.email/phone/address_line/city/postal_code: a fabricated
     *   identity ("contact@example.com", "ul. Marszałkowska 1") shown to a
     *   tenant's customers as if it were real.
     * - contact.logo_alt: NOT the same as appearance.logo_alt (removed
     *   separately by 2026_08_13_150000, which SettingsManager::logoAlt()
     *   actually reads) — this one is a dead key, seeded here but never
     *   consumed anywhere, removed for the same reason as the equally dead
     *   contact.logo_path above it, not because it was customer-facing.
     * - prelaunch.tagline/description_1/description_2: copy describing a
     *   mobile car-wash/detailing business — this project sells equipment
     *   rental only (see CLAUDE.md). False about every tenant's trade, not
     *   just unbranded.
     * - prelaunch.launch_date: a fixed calendar date ('2026-01-25') that is
     *   already in the past by the time any tenant using the default would
     *   show it — not an identity problem, but the same "silently wrong"
     *   shape, in the same file, decided in the same pass.
     *
     * Every consumer of these keys already renders "nothing" gracefully when
     * the key is absent (SystemSettings Contact tab shows blank inputs;
     * storefront header/footer/maintenance pages already `@if`/`!empty()`
     * guard on them) — this migration only removes the row asserting
     * something false, it does not add a new blade branch. See
     * app/docs/features/tenant-branding.md.
     *
     * Exact-value match only, like 2026_08_13_130000: a tenant whose
     * settings happen to differ (impossible today via any Filament field,
     * but the migration must stay safe if that ever changes) is untouched.
     *
     * No `organization_id` filter in the query below, deliberately: a
     * tenant-scoped row with the identical placeholder value is exactly as
     * customer-facing as the global one (verified directly — `grent`, the
     * one real tenant in dev, had its own `appearance.logo_alt` override
     * carrying the same foreign text; see 2026_08_13_150000's docblock).
     * Matching on (group, key, exact value) alone, with no org scoping,
     * catches both without risking a tenant's real, different value.
     */
    private const REMOVED = [
        ['group' => 'contact', 'key' => 'email', 'value' => ['contact@example.com']],
        ['group' => 'contact', 'key' => 'phone', 'value' => ['+48123456789']],
        ['group' => 'contact', 'key' => 'address_line', 'value' => ['ul. Marszałkowska 1']],
        ['group' => 'contact', 'key' => 'city', 'value' => ['Warszawa']],
        ['group' => 'contact', 'key' => 'postal_code', 'value' => ['00-001']],
        ['group' => 'contact', 'key' => 'logo_alt', 'value' => ['Registro - Mobilne Myjnie Parowe']],
        ['group' => 'prelaunch', 'key' => 'tagline', 'value' => ['Registro polega na tym, że to my przyjeżdżamy do Ciebie, a nie Ty do Nas!']],
        ['group' => 'prelaunch', 'key' => 'description_1', 'value' => ['Wprowadzamy autorski system rezerwacji mobilnych usług mycia pojazdów oraz detailingu.']],
        ['group' => 'prelaunch', 'key' => 'description_2', 'value' => ['Świadczymy usługi we wskazanej przez Ciebie lokalizacji.']],
        ['group' => 'prelaunch', 'key' => 'launch_date', 'value' => ['2026-01-25']],
    ];

    /**
     * Uses DB::table(), not the Setting Eloquent model — see
     * 2026_08_13_130000_remove_foreign_default_logo_path.php's docblock for
     * why (BelongsToOrganization's global scope is not safe to route
     * through in a migration).
     */
    public function up(): void
    {
        foreach (self::REMOVED as $row) {
            DB::table('settings')
                ->where('group', $row['group'])
                ->where('key', $row['key'])
                ->get(['id', 'value'])
                ->each(function ($existing) use ($row) {
                    if (json_decode((string) $existing->value, true) === $row['value']) {
                        DB::table('settings')->where('id', $existing->id)->delete();
                    }
                });
        }
    }

    /**
     * Unlike 2026_08_13_130000's down() (irreversible: it points at a file
     * this branch deletes), these are plain text values, which is why this
     * restores anything at all — but only the GLOBAL default, not
     * necessarily the full prior state.
     *
     * Honest limit, not an oversight: up() DELETEs matching rows, any
     * organization_id. `Setting` has neither SoftDeletes nor an audit trail
     * (checked: `app/Models/Setting.php` uses only `BelongsToOrganization`;
     * no `spatie/laravel-activitylog` or equivalent in this project) — once
     * a tenant-scoped row is deleted, there is no remaining record of which
     * organization_id it belonged to, or that it existed at all. down()
     * cannot restore what it has no way to know about. This is different in
     * kind from 2026_08_13_150000's important_info_points fix, where the
     * row is UPDATEd, not deleted — the row (and its organization_id)
     * survives up(), so down() can find and restore it later by its current
     * value. A DELETE destroys the very information a later UPDATE-style
     * restore would need.
     *
     * updateOrInsert() so a rollback is safe even if the global row was
     * somehow already recreated (e.g. by a rerun of the original seeder).
     */
    public function down(): void
    {
        foreach (self::REMOVED as $row) {
            DB::table('settings')->updateOrInsert(
                ['organization_id' => null, 'group' => $row['group'], 'key' => $row['key']],
                ['value' => json_encode($row['value']), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
};
