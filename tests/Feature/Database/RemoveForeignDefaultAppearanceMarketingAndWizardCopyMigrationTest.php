<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins database/migrations/2026_08_13_150000_remove_foreign_default_appearance_marketing_and_wizard_copy.php
 * for real on SQLite. Same reasoning as the two prior migration tests in this
 * directory for why the migration object is invoked directly.
 */
class RemoveForeignDefaultAppearanceMarketingAndWizardCopyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = 'migrations/2026_08_13_150000_remove_foreign_default_appearance_marketing_and_wizard_copy.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION_FILE);
    }

    private function insertSetting(string $group, string $key, mixed $value, ?int $organizationId = null): void
    {
        DB::table('settings')->insert([
            'organization_id' => $organizationId,
            'group' => $group,
            'key' => $key,
            'value' => json_encode($value),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    public static function removedRowsProvider(): array
    {
        return [
            'appearance.logo_alt' => ['appearance', 'logo_alt', ['Registro - Mobilne Myjnie Parowe']],
            'marketing.hero_title' => ['marketing', 'hero_title', ['Profesjonalne Pranie Tapicerki Samochodowej']],
            'marketing.hero_subtitle' => ['marketing', 'hero_subtitle', ['Przywróć swojemu samochodowi pierwotny wygląd']],
            'marketing.services_heading' => ['marketing', 'services_heading', ['Nasze Usługi']],
            'marketing.services_subheading' => ['marketing', 'services_subheading', ['Kompleksowa oferta detailingu']],
            'marketing.features_heading' => ['marketing', 'features_heading', ['Dlaczego My?']],
            'marketing.features_subheading' => ['marketing', 'features_subheading', ['Gwarantujemy najwyższą jakość']],
            'marketing.features' => ['marketing', 'features', [[
                ['title' => 'Profesjonalny Sprzęt', 'description' => 'Używamy najnowocześniejszego sprzętu do prania tapicerki'],
                ['title' => 'Doświadczony Zespół', 'description' => 'Nasz zespół ma wieloletnie doświadczenie'],
                ['title' => 'Gwarancja Jakości', 'description' => 'Gwarantujemy 100% satysfakcji'],
            ]]],
            'marketing.cta_heading' => ['marketing', 'cta_heading', ['Umów się już dziś']],
            'marketing.cta_subheading' => ['marketing', 'cta_subheading', ['Skontaktuj się z nami i poznaj naszą ofertę']],
            'booking_wizard.before_visit_items' => ['booking_wizard', 'before_visit_items', [
                'Upewnij się, że samochód jest dostępny pod wskazanym adresem',
                'Usuń wartościowe przedmioty z wnętrza auta',
                'Dostęp do wody i prądu ułatwi pracę (jeśli to możliwe)',
                'Otrzymasz przypomnienie SMS 2h przed wizytą',
            ]],
            'booking_wizard.service_location_types' => ['booking_wizard', 'service_location_types', [
                ['icon' => 'sun', 'name' => 'Parking naziemny', 'description' => 'Parking na zewnątrz, bez zadaszenia'],
                ['icon' => 'building-office', 'name' => 'Parking podziemny', 'description' => 'Wymagany kod dostępu do garażu'],
                ['icon' => 'home', 'name' => 'Podwórko/Posesja', 'description' => 'Prywatna posesja z dostępem'],
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('removedRowsProvider')]
    public function test_up_deletes_each_exact_placeholder_row(string $group, string $key, mixed $value): void
    {
        $this->insertSetting($group, $key, $value);

        $this->migration()->up();

        $this->assertSame(
            0,
            DB::table('settings')->where('group', $group)->where('key', $key)->count(),
            "up() must delete the seeded placeholder row for {$group}.{$key}."
        );
    }

    public function test_up_never_touches_a_tenant_who_configured_their_own_real_value(): void
    {
        $this->insertSetting('appearance', 'logo_alt', ['Wypożyczalnia Sprzętu Budowlanego XYZ']);
        $this->insertSetting('marketing', 'hero_title', ['Wynajmij koparkę na weekend']);

        $this->migration()->up();

        $this->assertSame(1, DB::table('settings')->where('group', 'appearance')->where('key', 'logo_alt')->count());
        $this->assertSame(1, DB::table('settings')->where('group', 'marketing')->where('key', 'hero_title')->count());
    }

    /**
     * The real-world case this exact migration exists for: `grent` (the one
     * real tenant in dev) had its own `appearance.logo_alt` override
     * carrying the identical foreign text as the global default —
     * SettingsManager::logoAlt() reads whichever row applies to the current
     * tenant, so a tenant-scoped copy is exactly as customer-facing as the
     * global one. up() must not skip it just because organization_id isn't
     * NULL.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('removedRowsProvider')]
    public function test_up_deletes_a_tenant_scoped_row_with_the_exact_placeholder_value_too(string $group, string $key, mixed $value): void
    {
        $org = Organization::factory()->create();
        $this->insertSetting($group, $key, $value, $org->id);

        $this->migration()->up();

        $this->assertSame(
            0,
            DB::table('settings')->where('organization_id', $org->id)->where('group', $group)->where('key', $key)->count(),
            "up() must delete a tenant-scoped row too for {$group}.{$key}."
        );
    }

    public function test_up_never_touches_a_tenant_scoped_row_with_a_different_value(): void
    {
        $org = Organization::factory()->create();
        $this->insertSetting('appearance', 'logo_alt', ['Wypożyczalnia Sprzętu Budowlanego XYZ'], $org->id);

        $this->migration()->up();

        $this->assertSame(
            1,
            DB::table('settings')->where('organization_id', $org->id)->where('group', 'appearance')->where('key', 'logo_alt')->count()
        );
    }

    /**
     * Also pins the nesting fix: SettingSeeder.php always wrote this key
     * double-nested (`[[a, b, c]]`) — a Simple Repeater format violation
     * per .claude/rules/filament-settings-pages.md, predating this branch.
     * up() both trims the content AND corrects the shape to the flat array
     * a Simple Repeater actually requires.
     */
    public function test_up_trims_a_tenant_scoped_important_info_points_to_two_generic_items_too(): void
    {
        $org = Organization::factory()->create();
        $this->insertSetting('marketing', 'important_info_points', [[
            'Rezerwacja wymaga wpłaty zaliczki',
            'Możliwość anulacji do 24h przed wizytą',
            'Usługi realizowane na terenie klienta',
        ]], $org->id);

        $this->migration()->up();

        $row = DB::table('settings')
            ->where('organization_id', $org->id)
            ->where('group', 'marketing')
            ->where('key', 'important_info_points')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(
            ['Rezerwacja wymaga wpłaty zaliczki', 'Możliwość anulacji do 24h przed wizytą'],
            json_decode((string) $row->value, true)
        );
    }

    public function test_up_trims_important_info_points_to_two_generic_items_not_deletes_the_row(): void
    {
        $this->insertSetting('marketing', 'important_info_points', [[
            'Rezerwacja wymaga wpłaty zaliczki',
            'Możliwość anulacji do 24h przed wizytą',
            'Usługi realizowane na terenie klienta',
        ]]);

        $this->migration()->up();

        $row = DB::table('settings')
            ->whereNull('organization_id')
            ->where('group', 'marketing')
            ->where('key', 'important_info_points')
            ->first();

        $this->assertNotNull($row, 'important_info_points must survive as a row, not be deleted.');
        $this->assertSame(
            ['Rezerwacja wymaga wpłaty zaliczki', 'Możliwość anulacji do 24h przed wizytą'],
            json_decode((string) $row->value, true)
        );
    }

    /**
     * A tenant's own real value survives untouched — using the flat shape a
     * genuine Filament save would actually produce (HasGroupedSettings
     * normalizes Simple Repeater state to flat before it ever reaches the
     * database), not the legacy double-nested shape only the original
     * seeder bug ever wrote.
     */
    public function test_up_leaves_a_tenant_customized_important_info_points_alone(): void
    {
        $this->insertSetting('marketing', 'important_info_points', ['Coś zupełnie innego']);

        $this->migration()->up();

        $row = DB::table('settings')->where('group', 'marketing')->where('key', 'important_info_points')->first();

        $this->assertSame(['Coś zupełnie innego'], json_decode((string) $row->value, true));
    }

    public function test_down_restores_every_removed_row_and_the_original_important_info_points(): void
    {
        foreach (self::removedRowsProvider() as [$group, $key, $value]) {
            $this->insertSetting($group, $key, $value);
        }
        $this->insertSetting('marketing', 'important_info_points', [[
            'Rezerwacja wymaga wpłaty zaliczki',
            'Możliwość anulacji do 24h przed wizytą',
            'Usługi realizowane na terenie klienta',
        ]]);

        $this->migration()->up();
        $this->migration()->down();

        foreach (self::removedRowsProvider() as [$group, $key, $value]) {
            $row = DB::table('settings')
                ->whereNull('organization_id')
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            $this->assertNotNull($row, "down() must restore {$group}.{$key}.");
            $this->assertSame($value, json_decode((string) $row->value, true));
        }

        $pointsRow = DB::table('settings')
            ->whereNull('organization_id')
            ->where('group', 'marketing')
            ->where('key', 'important_info_points')
            ->first();

        // Restored flat, not the legacy double-nested shape the row started
        // in — down() undoes the trim (2 items -> 3), it does not
        // reintroduce the separate, already-fixed nesting bug on top.
        $this->assertSame(
            ['Rezerwacja wymaga wpłaty zaliczki', 'Możliwość anulacji do 24h przed wizytą', 'Usługi realizowane na terenie klienta'],
            json_decode((string) $pointsRow->value, true)
        );
    }

    /**
     * up() trims important_info_points across every row, any organization_id
     * (see test_up_trims_a_tenant_scoped_important_info_points_to_two_generic_items_too
     * above) — down() must be the exact mirror, or a tenant-scoped row that
     * was trimmed going in is left trimmed coming back out. Real-world case:
     * `grent` (dev) held its own byte-identical copy of a seeded default for
     * a DIFFERENT key (appearance.logo_alt) — the same thing happening here
     * is not hypothetical, just not yet observed for this specific key.
     */
    public function test_down_restores_a_tenant_scoped_important_info_points_row_too(): void
    {
        $org = Organization::factory()->create();
        $this->insertSetting('marketing', 'important_info_points', [[
            'Rezerwacja wymaga wpłaty zaliczki',
            'Możliwość anulacji do 24h przed wizytą',
            'Usługi realizowane na terenie klienta',
        ]], $org->id);

        $this->migration()->up();
        $this->migration()->down();

        $row = DB::table('settings')
            ->where('organization_id', $org->id)
            ->where('group', 'marketing')
            ->where('key', 'important_info_points')
            ->first();

        $this->assertNotNull($row, 'down() must restore the tenant-scoped row, not just the global one.');
        $this->assertSame(
            ['Rezerwacja wymaga wpłaty zaliczki', 'Możliwość anulacji do 24h przed wizytą', 'Usługi realizowane na terenie klienta'],
            json_decode((string) $row->value, true)
        );
    }

    /**
     * Documents a known, intentional limit of the self::REMOVED (DELETE-based)
     * keys in this migration — not a bug, and not the same as the
     * important_info_points case just above, which IS fully symmetric
     * because it UPDATEs rather than deletes. `Setting` has no SoftDeletes
     * and no audit trail, so once up() deletes a tenant-scoped row, nothing
     * records it ever existed or which organization_id it had — down()
     * cannot restore what it has no way to know about. See this migration's
     * down() docblock for the full argument.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('removedRowsProvider')]
    public function test_down_cannot_restore_a_tenant_scoped_removed_row_that_up_deleted(string $group, string $key, mixed $value): void
    {
        $org = Organization::factory()->create();
        $this->insertSetting($group, $key, $value, $org->id);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(
            0,
            DB::table('settings')->where('organization_id', $org->id)->where('group', $group)->where('key', $key)->count(),
            "Documented limit: down() cannot know a tenant-scoped {$group}.{$key} row ever existed once up() deleted it."
        );
    }
}
