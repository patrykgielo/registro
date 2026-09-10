<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_10_090003_backfill_pickup_location_for_open_orders.php — the
 * wycofywalność requirement in plan-wdrozenia.md, and the split the
 * migration's own docblock argues for: OPEN orders (still blocking
 * inventory) get the organization's primary Location backfilled; CLOSED/
 * terminal orders are historical records left untouched.
 *
 * Same rollbackAndRerun() harness as
 * BackfillLocationIdForOpenReservationsMigrationTest — by the time
 * RefreshDatabase's own initial `migrate` runs there is no data to backfill,
 * so every test rolls back first, creates its own fixtures, then re-runs
 * `migrate --path=...` to actually exercise up() against real data.
 */
class BackfillPickupLocationForOpenOrdersMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_10_090003_backfill_pickup_location_for_open_orders.php';

    private function rollbackAndRerun(callable $fixtures): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $fixtures();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    public function test_up_assigns_the_primary_location_and_snapshot_to_a_paid_order(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$order) {
            $primary = Location::factory()->for($org, 'organization')->create([
                'primary_slot' => 1,
                'name' => 'Siedziba główna',
            ]);
            $order = Order::factory()->paid()->create(['organization_id' => $org->id]);
        });

        $order->refresh();
        $this->assertSame($primary->id, $order->pickup_location_id);
        $this->assertSame('Siedziba główna', $order->pickup_location_name);
        $this->assertNotNull($order->pickup_location_address);
    }

    public function test_up_assigns_a_location_to_a_confirmed_order(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$order) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        });

        $this->assertSame($primary->id, $order->fresh()->pickup_location_id);
    }

    public function test_up_assigns_a_location_to_an_in_progress_order(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$order) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->inProgress()->create(['organization_id' => $org->id]);
        });

        $this->assertSame($primary->id, $order->fresh()->pickup_location_id);
    }

    public function test_up_assigns_a_location_to_a_pending_payment_order_still_within_p24_grace(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$order) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->pendingPayment()->create([
                'organization_id' => $org->id,
                'p24_token' => 'tok_123',
                'expires_at' => now()->subMinutes(10), // within the default 120-minute grace
            ]);
        });

        $this->assertSame($primary->id, $order->fresh()->pickup_location_id);
    }

    /**
     * Closed/terminal orders are historical records — team-lead's own
     * question, answered in the migration's docblock: NOT backfilled,
     * because nothing about the order's own data says where it was actually
     * collected, and retroactively assigning the CURRENT primary Location
     * would misrepresent that as fact.
     */
    public function test_up_does_not_assign_a_location_to_a_cancelled_order(): void
    {
        $org = Organization::factory()->create();
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$order) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->cancelled()->create(['organization_id' => $org->id]);
        });

        $order->refresh();
        $this->assertNull($order->pickup_location_id);
        $this->assertNull($order->pickup_location_name);
        $this->assertNull($order->pickup_location_address);
    }

    public function test_up_does_not_assign_a_location_to_a_completed_order(): void
    {
        $org = Organization::factory()->create();
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$order) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->completed()->create(['organization_id' => $org->id]);
        });

        $this->assertNull($order->fresh()->pickup_location_id);
    }

    public function test_up_does_not_assign_a_location_to_a_genuinely_expired_pending_order(): void
    {
        $org = Organization::factory()->create();
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$order) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->expired()->create(['organization_id' => $org->id]);
        });

        $this->assertNull($order->fresh()->pickup_location_id);
    }

    public function test_up_backfills_multiple_organizations_independently(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $primaryA = null;
        $primaryB = null;
        $orderA = null;
        $orderB = null;

        $this->rollbackAndRerun(function () use ($orgA, $orgB, &$primaryA, &$primaryB, &$orderA, &$orderB) {
            $primaryA = Location::factory()->for($orgA, 'organization')->create(['primary_slot' => 1]);
            $primaryB = Location::factory()->for($orgB, 'organization')->create(['primary_slot' => 1]);

            $orderA = Order::factory()->paid()->create(['organization_id' => $orgA->id]);
            $orderB = Order::factory()->paid()->create(['organization_id' => $orgB->id]);
        });

        $this->assertSame($primaryA->id, $orderA->fresh()->pickup_location_id);
        $this->assertSame($primaryB->id, $orderB->fresh()->pickup_location_id);
        $this->assertNotSame($orderA->fresh()->pickup_location_id, $orderB->fresh()->pickup_location_id);
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
        $orderWithoutPrimary = null;
        $orderWithPrimary = null;

        $this->rollbackAndRerun(function () use ($orgWithoutPrimary, $orgWithPrimary, &$primary, &$orderWithoutPrimary, &$orderWithPrimary) {
            $orderWithoutPrimary = Order::factory()->paid()->create(['organization_id' => $orgWithoutPrimary->id]);

            $primary = Location::factory()->for($orgWithPrimary, 'organization')->create(['primary_slot' => 1]);
            $orderWithPrimary = Order::factory()->paid()->create(['organization_id' => $orgWithPrimary->id]);
        });

        $this->assertNull($orderWithoutPrimary->fresh()->pickup_location_id);
        $this->assertSame($primary->id, $orderWithPrimary->fresh()->pickup_location_id);
    }

    /**
     * whereNull('pickup_location_id') guard in up() must not clobber an
     * order that already has one (idempotency of a second run).
     */
    public function test_up_does_not_overwrite_an_already_assigned_pickup_location(): void
    {
        $org = Organization::factory()->create();
        $other = null;
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$other, &$order) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $other = Location::factory()->for($org, 'organization')->create(['name' => 'Filia Wrocław']);
            $order = Order::factory()->paid()->create([
                'organization_id' => $org->id,
                'pickup_location_id' => $other->id,
                'pickup_location_name' => $other->name,
                'pickup_location_address' => $other->formattedAddress(),
            ]);
        });

        $order->refresh();
        $this->assertSame($other->id, $order->pickup_location_id, 'up() must not overwrite an already-assigned pickup location.');
        $this->assertSame('Filia Wrocław', $order->pickup_location_name);
    }

    /**
     * down() is a deliberate no-op — see the migration file's own docblock.
     */
    public function test_down_preserves_the_backfilled_pickup_location(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $order = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$order) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->paid()->create(['organization_id' => $org->id]);
        });

        $this->assertSame($primary->id, $order->fresh()->pickup_location_id);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame($primary->id, $order->fresh()->pickup_location_id, 'down() must preserve the backfilled value, not erase it');

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }
}
