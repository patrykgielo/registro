<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
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

    // -------------------------------------------------------------------------
    // Pickup-branch block (Faza 6 krok 6.5, ClickUp 86cbahqhb) — real HTTP
    // download through the actual route, not View::make() called directly
    // (code review, 2026-09-19: a status-200-only test through the service's
    // public method used to stand in for this and did not actually exercise
    // whether branchDetails() reaches Pdf::loadView() — hardcoding
    // 'branch' => null inside OrderProtocolPdfService::render() left it green).
    //
    // dompdf's PDF bytes are opaque to a response assertion (no pdftotext in
    // the app container — see order-protocols.md's own note on this), so the
    // proof captures the ACTUAL array handed to Pdf::loadView() via
    // View::composer(), which fires for real inside
    // barryvdh/laravel-dompdf's loadView() (it calls
    // $this->view->make($view, $data)->render(), the same Factory the
    // composer event hooks into) — not a second, parallel render.
    // -------------------------------------------------------------------------

    public function test_handover_protocol_http_download_passes_the_branch_snapshot_to_the_real_pdf_view(): void
    {
        $captured = &$this->captureComposedViewDataRef('orders.protocols.handover');

        $user = User::factory()->create();
        $location = Location::factory()->for($this->org)->create([
            'name' => 'Oddział Gdańsk',
            'street' => 'ul. Portowa 8',
            'building' => null,
            'postal_code' => '80-001',
            'city' => 'Gdańsk',
        ]);
        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertOk();
        $this->assertNotNull($captured['branch'] ?? null, 'branchDetails() never reached the real Pdf::loadView() call');
        $this->assertSame('Oddział Gdańsk', $captured['branch']['name']);
        $this->assertSame('ul. Portowa 8, 80-001 Gdańsk', $captured['branch']['address']);
    }

    public function test_return_protocol_http_download_passes_the_branch_snapshot_to_the_real_pdf_view(): void
    {
        $captured = &$this->captureComposedViewDataRef('orders.protocols.return');

        $user = User::factory()->create();
        $location = Location::factory()->for($this->org)->create([
            'name' => 'Oddział Poznań',
            'street' => 'ul. Zwrotna 3',
            'postal_code' => '61-000',
            'city' => 'Poznań',
        ]);
        $order = Order::factory()->completed()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.return', $order));

        $response->assertOk();
        $this->assertNotNull($captured['branch'] ?? null, 'branchDetails() never reached the real Pdf::loadView() call');
        $this->assertSame('Oddział Poznań', $captured['branch']['name']);
        $this->assertSame('ul. Zwrotna 3, 61-000 Poznań', $captured['branch']['address']);
    }

    /**
     * Fallback through the real route — an order without a snapshot must
     * reach the view with `branch === null` (no block rendered), exactly
     * today's behaviour, proven through the actual HTTP/render pipeline
     * rather than assumed from the Unit-level view test alone.
     */
    public function test_handover_protocol_http_download_passes_null_branch_without_a_snapshot(): void
    {
        $captured = &$this->captureComposedViewDataRef('orders.protocols.handover');

        $user = User::factory()->create();
        $order = Order::factory()->inProgress()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertOk();
        $this->assertArrayHasKey('branch', $captured);
        $this->assertNull($captured['branch']);
    }

    /**
     * Same controller/route serves the admin/staff download (see
     * OrderProtocolController's own class docblock — there is no separate
     * admin path) — confirms the branch snapshot reaches the real view for
     * that caller too, not just the customer's own request.
     */
    public function test_staff_handover_protocol_http_download_passes_the_branch_snapshot_to_the_real_pdf_view(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $captured = &$this->captureComposedViewDataRef('orders.protocols.handover');

        $customer = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('admin');
        $location = Location::factory()->for($this->org)->create(['name' => 'Oddział Łódź']);
        $order = Order::factory()->inProgress()->create([
            'user_id' => $customer->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $response = $this->actingAs($staff)
            ->actingAsTenant($this->org)
            ->get(route('orders.protocol.handover', $order));

        $response->assertOk();
        $this->assertSame('Oddział Łódź', $captured['branch']['name'] ?? null);
    }

    /**
     * Registers a View::composer() callback for the given protocol view
     * BEFORE the request runs, returning a reference the test method can
     * still read AFTER the HTTP call completes. The composer only fires
     * later, inside Pdf::loadView() -> Factory::make()->render() (deep
     * inside the request/response cycle triggered by $this->get(...)), so a
     * plain `use (&$var)` closure declared in the test body would capture a
     * variable that goes out of scope before the composer ever runs — the
     * `&` return + `=&` at each call site keeps the SAME array alive across
     * that boundary.
     *
     * @return array<string, mixed>
     */
    private function &captureComposedViewDataRef(string $view): array
    {
        $captured = [];
        View::composer($view, function ($composedView) use (&$captured) {
            $captured = $composedView->getData();
        });

        return $captured;
    }
}
