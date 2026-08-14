<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderProtocolDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
    }

    /**
     * Bind a test double for ResolveTenant — same pattern as CustomerOrdersTest.
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
    // Happy path
    // -------------------------------------------------------------------------

    public function test_customer_can_download_handover_protocol_for_in_progress_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_customer_can_download_return_protocol_for_completed_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->completed()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.return', $order));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // Wrong state — document not eligible yet
    // -------------------------------------------------------------------------

    public function test_handover_protocol_returns_404_for_confirmed_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertNotFound();
    }

    public function test_return_protocol_returns_404_for_in_progress_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.return', $order));

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Cross-tenant / cross-customer — 404, not 403 (no existence leak)
    // -------------------------------------------------------------------------

    public function test_handover_protocol_returns_404_for_order_in_different_organization(): void
    {
        $user = User::factory()->create();
        $otherOrg = Organization::factory()->equipmentRental()->create();

        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertNotFound();
    }

    public function test_handover_protocol_returns_404_for_another_customers_order(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $order = Order::factory()->inProgress()->create([
            'user_id' => $userA->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($userB)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertNotFound();
    }

    public function test_return_protocol_returns_404_for_another_customers_order(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $order = Order::factory()->completed()->create([
            'user_id' => $userA->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($userB)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.return', $order));

        $response->assertNotFound();
    }

    public function test_handover_protocol_returns_404_without_tenant_context(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        // No actingAsTenant — TenantFeature::currentTenant() returns null.
        $response = $this->actingAs($user)
            ->get(route('orders.protocol.handover', $order));

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Guests
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login_for_handover_protocol(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_to_login_for_return_protocol(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->completed()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->get(route('orders.protocol.return', $order));

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Staff of the SAME tenant — this route is also where the Filament admin
    // actions point (OrderResource / EditOrder, see order-protocols.md), so
    // staff must be let through even though they are not the order's
    // customer. Tenant scoping still applies: a staff member of a DIFFERENT
    // tenant is rejected exactly like any other stranger.
    // -------------------------------------------------------------------------

    public function test_staff_can_download_handover_protocol_for_a_customers_order_in_their_own_tenant(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $customer = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('admin');

        $order = Order::factory()->inProgress()->create([
            'user_id' => $customer->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($staff)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_super_admin_can_download_return_protocol_for_a_customers_order_in_their_own_tenant(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $customer = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('super-admin');

        $order = Order::factory()->completed()->create([
            'user_id' => $customer->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($staff)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.return', $order));

        $response->assertOk();
    }

    public function test_staff_of_a_different_tenant_returns_404_for_handover_protocol(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $otherOrg = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create();
        $staffOfOtherOrg = User::factory()->create();
        $staffOfOtherOrg->assignRole('admin');

        $order = Order::factory()->inProgress()->create([
            'user_id' => $customer->id,
            'organization_id' => $this->org->id,
        ]);

        // actingAsTenant($otherOrg): the staff member's own tenant context is
        // $otherOrg, but the order belongs to $this->org — must still 404.
        $response = $this->actingAs($staffOfOtherOrg)
            ->actingAsTenant($otherOrg)
            ->get(route('orders.protocol.handover', $order));

        $response->assertNotFound();
    }
}
