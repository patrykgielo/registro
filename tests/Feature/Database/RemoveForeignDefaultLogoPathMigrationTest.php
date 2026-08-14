<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins database/migrations/2026_08_13_130000_remove_foreign_default_logo_path.php
 * for real on SQLite — MigrationRollbackTest only checks that down() has a
 * non-empty method body (a static regex), which proves nothing about what the
 * migration actually does when run. This exercises up() and down() directly.
 *
 * Uses DB::table() throughout, matching the migration itself, deliberately
 * NOT the Setting Eloquent model — see the migration's own docblock for why
 * (BelongsToOrganization's global scope is not safe to route through here).
 */
class RemoveForeignDefaultLogoPathMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = 'migrations/2026_08_13_130000_remove_foreign_default_logo_path.php';

    /**
     * Invokes the migration object directly rather than `artisan migrate --path=`:
     * Laravel's migrator skips a migration already recorded in the `migrations`
     * table (which it is, as part of RefreshDatabase's own full migrate), and
     * `migrate:rollback` to reset that bookkeeping is not available here — down()
     * throws by design (see that method's docblock). Same technique as the last
     * test in OrderHandoverReturnEmailTemplateMigrationTest.
     */
    private function migration(): object
    {
        return require database_path(self::MIGRATION_FILE);
    }

    public function test_up_deletes_the_exact_placeholder_row(): void
    {
        // Simulates the pre-fix state (2025_12_06_142446 seeded this row,
        // and this same migration already removed it once during setUp's
        // full migrate) by reinserting the exact placeholder.
        DB::table('settings')->insert([
            'organization_id' => null,
            'group' => 'contact',
            'key' => 'logo_path',
            'value' => json_encode(['/images/logo.svg']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame(
            0,
            DB::table('settings')->where('group', 'contact')->where('key', 'logo_path')->count(),
            'up() must delete the seeded placeholder row.'
        );
    }

    public function test_up_never_touches_a_row_with_a_different_value(): void
    {
        // A tenant who somehow set a real, different logo_path (not possible
        // via any current Filament field, but the migration must still be
        // safe if it ever happens) must survive.
        DB::table('settings')->insert([
            'organization_id' => null,
            'group' => 'contact',
            'key' => 'logo_path',
            'value' => json_encode(['/storage/settings/logos/real-tenant-logo.svg']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame(
            1,
            DB::table('settings')->where('group', 'contact')->where('key', 'logo_path')->count(),
            'up() must never delete a row whose value is not the exact bundled-asset placeholder.'
        );
    }

    /**
     * A tenant-scoped row is exactly as customer-facing as the global
     * default — up() must not skip it just because organization_id isn't
     * NULL. Found for real: `grent` (dev) had its own appearance.logo_alt
     * override carrying the identical placeholder (see 2026_08_13_150000).
     */
    public function test_up_deletes_a_tenant_scoped_row_with_the_exact_placeholder_value_too(): void
    {
        $org = Organization::factory()->create();

        DB::table('settings')->insert([
            'organization_id' => $org->id,
            'group' => 'contact',
            'key' => 'logo_path',
            'value' => json_encode(['/images/logo.svg']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame(
            0,
            DB::table('settings')->where('organization_id', $org->id)->where('group', 'contact')->where('key', 'logo_path')->count(),
            'up() must delete a tenant-scoped row too when its value is the exact placeholder.'
        );
    }

    /**
     * The other half of the same property: a tenant-scoped row with its OWN
     * real value must survive, same as the global-row case above.
     */
    public function test_up_never_touches_a_tenant_scoped_row_with_a_different_value(): void
    {
        $org = Organization::factory()->create();

        DB::table('settings')->insert([
            'organization_id' => $org->id,
            'group' => 'contact',
            'key' => 'logo_path',
            'value' => json_encode(['/storage/settings/logos/this-tenants-own-logo.svg']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame(
            1,
            DB::table('settings')->where('organization_id', $org->id)->where('group', 'contact')->where('key', 'logo_path')->count(),
            'up() must never delete a tenant-scoped row whose value is not the exact placeholder.'
        );
    }

    public function test_down_throws_instead_of_silently_recreating_a_pointer_to_a_deleted_file(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->migration()->down();
    }
}
