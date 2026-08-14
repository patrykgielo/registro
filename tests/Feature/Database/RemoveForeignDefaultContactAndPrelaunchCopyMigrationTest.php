<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins database/migrations/2026_08_13_140000_remove_foreign_default_contact_and_prelaunch_copy.php
 * for real on SQLite. Same reasoning as RemoveForeignDefaultLogoPathMigrationTest for why the
 * migration object is invoked directly rather than through `artisan migrate --path=`.
 */
class RemoveForeignDefaultContactAndPrelaunchCopyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = 'migrations/2026_08_13_140000_remove_foreign_default_contact_and_prelaunch_copy.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION_FILE);
    }

    private function insertSetting(string $group, string $key, array $value, ?int $organizationId = null): void
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
     * @return list<array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function placeholderRowsProvider(): array
    {
        return [
            'contact.email' => ['contact', 'email', ['contact@example.com']],
            'contact.phone' => ['contact', 'phone', ['+48123456789']],
            'contact.address_line' => ['contact', 'address_line', ['ul. Marszałkowska 1']],
            'contact.city' => ['contact', 'city', ['Warszawa']],
            'contact.postal_code' => ['contact', 'postal_code', ['00-001']],
            'contact.logo_alt' => ['contact', 'logo_alt', ['Registro - Mobilne Myjnie Parowe']],
            'prelaunch.tagline' => ['prelaunch', 'tagline', ['Registro polega na tym, że to my przyjeżdżamy do Ciebie, a nie Ty do Nas!']],
            'prelaunch.description_1' => ['prelaunch', 'description_1', ['Wprowadzamy autorski system rezerwacji mobilnych usług mycia pojazdów oraz detailingu.']],
            'prelaunch.description_2' => ['prelaunch', 'description_2', ['Świadczymy usługi we wskazanej przez Ciebie lokalizacji.']],
            'prelaunch.launch_date' => ['prelaunch', 'launch_date', ['2026-01-25']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('placeholderRowsProvider')]
    public function test_up_deletes_each_exact_placeholder_row(string $group, string $key, array $value): void
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
        $this->insertSetting('contact', 'email', ['biuro@wynajemsprzetu.pl']);
        $this->insertSetting('prelaunch', 'tagline', ['Wypożycz sprzęt budowlany bez wychodzenia z domu.']);

        $this->migration()->up();

        $this->assertSame(1, DB::table('settings')->where('group', 'contact')->where('key', 'email')->count());
        $this->assertSame(1, DB::table('settings')->where('group', 'prelaunch')->where('key', 'tagline')->count());
    }

    /**
     * A tenant-scoped row with the exact placeholder is exactly as
     * customer-facing as the global default — found for real: `grent` had
     * its own appearance.logo_alt override carrying the identical text (see
     * 2026_08_13_150000's docblock). up() must not skip a row just because
     * organization_id isn't NULL.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('placeholderRowsProvider')]
    public function test_up_deletes_a_tenant_scoped_row_with_the_exact_placeholder_value_too(string $group, string $key, array $value): void
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
        $this->insertSetting('contact', 'email', ['biuro@wynajemsprzetu.pl'], $org->id);

        $this->migration()->up();

        $this->assertSame(
            1,
            DB::table('settings')->where('organization_id', $org->id)->where('group', 'contact')->where('key', 'email')->count()
        );
    }

    public function test_down_restores_every_removed_row_with_its_original_value(): void
    {
        foreach (self::placeholderRowsProvider() as [$group, $key, $value]) {
            $this->insertSetting($group, $key, $value);
        }

        $this->migration()->up();
        $this->migration()->down();

        foreach (self::placeholderRowsProvider() as [$group, $key, $value]) {
            $row = DB::table('settings')
                ->whereNull('organization_id')
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            $this->assertNotNull($row, "down() must restore {$group}.{$key}.");
            $this->assertSame($value, json_decode((string) $row->value, true));
        }
    }

    /**
     * Documents a known, intentional limit — not a bug to fix. up() DELETEs
     * a tenant-scoped row (proven above); down() cannot bring it back,
     * because `Setting` has no SoftDeletes and no audit trail (see this
     * migration's down() docblock for the full argument) — once deleted,
     * nothing records the row ever existed or which organization_id it had.
     * down() restores only the global default. This is different in kind
     * from 2026_08_13_150000's important_info_points fix, which UPDATEs
     * rather than deletes, so the row (and its organization_id) survives
     * and CAN be found again — see that migration's own down() test for the
     * contrasting, fully-symmetric case.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('placeholderRowsProvider')]
    public function test_down_cannot_restore_a_tenant_scoped_row_that_up_deleted(string $group, string $key, array $value): void
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
