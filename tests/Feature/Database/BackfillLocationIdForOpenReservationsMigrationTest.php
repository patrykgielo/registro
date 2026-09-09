<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\RentalStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_09_090003_backfill_location_id_for_open_reservations.php — the
 * wycofywalność requirement in plan-wdrozenia.md, and the acceptance
 * criterion the team lead asked for: "żadna otwarta rezerwacja nie zostaje
 * bez oddziału".
 *
 * By the time RefreshDatabase's own initial `migrate` runs there is no data
 * to backfill, so that first run is a genuine no-op — same situation
 * BackfillPrimaryLocationForOrganizationsMigrationTest documents for its own
 * migration. Every test below rolls back first, creates its own fixtures,
 * then re-runs `migrate --path=...` to actually exercise up() against real
 * data.
 */
class BackfillLocationIdForOpenReservationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_09_090003_backfill_location_id_for_open_reservations.php';

    private function rollbackAndRerun(callable $fixtures): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $fixtures();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    // -------------------------------------------------------------------------
    // rentals
    // -------------------------------------------------------------------------

    public function test_up_assigns_the_primary_location_to_a_blocking_rental(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $rental = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$rental) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $rental = Rental::factory()->create([
                'organization_id' => $org->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Confirmed,
            ]);
        });

        $this->assertSame($primary->id, $rental->fresh()->location_id);
    }

    #[DataProvider('nonBlockingRentalStatuses')]
    public function test_up_does_not_assign_a_location_to_a_non_blocking_rental(RentalStatus $status): void
    {
        $org = Organization::factory()->create();
        $rental = null;

        $this->rollbackAndRerun(function () use ($org, $status, &$rental) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $rental = Rental::factory()->create([
                'organization_id' => $org->id,
                'customer_id' => User::factory()->create()->id,
                'status' => $status,
            ]);
        });

        $this->assertNull($rental->fresh()->location_id);
    }

    /**
     * @return array<string, array{0: RentalStatus}>
     */
    public static function nonBlockingRentalStatuses(): array
    {
        return [
            'returned' => [RentalStatus::Returned],
            'cancelled' => [RentalStatus::Cancelled],
            'expired' => [RentalStatus::Expired],
        ];
    }

    // -------------------------------------------------------------------------
    // order_items — mirrors OrderItem::scopeBlockingAvailability() exactly
    // -------------------------------------------------------------------------

    public function test_up_assigns_the_primary_location_to_an_order_item_on_a_paid_order(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $item = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$item) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->paid()->create(['organization_id' => $org->id]);
            $item = OrderItem::factory()->create(['order_id' => $order->id]);
        });

        $this->assertSame($primary->id, $item->fresh()->location_id);
    }

    public function test_up_does_not_assign_a_location_to_an_order_item_on_a_cancelled_order(): void
    {
        $org = Organization::factory()->create();
        $item = null;

        $this->rollbackAndRerun(function () use ($org, &$item) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->cancelled()->create(['organization_id' => $org->id]);
            $item = OrderItem::factory()->create(['order_id' => $order->id]);
        });

        $this->assertNull($item->fresh()->location_id);
    }

    public function test_up_does_not_assign_a_location_to_an_order_item_on_a_genuinely_expired_pending_order(): void
    {
        $org = Organization::factory()->create();
        $item = null;

        $this->rollbackAndRerun(function () use ($org, &$item) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->expired()->create(['organization_id' => $org->id]);
            $item = OrderItem::factory()->create(['order_id' => $order->id]);
        });

        $this->assertNull($item->fresh()->location_id);
    }

    public function test_up_assigns_a_location_to_a_pending_payment_order_item_still_within_p24_grace(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $item = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$item) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $order = Order::factory()->pendingPayment()->create([
                'organization_id' => $org->id,
                'p24_token' => 'tok_123',
                'expires_at' => now()->subMinutes(10), // within the default 120-minute grace
            ]);
            $item = OrderItem::factory()->create(['order_id' => $order->id]);
        });

        $this->assertSame($primary->id, $item->fresh()->location_id);
    }

    // -------------------------------------------------------------------------
    // cart_items — active carts only, regardless of blocking status
    // -------------------------------------------------------------------------

    public function test_up_assigns_the_primary_location_to_an_item_in_an_active_cart(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $item = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$item) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $cart = Cart::factory()->active()->create(['organization_id' => $org->id]);
            $item = CartItem::factory()->create(['cart_id' => $cart->id]);
        });

        $this->assertSame($primary->id, $item->fresh()->location_id);
    }

    public function test_up_does_not_assign_a_location_to_an_item_in_a_converted_cart(): void
    {
        $org = Organization::factory()->create();
        $item = null;

        $this->rollbackAndRerun(function () use ($org, &$item) {
            Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $cart = Cart::factory()->converted()->create(['organization_id' => $org->id]);
            $item = CartItem::factory()->create(['cart_id' => $cart->id]);
        });

        $this->assertNull($item->fresh()->location_id);
    }

    // -------------------------------------------------------------------------
    // Multi-tenant isolation + defensive skip
    // -------------------------------------------------------------------------

    public function test_up_backfills_multiple_organizations_independently(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $primaryA = null;
        $primaryB = null;
        $rentalA = null;
        $rentalB = null;

        $this->rollbackAndRerun(function () use ($orgA, $orgB, &$primaryA, &$primaryB, &$rentalA, &$rentalB) {
            $primaryA = Location::factory()->for($orgA, 'organization')->create(['primary_slot' => 1]);
            $primaryB = Location::factory()->for($orgB, 'organization')->create(['primary_slot' => 1]);

            $rentalA = Rental::factory()->create([
                'organization_id' => $orgA->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Pending,
            ]);
            $rentalB = Rental::factory()->create([
                'organization_id' => $orgB->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Pending,
            ]);
        });

        $this->assertSame($primaryA->id, $rentalA->fresh()->location_id);
        $this->assertSame($primaryB->id, $rentalB->fresh()->location_id);
        $this->assertNotSame($rentalA->fresh()->location_id, $rentalB->fresh()->location_id);
    }

    /**
     * Defensive posture, same as 2026_08_28_090001's own precedent: an
     * organization with no primary location at migration time is skipped
     * rather than crashing the whole batch.
     */
    public function test_up_skips_an_organization_with_no_primary_location_without_failing_the_batch(): void
    {
        $orgWithoutPrimary = Organization::factory()->create();
        $orgWithPrimary = Organization::factory()->create();
        $primary = null;
        $rentalWithoutPrimary = null;
        $rentalWithPrimary = null;

        $this->rollbackAndRerun(function () use ($orgWithoutPrimary, $orgWithPrimary, &$primary, &$rentalWithoutPrimary, &$rentalWithPrimary) {
            $rentalWithoutPrimary = Rental::factory()->create([
                'organization_id' => $orgWithoutPrimary->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Pending,
            ]);

            $primary = Location::factory()->for($orgWithPrimary, 'organization')->create(['primary_slot' => 1]);
            $rentalWithPrimary = Rental::factory()->create([
                'organization_id' => $orgWithPrimary->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Pending,
            ]);
        });

        $this->assertNull($rentalWithoutPrimary->fresh()->location_id);
        $this->assertSame($primary->id, $rentalWithPrimary->fresh()->location_id);
    }

    /**
     * whereNull('location_id') guard in up() must not clobber a row that
     * already has a location — proves idempotency of a second run and, by
     * extension, that a manually-assigned value ahead of a re-run survives.
     */
    public function test_up_does_not_overwrite_an_already_assigned_location(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $other = null;
        $rental = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$other, &$rental) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $other = Location::factory()->for($org, 'organization')->create();
            $rental = Rental::factory()->create([
                'organization_id' => $org->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Pending,
                'location_id' => $other->id,
            ]);
        });

        $this->assertSame($other->id, $rental->fresh()->location_id, 'up() must not overwrite an already-assigned location.');

        // Re-running up() again (idempotency) must not change it either.
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame($other->id, $rental->fresh()->location_id);
    }

    /**
     * down() is a deliberate no-op — see the migration file's own docblock.
     */
    public function test_down_preserves_the_backfilled_location_id(): void
    {
        $org = Organization::factory()->create();
        $primary = null;
        $rental = null;

        $this->rollbackAndRerun(function () use ($org, &$primary, &$rental) {
            $primary = Location::factory()->for($org, 'organization')->create(['primary_slot' => 1]);
            $rental = Rental::factory()->create([
                'organization_id' => $org->id,
                'customer_id' => User::factory()->create()->id,
                'status' => RentalStatus::Pending,
            ]);
        });

        $this->assertSame($primary->id, $rental->fresh()->location_id);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame($primary->id, $rental->fresh()->location_id, 'down() must preserve the backfilled value, not erase it');

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }
}
