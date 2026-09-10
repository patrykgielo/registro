<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_10_090001_backfill_location_id_for_active_carts.php — the
 * wycofywalność requirement in plan-wdrozenia.md, and the regression this
 * migration exists to prevent: without it, Faza 6 krok 6.4's fail-closed
 * checkout validation would lock out every customer with an
 * already-existing active cart the moment this feature deploys.
 *
 * Same rollbackAndRerun() harness as
 * BackfillLocationIdForOpenReservationsMigrationTest — by the time
 * RefreshDatabase's own initial `migrate` runs there is no data to backfill,
 * so every test rolls back first, creates its own fixtures, then re-runs
 * `migrate --path=...` to actually exercise up() against real data.
 */
class BackfillLocationIdForActiveCartsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_10_090001_backfill_location_id_for_active_carts.php';

    private function rollbackAndRerun(callable $fixtures): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $fixtures();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    public function test_up_assigns_the_primary_location_to_an_active_cart(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $cart = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$cart) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $cart = Cart::factory()->active()->create([
                'organization_id' => $org->id,
                'user_id' => User::factory()->create()->id,
            ]);
        });

        $this->assertSame($primary->id, $cart->fresh()->location_id);
    }

    public function test_up_does_not_assign_a_location_to_a_converted_cart(): void
    {
        $org = Organization::factory()->create();
        $cart = null;

        $this->rollbackAndRerun(function () use ($org, &$cart) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $cart = Cart::factory()->converted()->create([
                'organization_id' => $org->id,
                'user_id' => User::factory()->create()->id,
            ]);
        });

        $this->assertNull($cart->fresh()->location_id);
    }

    public function test_up_does_not_assign_a_location_to_an_abandoned_cart(): void
    {
        $org = Organization::factory()->create();
        $cart = null;

        $this->rollbackAndRerun(function () use ($org, &$cart) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $cart = Cart::factory()->abandoned()->create([
                'organization_id' => $org->id,
                'user_id' => User::factory()->create()->id,
            ]);
        });

        $this->assertNull($cart->fresh()->location_id);
    }

    public function test_up_backfills_multiple_organizations_independently(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $primaryA = null;
        $primaryB = null;
        $cartA = null;
        $cartB = null;

        $this->rollbackAndRerun(function () use ($orgA, $orgB, &$primaryA, &$primaryB, &$cartA, &$cartB) {
            $primaryA = Location::factory()->for($orgA, 'organization')->create(['primary_slot' => 1]);
            $primaryB = Location::factory()->for($orgB, 'organization')->create(['primary_slot' => 1]);

            $cartA = Cart::factory()->active()->create([
                'organization_id' => $orgA->id,
                'user_id' => User::factory()->create()->id,
            ]);
            $cartB = Cart::factory()->active()->create([
                'organization_id' => $orgB->id,
                'user_id' => User::factory()->create()->id,
            ]);
        });

        $this->assertSame($primaryA->id, $cartA->fresh()->location_id);
        $this->assertSame($primaryB->id, $cartB->fresh()->location_id);
        $this->assertNotSame($cartA->fresh()->location_id, $cartB->fresh()->location_id);
    }

    /**
     * Defensive posture, same precedent as the sibling backfill migrations:
     * an organization with no primary location at migration time is skipped
     * rather than crashing the whole batch.
     */
    public function test_up_skips_an_organization_with_no_primary_location_without_failing_the_batch(): void
    {
        $orgWithoutPrimary = Organization::factory()->create();
        $orgWithPrimary = Organization::factory()->create();
        $primary = null;
        $cartWithoutPrimary = null;
        $cartWithPrimary = null;

        $this->rollbackAndRerun(function () use ($orgWithoutPrimary, $orgWithPrimary, &$primary, &$cartWithoutPrimary, &$cartWithPrimary) {
            $cartWithoutPrimary = Cart::factory()->active()->create([
                'organization_id' => $orgWithoutPrimary->id,
                'user_id' => User::factory()->create()->id,
            ]);

            $primary = Location::factory()->for($orgWithPrimary, 'organization')->create(['primary_slot' => 1]);
            $cartWithPrimary = Cart::factory()->active()->create([
                'organization_id' => $orgWithPrimary->id,
                'user_id' => User::factory()->create()->id,
            ]);
        });

        $this->assertNull($cartWithoutPrimary->fresh()->location_id);
        $this->assertSame($primary->id, $cartWithPrimary->fresh()->location_id);
    }

    /**
     * whereNull('location_id') guard in up() must not clobber a cart that
     * already has a location.
     */
    public function test_up_does_not_overwrite_an_already_assigned_location(): void
    {
        $org = Organization::factory()->create();
        $other = null;
        $cart = null;

        $this->rollbackAndRerun(function () use ($org, &$other, &$cart) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $other = Location::factory()->for($org, 'organization')->create();
            $cart = Cart::factory()->active()->create([
                'organization_id' => $org->id,
                'user_id' => User::factory()->create()->id,
                'location_id' => $other->id,
            ]);
        });

        $this->assertSame($other->id, $cart->fresh()->location_id, 'up() must not overwrite an already-assigned location.');
    }

    /**
     * down() is a deliberate no-op — see the migration file's own docblock.
     */
    public function test_down_preserves_the_backfilled_location_id(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $cart = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$cart) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $cart = Cart::factory()->active()->create([
                'organization_id' => $org->id,
                'user_id' => User::factory()->create()->id,
            ]);
        });

        $this->assertSame($primary->id, $cart->fresh()->location_id);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame($primary->id, $cart->fresh()->location_id, 'down() must preserve the backfilled value, not erase it');

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }
}
