<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the project.
     */
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
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

    // -------------------------------------------------------------------------
    // IDOR protection — orders.show
    // -------------------------------------------------------------------------

    public function test_user_cannot_view_another_users_order(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Order owned by User A, in the shared org
        $orderA = Order::factory()->pendingPayment()->create([
            'user_id' => $userA->id,
            'organization_id' => $this->org->id,
        ]);

        // User B tries to view User A's order
        $response = $this->actingAs($userB)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $orderA));

        $response->assertForbidden();
    }

    public function test_user_can_view_their_own_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertOk();
    }

    public function test_user_cannot_view_order_belonging_to_different_organization(): void
    {
        $user = User::factory()->create();
        $otherOrg = Organization::factory()->equipmentRental()->create();

        // Order in a different org, but same user
        $orderOtherOrg = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $otherOrg->id,
        ]);

        // Current tenant context is $this->org, but the order belongs to $otherOrg
        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $orderOtherOrg));

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // IDOR protection — guest access
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login_on_order_show(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_to_login_on_orders_index(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get(route('orders.index'));

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // orders.index — scoped to authenticated user
    // -------------------------------------------------------------------------

    public function test_user_only_sees_their_own_orders_in_index(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // 3 orders for User A, 2 for User B — all in the same org
        Order::factory()->pendingPayment()->count(3)->create([
            'user_id' => $userA->id,
            'organization_id' => $this->org->id,
        ]);

        Order::factory()->pendingPayment()->count(2)->create([
            'user_id' => $userB->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($userA)
            ->actingAsTenant($this->org)
            ->get(route('orders.index'));

        $response->assertOk();
        $response->assertViewHas('orders', function ($orders) use ($userA) {
            // All visible orders must belong to User A
            return $orders->every(fn ($order) => $order->user_id === $userA->id);
        });
    }

    public function test_user_does_not_see_orders_from_other_organizations(): void
    {
        $user = User::factory()->create();
        $otherOrg = Organization::factory()->equipmentRental()->create();

        // 1 order in current org, 1 order in other org (same user)
        Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);
        Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.index'));

        $response->assertOk();
        $response->assertViewHas('orders', function ($orders) {
            // Only 1 order should be visible (the one in $this->org)
            return $orders->count() === 1;
        });
    }

    public function test_index_returns_empty_collection_when_user_has_no_orders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.index'));

        $response->assertOk();
        $response->assertViewHas('orders', function ($orders) {
            return $orders->isEmpty();
        });
    }

    // -------------------------------------------------------------------------
    // Ordering — latest order appears first
    // -------------------------------------------------------------------------

    public function test_index_orders_are_sorted_newest_first(): void
    {
        $user = User::factory()->create();

        $older = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'created_at' => now()->subHour(),
        ]);

        $newer = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.index'));

        $response->assertOk();
        $response->assertViewHas('orders', function ($orders) use ($newer, $older) {
            return $orders->first()->id === $newer->id
                && $orders->last()->id === $older->id;
        });
    }

    // -------------------------------------------------------------------------
    // 404 when no tenant context
    // -------------------------------------------------------------------------

    public function test_order_show_returns_404_without_tenant_context(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        // No actingAsTenant — TenantFeature::currentTenant() returns null
        $response = $this->actingAs($user)
            ->get(route('orders.show', $order));

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // orders.cancel — happy path
    // -------------------------------------------------------------------------

    public function test_customer_can_cancel_pending_payment_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->post(route('orders.cancel', $order));

        // Powinno przekierować na stronę zamówienia
        $response->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
    }

    public function test_cancel_sets_cancelled_at_timestamp(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->post(route('orders.cancel', $order));

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
    }

    // -------------------------------------------------------------------------
    // orders.cancel — forbidden statuses
    // -------------------------------------------------------------------------

    public function test_customer_cannot_cancel_paid_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->post(route('orders.cancel', $order));

        $response->assertForbidden();
    }

    public function test_customer_cannot_cancel_confirmed_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->post(route('orders.cancel', $order));

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // orders.cancel — authorization
    // -------------------------------------------------------------------------

    public function test_customer_cannot_cancel_another_users_order(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Zamówienie należy do użytkownika A
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $userA->id,
            'organization_id' => $this->org->id,
        ]);

        // Użytkownik B próbuje anulować zamówienie A
        $response = $this->actingAs($userB)
            ->actingAsTenant($this->org)
            ->post(route('orders.cancel', $order));

        $response->assertForbidden();
    }

    public function test_guest_cannot_cancel_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        // Brak autentykacji — powinno przekierować na login
        $response = $this->actingAsTenant($this->org)
            ->post(route('orders.cancel', $order));

        $response->assertRedirect(route('login'));
    }
}
