<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Http\Middleware\ResolveTenant;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faza 6 krok 6.2 (86cbahqgv, plan-wdrozenia.md) — the write side of the
 * "pytanie, nie błąd" acceptance criterion, exercised through the REAL
 * `location.select` route (same controller/route as Faza 5.2's switcher,
 * see LocationSelectionController's own docblock — this is deliberately
 * NOT a second endpoint). Mirrors LocationSwitcherTest.php's
 * `actingAsTenant()` bind-a-fake-ResolveTenant pattern, plus `actingAs()`
 * for a real authenticated customer — the confirmation step only engages
 * for someone who can actually have a cart (see the controller's own
 * docblock on that condition).
 */
class LocationChangeConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    private function setStock(Service $service, Location $location, int $quantity): void
    {
        ServiceLocationStock::where('service_id', $service->id)
            ->where('location_id', $location->id)
            ->update(['quantity' => $quantity]);
    }

    /**
     * Decision 2 (team lead's brief): an EMPTY cart has nothing to move —
     * the switch happens immediately, no question asked, identical to
     * Faza 5.2's original (pre-cart-aware) behaviour.
     */
    public function test_switching_location_with_an_empty_cart_skips_the_prompt(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);

        $response = $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $locationA->id])
            ->post(route('location.select'), ['location_id' => $locationB->id]);

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('selected_location_id', $locationB->id);
    }

    /**
     * Decision 2: a non-empty cart ALWAYS prompts on a real location change
     * — even when every item still fits at the new branch. The prompt is
     * about moving the pickup point, not just a stock question (see the
     * controller's own docblock).
     */
    public function test_switching_location_with_a_fully_available_non_empty_cart_still_shows_the_prompt(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        // Service created BEFORE the locations — ServiceLocationStockObserver
        // only auto-materializes anchor rows on Location::created() (see
        // SyncServiceLocationStock::forLocation()'s own docblock: forService()
        // is lazy, NOT triggered by creating a Service). Same ordering
        // CartServiceLocationChangeTest::setUp() uses, for the same reason.
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->setStock($service, $locationB, 5);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $locationA->id,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 200.00,
        ]);

        $response = $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $locationA->id])
            ->post(route('location.select'), ['location_id' => $locationB->id]);

        $response->assertOk();
        $response->assertViewIs('cart.location-change-confirm');
        $response->assertSee('Zmienić oddział odbioru?');
        $response->assertSee($locationB->name);
        // Not switched yet — this is a question, not a mutation.
        $this->assertSame($locationA->id, $cart->fresh()->location_id);
    }

    /**
     * Decision 1: an item that no longer fully fits is DISCLOSED, never a
     * hard rejection — the prompt renders with a reduced-quantity notice
     * instead of refusing the whole switch.
     */
    public function test_prompt_discloses_a_quantity_reduction_for_a_partially_available_item(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id, 'name' => 'Rusztowanie']);
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->setStock($service, $locationB, 1);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $locationA->id,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $response = $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $locationA->id])
            ->post(route('location.select'), ['location_id' => $locationB->id]);

        $response->assertOk();
        $response->assertSee('Rusztowanie');
        $response->assertSee('3 → 1');
    }

    /**
     * The confirm form posts BACK to the same route with `confirmed=1` —
     * this proves that second POST actually mutates the cart (trims the
     * quantity) and moves `carts.location_id`, closing the "sedno kroku"
     * rozjazd the team lead's brief describes.
     */
    public function test_confirming_the_change_trims_the_cart_and_moves_the_pickup_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->setStock($service, $locationB, 1);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $locationA->id,
        ]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $response = $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $locationA->id])
            ->post(route('location.select'), [
                'location_id' => $locationB->id,
                'confirmed' => '1',
            ]);

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('selected_location_id', $locationB->id);
        $response->assertSessionHas('location_change_report');

        $this->assertSame($locationB->id, $cart->fresh()->location_id);
        $this->assertSame(1, $item->fresh()->quantity);
    }

    /**
     * The full "przycięcie do zera" branch of decision 1 — an item with NO
     * availability at all is removed, not just left at quantity 0.
     */
    public function test_confirming_the_change_removes_an_item_with_zero_availability_at_the_new_location(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->setStock($service, $locationB, 0);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $locationA->id,
        ]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 200.00,
        ]);

        $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $locationA->id])
            ->post(route('location.select'), [
                'location_id' => $locationB->id,
                'confirmed' => '1',
            ]);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    /**
     * Decision 4, HTTP surface: stock consumed AFTER the question was shown
     * but BEFORE the customer confirms must still be caught — the
     * confirmation re-evaluates under lock (evaluateLocationChange's own
     * docblock), it does not trust whatever the earlier preview rendered.
     */
    public function test_confirmation_recalculates_availability_instead_of_trusting_the_earlier_prompt(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        [$locationA, $locationB] = Location::factory()->for($org, 'organization')->count(2)->create(['is_active' => true]);
        $this->setStock($service, $locationB, 2);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $locationA->id,
        ]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 200.00,
        ]);

        // At preview time, 2/2 would fit. Stock drops to 1 in the window
        // between the question and the confirm — simulating another
        // customer claiming a unit at the new branch in the meantime.
        $this->setStock($service, $locationB, 1);

        $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $locationA->id])
            ->post(route('location.select'), [
                'location_id' => $locationB->id,
                'confirmed' => '1',
            ]);

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_switching_to_the_carts_own_current_location_is_a_no_op_with_no_prompt(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $other = Location::factory()->for($org, 'organization')->create(['is_active' => true]);
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'location_id' => $location->id,
        ]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 100.00,
        ]);

        $response = $this->actingAsTenant($org)
            ->actingAs($user)
            ->withSession(['selected_location_id' => $other->id])
            ->post(route('location.select'), ['location_id' => $location->id]);

        $response->assertRedirect(route('home'));
        $this->assertSame(1, $item->fresh()->quantity);
    }
}
