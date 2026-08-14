<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Third pass over the same class of bug — see
     * app/docs/features/tenant-branding.md for the full audit. This one
     * corrects a real miss in 2026_08_13_140000: that migration deleted
     * `contact.logo_alt` (dead, never read), but SettingsManager::logoAlt()
     * actually reads `appearance.logo_alt` — a live key rendered as `alt=`
     * on every logo `<img>` and as visible fallback text wherever no logo
     * image is configured. It was still sitting in the settings table with
     * the same "Registro - Mobilne Myjnie Parowe" value — in TWO rows, not
     * one: the global (organization_id IS NULL) default, and a tenant-scoped
     * override on `grent` (the one real tenant in dev) carrying the
     * identical foreign text. A tenant's own row is exactly as
     * customer-facing as the global default, so this migration does not
     * filter by `organization_id` at all in the query below — it matches
     * every row for (group, key) regardless of owner, then only deletes/
     * updates the ones whose value is byte-for-byte the placeholder being
     * removed. That exact-value guard is what makes running unscoped safe:
     * a tenant who typed something of their own, global or tenant-scoped,
     * is never touched.
     *
     * Also removes/trims the two other groups found by sweeping every
     * remaining settings row, any organization_id, not just the global ones:
     * - marketing.* (all eleven keys, including important_info_heading/
     *   important_info_points): confirmed dead — its only would-be
     *   consumer, resources/views/booking/create.blade.php, is itself dead
     *   code (BookingController::create() only redirects, never renders a
     *   view), so nothing reads any marketing.* key anywhere. Still stale
     *   data in a live admin form, so removed regardless. An earlier pass
     *   over this migration claimed important_info_heading/
     *   important_info_points WERE live via that file — that was wrong,
     *   corrected by code review; see app/docs/features/tenant-branding.md.
     *   important_info_points is trimmed (3 items → 2), not deleted like
     *   the rest of the group, on its own merits regardless of live/dead
     *   status: two of the three seeded points were generic booking-policy
     *   statements worth keeping either way, the third was the same
     *   mobile-at-customer-location claim as the removed prelaunch tagline.
     *   An UPDATE, not a DELETE.
     * - booking_wizard.before_visit_items/service_location_types: live
     *   render paths (booking-wizard/confirmation.blade.php,
     *   booking-wizard/steps/vehicle-location.blade.php) that no current
     *   tenant reaches (equipment rental only uses Cart/Checkout, not the
     *   appointment booking wizard) but would show car-wash-specific
     *   content to any tenant that ever enables time_slot bookings on
     *   defaults.
     *
     * Uses DB::table(), not the Setting Eloquent model — see
     * 2026_08_13_130000's docblock for why.
     */
    private const REMOVED = [
        ['group' => 'appearance', 'key' => 'logo_alt', 'value' => ['Registro - Mobilne Myjnie Parowe']],
        ['group' => 'marketing', 'key' => 'hero_title', 'value' => ['Profesjonalne Pranie Tapicerki Samochodowej']],
        ['group' => 'marketing', 'key' => 'hero_subtitle', 'value' => ['Przywróć swojemu samochodowi pierwotny wygląd']],
        ['group' => 'marketing', 'key' => 'services_heading', 'value' => ['Nasze Usługi']],
        ['group' => 'marketing', 'key' => 'services_subheading', 'value' => ['Kompleksowa oferta detailingu']],
        ['group' => 'marketing', 'key' => 'features_heading', 'value' => ['Dlaczego My?']],
        ['group' => 'marketing', 'key' => 'features_subheading', 'value' => ['Gwarantujemy najwyższą jakość']],
        ['group' => 'marketing', 'key' => 'features', 'value' => [[
            ['title' => 'Profesjonalny Sprzęt', 'description' => 'Używamy najnowocześniejszego sprzętu do prania tapicerki'],
            ['title' => 'Doświadczony Zespół', 'description' => 'Nasz zespół ma wieloletnie doświadczenie'],
            ['title' => 'Gwarancja Jakości', 'description' => 'Gwarantujemy 100% satysfakcji'],
        ]]],
        ['group' => 'marketing', 'key' => 'cta_heading', 'value' => ['Umów się już dziś']],
        ['group' => 'marketing', 'key' => 'cta_subheading', 'value' => ['Skontaktuj się z nami i poznaj naszą ofertę']],
        ['group' => 'booking_wizard', 'key' => 'before_visit_items', 'value' => [
            'Upewnij się, że samochód jest dostępny pod wskazanym adresem',
            'Usuń wartościowe przedmioty z wnętrza auta',
            'Dostęp do wody i prądu ułatwi pracę (jeśli to możliwe)',
            'Otrzymasz przypomnienie SMS 2h przed wizytą',
        ]],
        ['group' => 'booking_wizard', 'key' => 'service_location_types', 'value' => [
            ['icon' => 'sun', 'name' => 'Parking naziemny', 'description' => 'Parking na zewnątrz, bez zadaszenia'],
            ['icon' => 'building-office', 'name' => 'Parking podziemny', 'description' => 'Wymagany kod dostępu do garażu'],
            ['icon' => 'home', 'name' => 'Podwórko/Posesja', 'description' => 'Prywatna posesja z dostępem'],
        ]],
    ];

    /**
     * The only way this row was ever seeded is database/seeders/SettingSeeder.php,
     * which — like the sibling incident documented in
     * .claude/rules/filament-settings-pages.md ("Repeater Data Format",
     * 2026-02-05) — wrote it double-nested: `[[item1, item2, item3]]`
     * instead of the flat `[item1, item2, item3]` a Filament Simple Repeater
     * (`Repeater::make(...)->simple(...)`, SystemSettings.php:911) requires.
     * That format violation predates this branch and is orthogonal to the
     * car-wash-copy removal, but this migration already touches this exact
     * key/row, and a caller (SettingsManager::unwrapValue()) would never
     * unwrap it back to a plain array either — so it's fixed here rather
     * than left for someone to rediscover via the [object Object] symptom.
     */
    private const IMPORTANT_INFO_POINTS_LEGACY_NESTED_BEFORE = [[
        'Rezerwacja wymaga wpłaty zaliczki',
        'Możliwość anulacji do 24h przed wizytą',
        'Usługi realizowane na terenie klienta',
    ]];

    private const IMPORTANT_INFO_POINTS_BEFORE = [
        'Rezerwacja wymaga wpłaty zaliczki',
        'Możliwość anulacji do 24h przed wizytą',
        'Usługi realizowane na terenie klienta',
    ];

    private const IMPORTANT_INFO_POINTS_AFTER = [
        'Rezerwacja wymaga wpłaty zaliczki',
        'Możliwość anulacji do 24h przed wizytą',
    ];

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

        // Matches only the legacy double-nested shape SettingSeeder.php ever
        // actually wrote — there is no other origin for this row, so no
        // flat-shaped variant can exist yet to also guard against.
        DB::table('settings')
            ->where('group', 'marketing')
            ->where('key', 'important_info_points')
            ->get(['id', 'value'])
            ->each(function ($existing) {
                if (json_decode((string) $existing->value, true) === self::IMPORTANT_INFO_POINTS_LEGACY_NESTED_BEFORE) {
                    DB::table('settings')
                        ->where('id', $existing->id)
                        ->update(['value' => json_encode(self::IMPORTANT_INFO_POINTS_AFTER), 'updated_at' => now()]);
                }
            });
    }

    /**
     * Two different kinds of restore in this one method, and only one of
     * them is fully symmetric with up() — see each block below.
     *
     * self::REMOVED rows: up() DELETEs matching rows, any organization_id.
     * down() here restores only the GLOBAL default, not necessarily the
     * full prior state. Honest limit, not an oversight: `Setting` has
     * neither SoftDeletes nor an audit trail (checked: `app/Models/Setting.php`
     * uses only `BelongsToOrganization`; no `spatie/laravel-activitylog` or
     * equivalent in this project) — once a tenant-scoped row is deleted,
     * nothing records which organization_id it had, or that it existed at
     * all. down() cannot restore what it has no way to know about. Same
     * limitation, same reasoning, as 2026_08_13_140000's down().
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

        // important_info_points is different: up() UPDATEs this row, it
        // never deletes it — the row (and its organization_id) survives,
        // so down() CAN find and restore it later by its current value.
        // Fully symmetric with up(), unlike the DELETE-based block above.
        // Mirror of up()'s trim: matches every row, any organization_id, whose
        // CURRENT value is the trimmed result — restoring the global row only
        // would leave a tenant-scoped row (that up() also trimmed, using the
        // exact same org-agnostic guard) permanently stuck trimmed.
        //
        // Restores IMPORTANT_INFO_POINTS_BEFORE — the 3 original items,
        // correctly flat — not IMPORTANT_INFO_POINTS_LEGACY_NESTED_BEFORE.
        // The double-nested encoding was a pre-existing format bug (see
        // up()'s docblock), not an intentional prior state; down() undoes
        // the content change (2 items → 3), it does not reintroduce a
        // separate, already-fixed formatting bug on top of that.
        DB::table('settings')
            ->where('group', 'marketing')
            ->where('key', 'important_info_points')
            ->get(['id', 'value'])
            ->each(function ($existing) {
                if (json_decode((string) $existing->value, true) === self::IMPORTANT_INFO_POINTS_AFTER) {
                    DB::table('settings')
                        ->where('id', $existing->id)
                        ->update(['value' => json_encode(self::IMPORTANT_INFO_POINTS_BEFORE), 'updated_at' => now()]);
                }
            });
    }
};
