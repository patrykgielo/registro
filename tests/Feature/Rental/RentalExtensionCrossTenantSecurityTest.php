<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Http\Middleware\ResolveTenant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cross-tenant IDOR coverage for RentalExtensionController: an order that
 * belongs to Org B must be completely unreachable through Org A's tenant
 * context, even by a customer who is authenticated on Org A and knows Org B's
 * order/item IDs (e.g. sequential IDs, or a leaked URL). Mirrors the pattern
 * used by BookingCrossTenantSessionFallbackTest /
 * CartCheckoutOrderCrossTenantSessionFallbackTest.
 */
class RentalExtensionCrossTenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);
    }

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

    private function enableRentalExtension(Organization $org): void
    {
        Setting::create([
            'group' => 'rentals',
            'key' => 'rental_extension_enabled',
            'value' => [true],
            'organization_id' => $org->id,
        ]);
    }

    /**
     * @return array{0: Order, 1: OrderItem}
     */
    private function paidOrderForOrg(Organization $org, User $user): array
    {
        $this->app['request']->attributes->set('tenant', $org);

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 3,
            'price_per_day' => 100.00,
        ]);

        $order = Order::factory()->paid()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->addDays(4),
            'rental_days' => 8,
            'unit_price' => 100.00,
            'total_price' => 800.00,
        ]);

        return [$order, $item];
    }

    public function test_check_availability_returns_403_when_order_belongs_to_a_different_tenant(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $this->enableRentalExtension($orgA);
        $this->enableRentalExtension($orgB);

        $customerA = User::factory()->create();
        $customerA->organizations()->attach($orgA->id);

        // Order + item belong to Org B, owned by Org B's own customer.
        $customerB = User::factory()->create();
        [$orderB, $itemB] = $this->paidOrderForOrg($orgB, $customerB);

        $checkUrl = route('orders.extension.check', [$orderB, $itemB]);

        // Current tenant context resolves to Org A (e.g. customerA is browsing
        // Org A's subdomain) — Org B's order must be completely unreachable.
        $response = $this->actingAs($customerA)
            ->actingAsTenant($orgA)
            ->getJson($checkUrl.'?new_end_date='.Carbon::today()->addDays(7)->toDateString());

        $response->assertForbidden();
    }

    public function test_store_returns_403_when_order_belongs_to_a_different_tenant(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $this->enableRentalExtension($orgA);
        $this->enableRentalExtension($orgB);

        $customerA = User::factory()->create();
        $customerA->organizations()->attach($orgA->id);

        $customerB = User::factory()->create();
        [$orderB, $itemB] = $this->paidOrderForOrg($orgB, $customerB);

        $storeUrl = route('orders.extension.store', [$orderB, $itemB]);

        $response = $this->actingAs($customerA)
            ->actingAsTenant($orgA)
            ->post($storeUrl, [
                'new_end_date' => Carbon::today()->addDays(7)->toDateString(),
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('order_item_extension_requests', [
            'order_item_id' => $itemB->id,
        ]);
    }

    /**
     * Even Org B's OWN customer must not reach their own order through Org A's
     * tenant context — organization_id === $org->id is checked against the
     * CURRENT tenant, not just "does this user own the order".
     */
    public function test_check_availability_returns_403_for_org_b_customer_browsing_org_a_context(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $this->enableRentalExtension($orgA);
        $this->enableRentalExtension($orgB);

        $customerB = User::factory()->create();
        [$orderB, $itemB] = $this->paidOrderForOrg($orgB, $customerB);

        $checkUrl = route('orders.extension.check', [$orderB, $itemB]);

        $response = $this->actingAs($customerB)
            ->actingAsTenant($orgA)
            ->getJson($checkUrl.'?new_end_date='.Carbon::today()->addDays(7)->toDateString());

        $response->assertForbidden();
    }

    /**
     * Positive control — same request succeeds when the tenant context
     * actually matches the order's own organization, proving the 403s above
     * are due to the cross-tenant mismatch and not some unrelated breakage.
     */
    public function test_check_availability_works_normally_within_the_same_tenant(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->enableRentalExtension($org);

        $customer = User::factory()->create();
        [$order, $item] = $this->paidOrderForOrg($org, $customer);

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->getJson(route('orders.extension.check', [$order, $item]).'?new_end_date='.Carbon::today()->addDays(7)->toDateString());

        $response->assertOk();
    }
}
